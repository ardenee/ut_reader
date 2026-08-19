<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns leased-job completion, failure, heartbeat and cancellation transitions.
 * Why: WorkerJobQueue and PdoJobQueue previously implemented overlapping lifecycle SQL with different lease limits and completion metadata.
 * Role: Authoritative Infrastructure persistence collaborator for every leased job transition.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

final class PdoJobLeaseStore
{
    private const MAX_LEASE_SECONDS = 6 * 3600;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $result @return 'completed'|'cancelled' */
    public function complete(ClaimedJob $job, array $result = []): string
    {
        $now = PdoJobQueueSupport::now()->format('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $row = $this->lockLeaseRow($job, 'cancel_requested_at,cancel_reason,leased_at,progress_json');
            if (!empty($row['cancel_requested_at'])) {
                $this->transitionCancelled($job, (string)($row['cancel_reason'] ?? ''), $now);
                $this->db->commit();
                return 'cancelled';
            }

            $result['job_id'] = $job->id;
            $expectedOriginalName = trim((string)($job->payload['original_name'] ?? ''));
            if ($expectedOriginalName !== '') {
                $result['job_original_name'] = $expectedOriginalName;
            }
            $leasedAt = trim((string)($row['leased_at'] ?? ''));
            if ($leasedAt !== '') {
                $result['file_started_at'] = $leasedAt;
            }
            $result['file_completed_at'] = $now;

            $progress = [];
            if (!empty($row['progress_json'])) {
                $decoded = json_decode((string)$row['progress_json'], true);
                if (is_array($decoded)) {
                    $progress = $decoded;
                }
            }
            $progress['stage'] = 'completed';
            $progress['done'] = 100;
            $progress['total'] = 100;
            $progress['percent'] = 100;
            $progress['job_id'] = $job->id;
            if ($leasedAt !== '') {
                $progress['file_started_at'] = $progress['file_started_at'] ?? $leasedAt;
            }
            $progress['file_completed_at'] = $now;
            $resultMessage = trim((string)($result['message'] ?? ''));
            if ($resultMessage !== '') {
                $progress['message'] = $resultMessage;
            } elseif (trim((string)($progress['message'] ?? '')) === '') {
                $progress['message'] = 'Job #' . $job->id . ' completed.';
            }

            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="completed",result_json=?,dedupe_key=NULL,last_error=NULL,'
                . 'worker_id=NULL,lease_token=NULL,lease_expires_at=NULL,'
                . 'progress_json=?,progress_updated_at=?,completed_at=?,updated_at=? '
                . 'WHERE id=? AND status="running" AND lease_token=?'
            );
            $statement->execute([
                PdoJobQueueSupport::encodeJson($result),
                PdoJobQueueSupport::encodeJson($progress),
                $now,
                $now,
                $now,
                $job->id,
                $job->leaseToken,
            ]);
            $this->assertLeaseUpdate($statement->rowCount(), $job);
            $this->db->commit();
            return 'completed';
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** @return 'retry_queued'|'dead_letter'|'cancelled' */
    public function fail(ClaimedJob $job, \Throwable $exception, int $retryDelaySeconds): string
    {
        $now = PdoJobQueueSupport::now();
        $timestamp = $now->format('Y-m-d H:i:s');
        $error = PdoJobQueueSupport::trimError($exception);

        $this->db->beginTransaction();
        try {
            $row = $this->lockLeaseRow($job, 'cancel_requested_at,cancel_reason');
            if (!empty($row['cancel_requested_at'])) {
                $this->transitionCancelled($job, (string)($row['cancel_reason'] ?? $error), $timestamp);
                $this->db->commit();
                return 'cancelled';
            }

            if ($job->attempt < $job->maxAttempts && $retryDelaySeconds > 0) {
                $availableAt = $now->modify('+' . min(3600, $retryDelaySeconds) . ' seconds');
                $statement = $this->db->prepare(
                    'UPDATE ue_background_jobs SET status="queued",available_at=?,worker_id=NULL,lease_token=NULL,leased_at=NULL,'
                    . 'lease_expires_at=NULL,last_heartbeat_at=NULL,last_error=?,updated_at=? '
                    . 'WHERE id=? AND status="running" AND lease_token=?'
                );
                $statement->execute([
                    $availableAt->format('Y-m-d H:i:s'),
                    $error,
                    $timestamp,
                    $job->id,
                    $job->leaseToken,
                ]);
                $this->assertLeaseUpdate($statement->rowCount(), $job);
                $this->db->commit();
                return 'retry_queued';
            }

            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="dead_letter",dedupe_key=NULL,worker_id=NULL,lease_token=NULL,leased_at=NULL,'
                . 'lease_expires_at=NULL,last_heartbeat_at=NULL,last_error=?,dead_lettered_at=?,completed_at=?,updated_at=? '
                . 'WHERE id=? AND status="running" AND lease_token=?'
            );
            $statement->execute([
                $error,
                $timestamp,
                $timestamp,
                $timestamp,
                $job->id,
                $job->leaseToken,
            ]);
            $this->assertLeaseUpdate($statement->rowCount(), $job);
            $this->db->commit();
            return 'dead_letter';
        } catch (\Throwable $failure) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $failure;
        }
    }

    /** @param array<string,mixed> $progress */
    public function defer(ClaimedJob $job, int $delaySeconds, array $progress = []): void
    {
        $delaySeconds = max(1, min(3600, $delaySeconds));
        $now = PdoJobQueueSupport::now();
        $timestamp = $now->format('Y-m-d H:i:s');
        $availableAt = $now->modify('+' . $delaySeconds . ' seconds')->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $row = $this->lockLeaseRow($job, 'cancel_requested_at,cancel_reason,progress_json');
            if (!empty($row['cancel_requested_at'])) {
                $this->transitionCancelled($job, (string)($row['cancel_reason'] ?? ''), $timestamp);
                $this->db->commit();
                return;
            }
            if ($progress === [] && !empty($row['progress_json'])) {
                $decoded = json_decode((string)$row['progress_json'], true);
                if (is_array($decoded)) {
                    $progress = $decoded;
                }
            }
            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="queued",attempts=GREATEST(attempts-1,0),available_at=?,'
                . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,last_error=NULL,'
                . 'progress_json=?,progress_updated_at=?,updated_at=? '
                . 'WHERE id=? AND status="running" AND lease_token=?'
            );
            $statement->execute([
                $availableAt,
                $progress !== [] ? PdoJobQueueSupport::encodeJson($progress) : null,
                $progress !== [] ? $timestamp : null,
                $timestamp,
                $job->id,
                $job->leaseToken,
            ]);
            $this->assertLeaseUpdate($statement->rowCount(), $job);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $progress @return 'active'|'cancel_requested'|'lost' */
    public function heartbeat(ClaimedJob $job, int $leaseSeconds, array $progress = []): string
    {
        $leaseSeconds = max(15, min($leaseSeconds, self::MAX_LEASE_SECONDS));
        $now = PdoJobQueueSupport::now();
        $timestamp = $now->format('Y-m-d H:i:s');
        $expires = $now->modify('+' . $leaseSeconds . ' seconds')->format('Y-m-d H:i:s');

        if ($progress === []) {
            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET lease_expires_at=?,last_heartbeat_at=?,updated_at=? '
                . 'WHERE id=? AND status="running" AND lease_token=?'
            );
            $statement->execute([$expires, $timestamp, $timestamp, $job->id, $job->leaseToken]);
        } else {
            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET lease_expires_at=?,last_heartbeat_at=?,progress_json=?,progress_updated_at=?,updated_at=? '
                . 'WHERE id=? AND status="running" AND lease_token=?'
            );
            $statement->execute([
                $expires,
                $timestamp,
                PdoJobQueueSupport::encodeJson($progress),
                $timestamp,
                $timestamp,
                $job->id,
                $job->leaseToken,
            ]);
        }

        // MySQL PDO rowCount() reports changed rows for UPDATE by default, not
        // matched rows. A legitimate heartbeat can therefore return zero when a
        // restarted job immediately persists the same checkpoint within the same
        // second. Lease ownership is authoritative; never infer lease loss from a
        // no-op UPDATE.
        $check = $this->db->prepare(
            'SELECT cancel_requested_at FROM ue_background_jobs WHERE id=? AND status="running" AND lease_token=?'
        );
        $check->execute([$job->id, $job->leaseToken]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 'lost';
        }
        return empty($row['cancel_requested_at']) ? 'active' : 'cancel_requested';
    }

    /** @return 'cancelled'|'cancel_requested'|'not_found'|'completed'|'failed'|'dead_letter' */
    public function requestCancellation(int $jobId, ?int $requestedBy = null, string $reason = ''): string
    {
        if ($jobId < 1) {
            return 'not_found';
        }
        $reason = PdoJobQueueSupport::trimReason($reason);
        $now = PdoJobQueueSupport::now()->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare('SELECT status FROM ue_background_jobs WHERE id=? FOR UPDATE');
            $select->execute([$jobId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->db->commit();
                return 'not_found';
            }

            $status = (string)$row['status'];
            if ($status === 'queued') {
                $statement = $this->db->prepare(
                    'UPDATE ue_background_jobs SET status="cancelled",dedupe_key=NULL,cancel_requested_at=?,'
                    . 'cancel_requested_by=?,cancel_reason=?,completed_at=?,updated_at=? WHERE id=? AND status="queued"'
                );
                $statement->execute([$now, $requestedBy, $reason, $now, $now, $jobId]);
                $this->db->commit();
                return 'cancelled';
            }
            if ($status === 'running') {
                $statement = $this->db->prepare(
                    'UPDATE ue_background_jobs SET cancel_requested_at=COALESCE(cancel_requested_at,?),'
                    . 'cancel_requested_by=COALESCE(cancel_requested_by,?),'
                    . 'cancel_reason=CASE WHEN cancel_reason IS NULL OR cancel_reason="" THEN ? ELSE cancel_reason END,updated_at=? '
                    . 'WHERE id=? AND status="running"'
                );
                $statement->execute([$now, $requestedBy, $reason, $now, $jobId]);
                $this->db->commit();
                return 'cancel_requested';
            }

            $this->db->commit();
            return in_array($status, ['completed', 'failed', 'dead_letter'], true) ? $status : 'cancelled';
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function cancelClaimed(ClaimedJob $job, string $reason = ''): void
    {
        $now = PdoJobQueueSupport::now()->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="cancelled",dedupe_key=NULL,worker_id=NULL,lease_token=NULL,'
            . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,cancel_requested_at=COALESCE(cancel_requested_at,?),'
            . 'cancel_reason=CASE WHEN cancel_reason IS NULL OR cancel_reason="" THEN ? ELSE cancel_reason END,completed_at=?,updated_at=? '
            . 'WHERE id=? AND status="running" AND lease_token=?'
        );
        $statement->execute([
            $now,
            PdoJobQueueSupport::trimReason($reason),
            $now,
            $now,
            $job->id,
            $job->leaseToken,
        ]);
        $this->assertLeaseUpdate($statement->rowCount(), $job);
    }

    /** @return array<string,mixed> */
    private function lockLeaseRow(ClaimedJob $job, string $columns): array
    {
        if (preg_match('/^[a-z_,]+$/', $columns) !== 1) {
            throw new \LogicException('Invalid leased-job column selection.');
        }
        $statement = $this->db->prepare(
            'SELECT ' . $columns . ' FROM ue_background_jobs WHERE id=? AND status="running" AND lease_token=? FOR UPDATE'
        );
        $statement->execute([$job->id, $job->leaseToken]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Job lease no longer belongs to this worker: ' . $job->id);
        }
        return $row;
    }

    private function transitionCancelled(ClaimedJob $job, string $reason, string $timestamp): void
    {
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="cancelled",dedupe_key=NULL,worker_id=NULL,lease_token=NULL,'
            . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,cancel_requested_at=COALESCE(cancel_requested_at,?),'
            . 'cancel_reason=CASE WHEN cancel_reason IS NULL OR cancel_reason="" THEN ? ELSE cancel_reason END,completed_at=?,updated_at=? '
            . 'WHERE id=? AND status="running" AND lease_token=?'
        );
        $statement->execute([
            $timestamp,
            PdoJobQueueSupport::trimReason($reason),
            $timestamp,
            $timestamp,
            $job->id,
            $job->leaseToken,
        ]);
        $this->assertLeaseUpdate($statement->rowCount(), $job);
    }

    private function assertLeaseUpdate(int $affectedRows, ClaimedJob $job): void
    {
        if ($affectedRows !== 1) {
            throw new \RuntimeException('Job lease no longer belongs to this worker: ' . $job->id);
        }
    }
}
