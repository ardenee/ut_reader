<?php
/**
 * Expands operator-selected partial affected-dependency coordinators into the
 * terminal child recovery jobs that actually need to be retried.
 *
 * The file-centric Background Jobs UI intentionally exposes only top-level source
 * rows as selectable units. Older affected-dependency workflows can finalize their
 * coordinator as "partial" while leaving failed/cancelled affected:* children for
 * later recovery. Retrying the coordinator itself would replay successful work;
 * retrying only those terminal children preserves completed dependency work.
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
     * @param list<int> $jobIds
     * @return list<int>
     */
    public function expand(string $queueName, array $jobIds): array
    {
        $jobIds = array_values(array_unique(array_filter(
            array_map('intval', $jobIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($jobIds === []) {
            return [];
        }

        $partialRoots = [];
        foreach (array_chunk($jobIds, self::CHUNK_SIZE) as $chunk) {
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
                    $partialRoots[$id] = true;
                }
            }
        }

        if ($partialRoots === []) {
            return $jobIds;
        }

        $expanded = [];
        foreach ($jobIds as $id) {
            if (!isset($partialRoots[$id])) {
                $expanded[$id] = $id;
            }
        }

        $problemChildrenByRoot = [];
        $rootIds = array_map('intval', array_keys($partialRoots));
        foreach (array_chunk($rootIds, self::CHUNK_SIZE) as $chunk) {
            $rootSql = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT id,parent_job_id FROM ue_background_jobs WHERE queue_name=? '
                . 'AND parent_job_id IN (' . $rootSql . ') '
                . 'AND workflow_unit_key LIKE "affected:%" AND ('
                . 'status IN ("cancelled","failed","dead_letter") '
                . 'OR (status="completed" AND display_status IN '
                . '("failed","rejected","unverified","partial","error"))'
                . ') ORDER BY id'
            );
            $statement->execute(array_merge([$queueName], $chunk));
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (int)($row['id'] ?? 0);
                $parentId = (int)($row['parent_job_id'] ?? 0);
                if ($id < 1 || $parentId < 1) {
                    continue;
                }
                $problemChildrenByRoot[$parentId][$id] = $id;
            }
        }

        foreach ($rootIds as $rootId) {
            $children = array_values($problemChildrenByRoot[$rootId] ?? []);
            if ($children === []) {
                // Keep a stale/legacy partial root in the selection so the normal
                // bulk action reports it as skipped rather than silently dropping
                // an operator-selected source from the request.
                $expanded[$rootId] = $rootId;
                continue;
            }
            foreach ($children as $childId) {
                $expanded[$childId] = $childId;
            }
        }

        return array_values($expanded);
    }
}
