<?php
/**
 * Bounded leaf-first deletion for hidden background-job workflow rows.
 *
 * Parent jobs own their workflow ledger through an ON DELETE CASCADE foreign
 * key. Deleting a large parent directly can therefore turn one apparent job
 * deletion into a multi-million-row InnoDB cascade with no heartbeat. This
 * helper drains descendants explicitly from the leaves upward so each SQL
 * delete stays bounded and the cleanup worker can checkpoint between batches.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;

final class CatalogBackgroundJobSubtreePruner
{
    public const CHILD_SCAN_LIMIT = 5000;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Inspect one bounded page of immediate children.
     *
     * @return array{leaf_ids:list<int>,branch_ids:list<int>}
     */
    public function childPage(int $parentJobId, int $limit = self::CHILD_SCAN_LIMIT): array
    {
        if ($parentJobId < 1) {
            return ['leaf_ids' => [], 'branch_ids' => []];
        }
        $limit = max(1, min(self::CHILD_SCAN_LIMIT, $limit));
        $statement = $this->db->prepare(
            'SELECT c.id,EXISTS('
            . 'SELECT 1 FROM ue_background_jobs gc WHERE gc.parent_job_id=c.id LIMIT 1'
            . ') has_children '
            . 'FROM ue_background_jobs c WHERE c.parent_job_id=? '
            . 'ORDER BY c.id ASC LIMIT ' . $limit
        );
        $statement->execute([$parentJobId]);
        $leafIds = [];
        $branchIds = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            if ((int)($row['has_children'] ?? 0) > 0) {
                $branchIds[] = $id;
            } else {
                $leafIds[] = $id;
            }
        }
        return ['leaf_ids' => $leafIds, 'branch_ids' => $branchIds];
    }

    /**
     * Delete rows that were just observed as leaves. No filesystem/event-log
     * cleanup is performed here: the previous FK cascade also removed hidden
     * descendants only from the database. Operator-visible snapshot rows still
     * pass through CatalogBackgroundJobCleanup for their normal retained-source
     * cleanup.
     *
     * @param list<int> $jobIds
     */
    public function deleteLeafRows(array $jobIds): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $jobIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($ids, self::CHILD_SCAN_LIMIT) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'DELETE FROM ue_background_jobs WHERE id IN (' . $placeholders . ')'
            );
            $statement->execute($chunk);
            $deleted += max(0, $statement->rowCount());
        }
        return $deleted;
    }

    public function exists(int $jobId): bool
    {
        if ($jobId < 1) {
            return false;
        }
        $statement = $this->db->prepare('SELECT 1 FROM ue_background_jobs WHERE id=? LIMIT 1');
        $statement->execute([$jobId]);
        return $statement->fetchColumn() !== false;
    }
}
