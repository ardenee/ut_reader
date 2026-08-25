<?php
/**
 * Retries one terminal affected-dependency child from its durable checkpoint.
 *
 * Child recovery is identified from the actual job payload and coordinator
 * relationship, not from workflow-unit naming conventions. The child and its
 * coordinator are locked and transitioned together so an operator retry cannot
 * leave queued child work beneath a terminal partial coordinator.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;

final class PdoAffectedDependencyChildRetry
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{
     *   handled:bool,
     *   job_id:int,
     *   parent_job_id:int,
     *   affected:int,
     *   retry_blocked:int,
     *   parent_requeued:bool,
     *   resume_done:int,
     *   resume_total:int,
     *   status_before:string,
     *   reason:string
     * }
     */
    public function restart(string $queueName, int $childJobId, string $now): array
    {
        if ($childJobId < 1) {
            return $this->result(false, $childJobId, 0, 0, 0, false, 0, 0, '', 'A positive child job ID is required.');
        }

        $this->db->beginTransaction();
        try {
            $parentLookup = $this->db->prepare(
                'SELECT parent_job_id FROM ue_background_jobs WHERE queue_name=? AND id=? LIMIT 1'
            );
            $parentLookup->execute([$queueName, $childJobId]);
            $parentId = (int)($parentLookup->fetchColumn() ?: 0);
            if ($parentId < 1) {
                $this->db->rollBack();
                return $this->result(false, $childJobId, 0, 0, 0, false, 0, 0, '', 'The selected row is not a child recovery job.');
            }

            // Lock parent first, then child, so concurrent operator retries use a
            // stable lock order and cannot split the coordinator/child transition.
            $parentStatement = $this->db->prepare(
                'SELECT id,parent_job_id,job_type,status,display_status,progress_json '
                . 'FROM ue_background_jobs WHERE queue_name=? AND id=? LIMIT 1 FOR UPDATE'
            );
            $parentStatement->execute([$queueName, $parentId]);
            $parent = $parentStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($parent)) {
                $this->db->rollBack();
                return $this->result(false, $childJobId, $parentId, 0, 0, false, 0, 0, '', 'The dependency coordinator no longer exists.');
            }

            $childStatement = $this->db->prepare(
                'SELECT id,parent_job_id,job_type,status,payload_json,progress_json,last_error,result_json '
                . 'FROM ue_background_jobs WHERE queue_name=? AND id=? LIMIT 1 FOR UPDATE'
            );
            $childStatement->execute([$queueName, $childJobId]);
            $child = $childStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($child) || (int)($child['parent_job_id'] ?? 0) !== $parentId) {
                $this->db->rollBack();
                return $this->result(false, $childJobId, $parentId, 0, 0, false, 0, 0, '', 'The dependency child relationship changed before it could be retried.');
            }

            if ((string)($child['job_type'] ?? '') !== JobType::REBUILD_AFFECTED_DEPENDENCIES
                || !self::isAffectedRecoveryPayload((string)($child['payload_json'] ?? ''))) {
                $this->db->rollBack();
                return $this->result(false, $childJobId, $parentId, 0, 0, false, 0, 0, '', 'This row is not an affected-dependency recovery batch.');
            }

            if ((int)($parent['parent_job_id'] ?? 0) > 0
                || (string)($parent['job_type'] ?? '') !== JobType::REBUILD_AFFECTED_DEPENDENCIES) {
                $this->db->rollBack();
                return $this->result(false, $childJobId, $parentId, 0, 0, false, 0, 0, '', 'The parent row is not the expected affected-dependency coordinator.');
            }

            $childStatus = strtolower(trim((string)($child['status'] ?? '')));
            $parentStatus = strtolower(trim((string)($parent['status'] ?? '')));
            $parentDisplay = strtolower(trim((string)($parent['display_status'] ?? '')));
            [$resumeDone, $resumeTotal] = self::resumePosition((string)($child['progress_json'] ?? ''));

            if (!in_array($childStatus, ['cancelled', 'failed', 'dead_letter'], true)) {
                $this->db->rollBack();
                return $this->result(
                    true,
                    $childJobId,
                    $parentId,
                    0,
                    0,
                    false,
                    $resumeDone,
                    $resumeTotal,
                    $childStatus,
                    'This child is currently ' . ($childStatus !== '' ? $childStatus : 'not terminal') . ', so there is nothing to retry.'
                );
            }

            $parentRecoverable = in_array($parentStatus, ['queued', 'running'], true)
                || ($parentStatus === 'completed' && $parentDisplay === 'partial');
            if (!$parentRecoverable) {
                $this->db->rollBack();
                return $this->result(
                    true,
                    $childJobId,
                    $parentId,
                    0,
                    0,
                    false,
                    $resumeDone,
                    $resumeTotal,
                    $childStatus,
                    'The parent coordinator is ' . ($parentStatus !== '' ? $parentStatus : 'not recoverable')
                        . ($parentDisplay !== '' ? '/' . $parentDisplay : '') . ' and cannot accept child recovery work.'
                );
            }

            if (JobFailureRetryPolicy::isDeterministicFailureText(
                (string)$child['job_type'],
                self::persistedFailureText($child)
            )) {
                $this->db->rollBack();
                return $this->result(
                    true,
                    $childJobId,
                    $parentId,
                    0,
                    1,
                    false,
                    $resumeDone,
                    $resumeTotal,
                    $childStatus,
                    'This child failure is deterministic and cannot succeed by replaying the same work.'
                );
            }

            // display_status is a generated STORED column derived from status and
            // result_json. Never assign it directly; MySQL rejects writes to it.
            // Keep progress_json intact so a stopped batch resumes at progress.done.
            $retry = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="queued",attempts=0,available_at=?,'
                . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
                . 'last_error=NULL,result_json=NULL,progress_updated_at=?,'
                . 'cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,'
                . 'dead_lettered_at=NULL,completed_at=NULL,updated_at=? '
                . 'WHERE queue_name=? AND id=? AND parent_job_id=? '
                . 'AND status IN ("cancelled","failed","dead_letter")'
            );
            $retry->execute([$now, $now, $now, $queueName, $childJobId, $parentId]);
            if ($retry->rowCount() !== 1) {
                $this->db->rollBack();
                return $this->result(
                    true,
                    $childJobId,
                    $parentId,
                    0,
                    0,
                    false,
                    $resumeDone,
                    $resumeTotal,
                    $childStatus,
                    'The child changed state while the retry was being applied. Refresh and try again if it is still stopped.'
                );
            }

            $parentRequeued = false;
            if ($parentStatus === 'completed' && $parentDisplay === 'partial') {
                $parentProgress = self::coordinatorProgress((string)($parent['progress_json'] ?? ''));
                $parentUpdate = $this->db->prepare(
                    'UPDATE ue_background_jobs SET status="queued",attempts=0,available_at=?,'
                    . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
                    . 'last_error=NULL,result_json=NULL,progress_json=?,progress_updated_at=?,'
                    . 'cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,'
                    . 'dead_lettered_at=NULL,completed_at=NULL,updated_at=? '
                    . 'WHERE queue_name=? AND id=? AND parent_job_id IS NULL AND job_type=? '
                    . 'AND status="completed" AND display_status="partial"'
                );
                $parentUpdate->execute([
                    $now,
                    $parentProgress,
                    $now,
                    $now,
                    $queueName,
                    $parentId,
                    JobType::REBUILD_AFFECTED_DEPENDENCIES,
                ]);
                if ($parentUpdate->rowCount() !== 1) {
                    throw new \RuntimeException('Could not re-arm the partial dependency coordinator for child recovery.');
                }
                $parentRequeued = true;
            }

            $this->db->commit();
            return $this->result(
                true,
                $childJobId,
                $parentId,
                1,
                0,
                $parentRequeued,
                $resumeDone,
                $resumeTotal,
                $childStatus,
                $resumeTotal > 0
                    ? 'Queued to resume at ' . $resumeDone . '/' . $resumeTotal . '.'
                    : 'Queued to resume from its saved progress.'
            );
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private static function isAffectedRecoveryPayload(string $payloadJson): bool
    {
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return false;
        }

        if ((int)($payload['affected_file_id'] ?? 0) > 0) {
            return true;
        }

        $ids = $payload['affected_file_ids'] ?? null;
        if (!is_array($ids)) {
            return false;
        }
        foreach ($ids as $id) {
            if ((int)$id > 0) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:int,1:int} */
    private static function resumePosition(string $progressJson): array
    {
        $progress = json_decode($progressJson, true);
        if (!is_array($progress)) {
            return [0, 0];
        }
        return [
            max(0, (int)($progress['done'] ?? 0)),
            max(0, (int)($progress['total'] ?? 0)),
        ];
    }

    private static function coordinatorProgress(string $progressJson): string
    {
        $progress = json_decode($progressJson, true);
        if (!is_array($progress)) {
            $progress = [];
        }
        $progress['workflow_version'] = max(4, (int)($progress['workflow_version'] ?? 0));
        $progress['stage'] = 'affected_wait';
        $progress['percent'] = min(85, max(10, (int)($progress['percent'] ?? 85)));
        $progress['message'] = 'Waiting for retried affected dependency recovery work; completed batches are retained.';
        unset($progress['status'], $progress['failure_count']);

        $encoded = json_encode($progress, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded)
            ? $encoded
            : '{"workflow_version":4,"stage":"affected_wait","percent":85}';
    }

    /** @param array<string,mixed> $row */
    private static function persistedFailureText(array $row): string
    {
        $lastError = trim((string)($row['last_error'] ?? ''));
        if ($lastError !== '') {
            return $lastError;
        }
        foreach (['result_json', 'progress_json'] as $column) {
            $decoded = json_decode((string)($row[$column] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $message = trim((string)($decoded['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }
        return '';
    }

    /**
     * @return array{
     *   handled:bool,job_id:int,parent_job_id:int,affected:int,retry_blocked:int,
     *   parent_requeued:bool,resume_done:int,resume_total:int,status_before:string,reason:string
     * }
     */
    private function result(
        bool $handled,
        int $jobId,
        int $parentJobId,
        int $affected,
        int $retryBlocked,
        bool $parentRequeued,
        int $resumeDone,
        int $resumeTotal,
        string $statusBefore,
        string $reason
    ): array {
        return [
            'handled' => $handled,
            'job_id' => max(0, $jobId),
            'parent_job_id' => max(0, $parentJobId),
            'affected' => max(0, $affected),
            'retry_blocked' => max(0, $retryBlocked),
            'parent_requeued' => $parentRequeued,
            'resume_done' => max(0, $resumeDone),
            'resume_total' => max(0, $resumeTotal),
            'status_before' => $statusBefore,
            'reason' => trim($reason),
        ];
    }
}
