<?php
/**
 * Restarts terminal recovery work for partial affected-dependency coordinators.
 *
 * The file-centric Background Jobs UI selects top-level coordinator rows. This
 * adapter expands those selections to their terminal child batches and delegates
 * every actual state transition to PdoAffectedDependencyChildRetry so there is one
 * authoritative recovery implementation.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
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

        $rootIds = $this->partialRootIds($queueName, $selectedJobIds);
        if ($rootIds === []) {
            return $this->result([], 0, 0, 0);
        }

        $childIds = $this->problemChildIds($queueName, $rootIds);
        $requested = count($childIds);
        $affected = 0;
        $retryBlocked = 0;

        $retry = new PdoAffectedDependencyChildRetry($this->db);
        foreach ($childIds as $childId) {
            $outcome = $retry->restart($queueName, $childId, $now);
            $affected += max(0, (int)($outcome['affected'] ?? 0));
            $retryBlocked += max(0, (int)($outcome['retry_blocked'] ?? 0));
        }

        return $this->result($rootIds, $requested, $affected, $retryBlocked);
    }

    /**
     * Compatibility entry point for callers that already target one expanded
     * child row. The semantic child retry service owns the actual transition.
     *
     * @return array{
     *   handled:bool,job_id:int,parent_job_id:int,requested:int,affected:int,
     *   retry_blocked:int,skipped:int,parent_requeued:bool
     * }
     */
    public function restartChild(string $queueName, int $childJobId, string $now): array
    {
        $outcome = (new PdoAffectedDependencyChildRetry($this->db))
            ->restart($queueName, $childJobId, $now);

        $requested = !empty($outcome['handled']) ? 1 : 0;
        $affected = max(0, (int)($outcome['affected'] ?? 0));
        $retryBlocked = max(0, (int)($outcome['retry_blocked'] ?? 0));

        return [
            'handled' => !empty($outcome['handled']),
            'job_id' => max(0, (int)($outcome['job_id'] ?? $childJobId)),
            'parent_job_id' => max(0, (int)($outcome['parent_job_id'] ?? 0)),
            'requested' => $requested,
            'affected' => $affected,
            'retry_blocked' => $retryBlocked,
            'skipped' => max(0, $requested - $affected),
            'parent_requeued' => !empty($outcome['parent_requeued']),
        ];
    }

    /** @param list<int> $selectedJobIds @return list<int> */
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
        ksort($roots, SORT_NUMERIC);
        return array_values($roots);
    }

    /** @param list<int> $rootIds @return list<int> */
    private function problemChildIds(string $queueName, array $rootIds): array
    {
        $children = [];
        foreach (array_chunk($rootIds, self::CHUNK_SIZE) as $chunk) {
            $rootSql = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT id FROM ue_background_jobs WHERE queue_name=? '
                . 'AND parent_job_id IN (' . $rootSql . ') '
                . 'AND job_type=? AND status IN ("cancelled","failed","dead_letter") ORDER BY id'
            );
            $statement->execute(array_merge(
                [$queueName],
                $chunk,
                [JobType::REBUILD_AFFECTED_DEPENDENCIES]
            ));
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $children[$id] = $id;
                }
            }
        }
        ksort($children, SORT_NUMERIC);
        return array_values($children);
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
