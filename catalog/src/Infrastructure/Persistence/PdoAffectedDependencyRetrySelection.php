<?php
/**
 * Restarts terminal recovery work for partial affected-dependency coordinators.
 *
 * The file-centric Background Jobs UI exposes top-level sources as selectable
 * units, while expanded terminal child rows may also be retried directly. Retry
 * preserves completed work and each recovery child's checkpoint, then returns the
 * coordinator only to its lightweight wait/finalize stage when required.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;

final class PdoAffectedDependencyRetrySelection
{
    private const CHUNK_SIZE = 500;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param list<int> $selectedJobIds
     * @return array{
     *   handled_root_ids:list<int>,requested:int,affected:int,retry_blocked:int,skipped:int
     * }
     */
    public function restartPartialRoots(string $queueName, array $selectedJobIds, string $now): array
    {
        $selectedJobIds = array_values(array_unique(array_filter(
            array_map('intval', $selectedJobIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($selectedJobIds === []) {
            return $this->result([], 0, 0, 0);
        }

        $partialRoots = $this->partialRootRows($queueName, $selectedJobIds);
        if ($partialRoots === []) {
            return $this->result([], 0, 0, 0);
        }

        $rootIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            $partialRoots
        );
        $problemRows = $this->problemChildRows($queueName, $rootIds);
        $requested = count($problemRows);
        $restartableIds = [];
        $retryBlocked = 0;

        foreach ($problemRows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            if (JobFailureRetryPolicy::isDeterministicFailureText(
                (string)($row['job_type'] ?? ''),
                self::persistedFailureText($row)
            )) {
                $retryBlocked++;
                continue;
            }
            $restartableIds[] = $id;
        }

        // Recovery children are reset first. Their progress_json is deliberately
        // retained so a stopped bounded batch such as 46/250 resumes at 46 rather
        // than replaying successful files from the start of that batch.
        $affected = 0;
        foreach (array_chunk($restartableIds, self::CHUNK_SIZE) as $chunk) {
            $idSql = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="queued",display_status=NULL,attempts=0,available_at=?,'
                . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
                . 'last_error=NULL,result_json=NULL,'
                . 'cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,'
                . 'dead_lettered_at=NULL,completed_at=NULL,updated_at=? '
                . 'WHERE queue_name=? AND id IN (' . $idSql . ') '
                . 'AND status IN ("cancelled","failed","dead_letter")'
            );
            $statement->execute(array_merge([$now, $now, $queueName], $chunk));
            $affected += $statement->rowCount();
        }

        if ($affected > 0) {
            $this->requeueCoordinators($queueName, $partialRoots, $now);
        }

        return $this->result($rootIds, $requested, $affected, $retryBlocked);
    }

    /**
     * Retry exactly one expanded affected-dependency child row.
     *
     * This is intentionally narrower than the parent bulk action: a stopped or
     * failed batch resumes from its own progress_json and successful siblings are
     * untouched. If the root coordinator already finalized partial, it is re-armed
     * only at affected_wait so it can observe the recovered child and finalize.
     *
     * @return array{
     *   handled:bool,job_id:int,parent_job_id:int,requested:int,affected:int,
     *   retry_blocked:int,skipped:int,parent_requeued:bool
     * }
     */
    public function restartChild(string $queueName, int $childJobId, string $now): array
    {
        if ($childJobId < 1) {
            return $this->childResult(false, $childJobId, 0, 0, 0, 0, false);
        }

        $statement = $this->db->prepare(
            'SELECT j.id,j.parent_job_id,j.job_type,j.status,j.last_error,j.result_json,j.progress_json,'
            . 'p.id parent_id,p.parent_job_id parent_parent_job_id,p.job_type parent_job_type,'
            . 'p.status parent_status,p.display_status parent_display_status,p.progress_json parent_progress_json '
            . 'FROM ue_background_jobs j '
            . 'JOIN ue_background_jobs p ON p.id=j.parent_job_id AND p.queue_name=j.queue_name '
            . 'WHERE j.queue_name=? AND j.id=? AND j.parent_job_id IS NOT NULL '
            . 'AND j.workflow_unit_key LIKE "affected:%" AND j.job_type=? '
            . 'AND p.parent_job_id IS NULL AND p.job_type=? LIMIT 1'
        );
        $statement->execute([
            $queueName,
            $childJobId,
            JobType::REBUILD_AFFECTED_DEPENDENCIES,
            JobType::REBUILD_AFFECTED_DEPENDENCIES,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $this->childResult(false, $childJobId, 0, 0, 0, 0, false);
        }

        $parentId = (int)($row['parent_id'] ?? 0);
        $childStatus = strtolower(trim((string)($row['status'] ?? '')));
        $parentStatus = strtolower(trim((string)($row['parent_status'] ?? '')));
        $parentDisplay = strtolower(trim((string)($row['parent_display_status'] ?? '')));
        $parentRecoverable = in_array($parentStatus, ['queued', 'running'], true)
            || ($parentStatus === 'completed' && $parentDisplay === 'partial');

        if (!$parentRecoverable || !in_array($childStatus, ['cancelled', 'failed', 'dead_letter'], true)) {
            return $this->childResult(true, $childJobId, $parentId, 1, 0, 0, false);
        }

        if (JobFailureRetryPolicy::isDeterministicFailureText(
            (string)($row['job_type'] ?? ''),
            self::persistedFailureText($row)
        )) {
            return $this->childResult(true, $childJobId, $parentId, 1, 0, 1, false);
        }

        $retry = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="queued",display_status=NULL,attempts=0,available_at=?,'
            . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
            . 'last_error=NULL,result_json=NULL,'
            . 'cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,'
            . 'dead_lettered_at=NULL,completed_at=NULL,updated_at=? '
            . 'WHERE queue_name=? AND id=? AND status IN ("cancelled","failed","dead_letter")'
        );
        $retry->execute([$now, $now, $queueName, $childJobId]);
        $affected = $retry->rowCount();
        $parentRequeued = false;

        if ($affected === 1 && $parentStatus === 'completed' && $parentDisplay === 'partial') {
            $this->requeueCoordinators($queueName, [[
                'id' => $parentId,
                'progress_json' => (string)($row['parent_progress_json'] ?? ''),
            ]], $now);
            $parentRequeued = true;
        }

        return $this->childResult(true, $childJobId, $parentId, 1, $affected, 0, $parentRequeued);
    }

    /**
     * @param list<int> $selectedJobIds
     * @return list<array{id:int,progress_json:string}>
     */
    private function partialRootRows(string $queueName, array $selectedJobIds): array
    {
        $roots = [];
        foreach (array_chunk($selectedJobIds, self::CHUNK_SIZE) as $chunk) {
            $idSql = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT id,progress_json FROM ue_background_jobs WHERE queue_name=? '
                . 'AND id IN (' . $idSql . ') '
                . 'AND parent_job_id IS NULL AND job_type=? '
                . 'AND status="completed" AND display_status="partial"'
            );
            $statement->execute(array_merge(
                [$queueName],
                $chunk,
                [JobType::REBUILD_AFFECTED_DEPENDENCIES]
            ));
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0) {
                    $roots[$id] = [
                        'id' => $id,
                        'progress_json' => (string)($row['progress_json'] ?? ''),
                    ];
                }
            }
        }
        return array_values($roots);
    }

    /**
     * @param list<int> $rootIds
     * @return list<array<string,mixed>>
     */
    private function problemChildRows(string $queueName, array $rootIds): array
    {
        $rows = [];
        foreach (array_chunk($rootIds, self::CHUNK_SIZE) as $chunk) {
            $rootSql = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT id,parent_job_id,job_type,last_error,result_json,progress_json '
                . 'FROM ue_background_jobs WHERE queue_name=? '
                . 'AND parent_job_id IN (' . $rootSql . ') '
                . 'AND workflow_unit_key LIKE "affected:%" '
                . 'AND status IN ("cancelled","failed","dead_letter") ORDER BY id'
            );
            $statement->execute(array_merge([$queueName], $chunk));
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0) {
                    $rows[$id] = $row;
                }
            }
        }
        return array_values($rows);
    }

    /**
     * @param list<array{id:int,progress_json:string}> $roots
     */
    private function requeueCoordinators(string $queueName, array $roots, string $now): void
    {
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="queued",display_status=NULL,attempts=0,available_at=?,'
            . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
            . 'last_error=NULL,result_json=NULL,progress_json=?,progress_updated_at=?,'
            . 'cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,'
            . 'dead_lettered_at=NULL,completed_at=NULL,updated_at=? '
            . 'WHERE queue_name=? AND id=? AND parent_job_id IS NULL AND job_type=? '
            . 'AND status="completed" AND display_status="partial"'
        );

        foreach ($roots as $root) {
            $progress = json_decode((string)($root['progress_json'] ?? ''), true);
            if (!is_array($progress)) {
                $progress = [];
            }
            $progress['workflow_version'] = max(4, (int)($progress['workflow_version'] ?? 0));
            $progress['stage'] = 'affected_wait';
            $progress['percent'] = min(85, max(10, (int)($progress['percent'] ?? 85)));
            $progress['message'] = 'Retrying only failed/cancelled affected dependency recovery work; completed batches are retained.';
            unset($progress['status'], $progress['failure_count']);
            $encoded = json_encode($progress, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded)) {
                $encoded = '{"workflow_version":4,"stage":"affected_wait","percent":85}';
            }

            $statement->execute([
                $now,
                $encoded,
                $now,
                $now,
                $queueName,
                (int)$root['id'],
                JobType::REBUILD_AFFECTED_DEPENDENCIES,
            ]);
        }
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
     * @param list<int> $handledRootIds
     * @return array{
     *   handled_root_ids:list<int>,requested:int,affected:int,retry_blocked:int,skipped:int
     * }
     */
    private function result(
        array $handledRootIds,
        int $requested,
        int $affected,
        int $retryBlocked
    ): array {
        return [
            'handled_root_ids' => array_values(array_map('intval', $handledRootIds)),
            'requested' => max(0, $requested),
            'affected' => max(0, $affected),
            'retry_blocked' => max(0, $retryBlocked),
            'skipped' => max(0, $requested - $affected),
        ];
    }

    /**
     * @return array{
     *   handled:bool,job_id:int,parent_job_id:int,requested:int,affected:int,
     *   retry_blocked:int,skipped:int,parent_requeued:bool
     * }
     */
    private function childResult(
        bool $handled,
        int $jobId,
        int $parentJobId,
        int $requested,
        int $affected,
        int $retryBlocked,
        bool $parentRequeued
    ): array {
        return [
            'handled' => $handled,
            'job_id' => max(0, $jobId),
            'parent_job_id' => max(0, $parentJobId),
            'requested' => max(0, $requested),
            'affected' => max(0, $affected),
            'retry_blocked' => max(0, $retryBlocked),
            'skipped' => max(0, $requested - $affected),
            'parent_requeued' => $parentRequeued,
        ];
    }
}
