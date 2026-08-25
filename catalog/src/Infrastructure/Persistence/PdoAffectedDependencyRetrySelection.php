<?php
/**
 * Restarts the terminal recovery children of operator-selected partial
 * affected-dependency coordinators.
 *
 * The file-centric Background Jobs UI intentionally exposes only top-level source
 * rows as selectable units. Affected-dependency workflows can finalize their
 * coordinator as "partial" while leaving failed/dead-letter/cancelled affected:*
 * children for later recovery. Retrying must preserve completed work and each
 * recovery child's checkpoint, then return only the coordinator to its lightweight
 * wait/finalize stage so the Issue clears automatically after recovery succeeds.
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
}
