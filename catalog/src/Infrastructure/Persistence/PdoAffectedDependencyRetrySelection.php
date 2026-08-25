<?php
/**
 * Restarts the terminal recovery children of operator-selected partial
 * affected-dependency coordinators.
 *
 * The file-centric Background Jobs UI intentionally exposes only top-level source
 * rows as selectable units. Older affected-dependency workflows can finalize their
 * coordinator as "partial" while leaving failed/dead-letter/cancelled affected:*
 * children for later recovery. Retrying the coordinator itself would replay
 * successful work; retrying only those terminal children preserves completed work.
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

        $partialRoots = $this->partialRootIds($queueName, $selectedJobIds);
        if ($partialRoots === []) {
            return $this->result([], 0, 0, 0);
        }

        $problemRows = $this->problemChildRows($queueName, $partialRoots);
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

        $affected = 0;
        foreach (array_chunk($restartableIds, self::CHUNK_SIZE) as $chunk) {
            $idSql = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="queued",display_status=NULL,attempts=0,available_at=?,'
                . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
                . 'last_error=NULL,result_json=NULL,progress_json=NULL,progress_updated_at=NULL,'
                . 'cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,'
                . 'dead_lettered_at=NULL,completed_at=NULL,updated_at=? '
                . 'WHERE queue_name=? AND id IN (' . $idSql . ') '
                . 'AND status IN ("cancelled","failed","dead_letter")'
            );
            $statement->execute(array_merge([$now, $now, $queueName], $chunk));
            $affected += $statement->rowCount();
        }

        return $this->result($partialRoots, $requested, $affected, $retryBlocked);
    }

    /**
     * @param list<int> $selectedJobIds
     * @return list<int>
     */
    private function partialRootIds(string $queueName, array $selectedJobIds): array
    {
        $roots = [];
        foreach (array_chunk($selectedJobIds, self::CHUNK_SIZE) as $chunk) {
            $idSql = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT id FROM ue_background_jobs WHERE queue_name=? '
                . 'AND id IN (' . $idSql . ') '
                . 'AND parent_job_id IS NULL AND job_type=? '
                . 'AND status="completed" AND display_status="partial"'
            );
            $statement->execute(array_merge(
                [$queueName],
                $chunk,
                [JobType::REBUILD_AFFECTED_DEPENDENCIES]
            ));
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $roots[$id] = $id;
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
