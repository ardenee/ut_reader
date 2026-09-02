<?php
/**
 * Re-runs explicitly selected completed archive source trees from retained bytes.
 *
 * Generic Retry is intentionally failure-oriented. A completed archive is different:
 * an administrator may need to replay it after archive/classifier code changes so
 * newly supported nested containers are discovered. This service handles only that
 * explicit selected-root case and resets the existing archive workflow tree as one
 * transaction; partial/problem archives remain owned by the normal retry policy.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;

final class PdoCompletedArchiveRerunSelection
{
    private const UPDATE_BATCH = 500;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param list<int> $selectedRootIds
     * @return array{
     *   handled_root_ids:list<int>,requested:int,affected:int,descendants_requeued:int,skipped:int
     * }
     */
    public function rerunSelected(string $queueName, array $selectedRootIds, string $now): array
    {
        $selectedRootIds = array_values(array_unique(array_filter(
            array_map('intval', $selectedRootIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($selectedRootIds === []) {
            return $this->emptyResult();
        }

        $roots = $this->completedArchiveRootIds($queueName, $selectedRootIds);
        if ($roots === []) {
            return $this->emptyResult();
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $descendantIds = $this->archiveDescendantIds($queueName, $roots);
            $descendantsRequeued = $this->resetJobs($queueName, $descendantIds, $now);
            $rootsRequeued = $this->resetJobs($queueName, $roots, $now, true);

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return [
                'handled_root_ids' => $roots,
                'requested' => count($roots),
                'affected' => $rootsRequeued,
                'descendants_requeued' => $descendantsRequeued,
                'skipped' => max(0, count($roots) - $rootsRequeued),
            ];
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @param list<int> $selectedRootIds @return list<int> */
    private function completedArchiveRootIds(string $queueName, array $selectedRootIds): array
    {
        $ids = [];
        foreach (array_chunk($selectedRootIds, self::UPDATE_BATCH) as $chunk) {
            $idSql = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT id FROM ue_background_jobs WHERE queue_name=? '
                . 'AND parent_job_id IS NULL AND id IN (' . $idSql . ') '
                . 'AND status="completed" AND job_type IN (?,?) '
                . 'AND display_status NOT IN ("partial","failed","rejected","unverified","error") '
                . 'AND JSON_VALID(result_json) '
                . 'AND COALESCE(JSON_EXTRACT(result_json,"$.source_retained"),false)=true '
                . 'ORDER BY id'
            );
            $statement->execute(array_merge(
                [$queueName],
                $chunk,
                [JobType::PROCESS_BUCKET_ARCHIVE, JobType::IMPORT_STAGED_ARCHIVE]
            ));
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }
        return array_map('intval', array_keys($ids));
    }

    /** @param list<int> $rootIds @return list<int> */
    private function archiveDescendantIds(string $queueName, array $rootIds): array
    {
        if ($rootIds === []) {
            return [];
        }

        $rootSql = implode(',', array_fill(0, count($rootIds), '?'));
        $statement = $this->db->prepare(
            'WITH RECURSIVE archive_descendants AS ('
            . 'SELECT id,parent_job_id FROM ue_background_jobs '
            . 'WHERE queue_name=? AND parent_job_id IN (' . $rootSql . ') '
            . 'AND workflow_unit_key LIKE "archive:%" '
            . 'UNION ALL '
            . 'SELECT j.id,j.parent_job_id FROM ue_background_jobs j '
            . 'INNER JOIN archive_descendants d ON d.id=j.parent_job_id '
            . 'WHERE j.queue_name=? AND j.workflow_unit_key LIKE "archive:%"'
            . ') SELECT DISTINCT id FROM archive_descendants ORDER BY id'
        );
        $statement->execute(array_merge([$queueName], $rootIds, [$queueName]));
        return array_values(array_unique(array_filter(
            array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []),
            static fn(int $id): bool => $id > 0
        )));
    }

    /** @param list<int> $jobIds */
    private function resetJobs(string $queueName, array $jobIds, string $now, bool $rootsOnly = false): int
    {
        if ($jobIds === []) {
            return 0;
        }

        $affected = 0;
        foreach (array_chunk($jobIds, self::UPDATE_BATCH) as $chunk) {
            $idSql = implode(',', array_fill(0, count($chunk), '?'));
            $where = $rootsOnly
                ? 'status="completed"'
                : 'status IN ("completed","failed","dead_letter","cancelled")';
            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="queued",attempts=0,available_at=?,'
                . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
                . 'last_error=NULL,result_json=NULL,progress_json=NULL,progress_updated_at=NULL,'
                . 'cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,'
                . 'dead_lettered_at=NULL,completed_at=NULL,updated_at=? '
                . 'WHERE queue_name=? AND id IN (' . $idSql . ') AND ' . $where
            );
            $statement->execute(array_merge([$now, $now, $queueName], $chunk));
            $affected += $statement->rowCount();
        }
        return $affected;
    }

    /** @return array{handled_root_ids:list<int>,requested:int,affected:int,descendants_requeued:int,skipped:int} */
    private function emptyResult(): array
    {
        return [
            'handled_root_ids' => [],
            'requested' => 0,
            'affected' => 0,
            'descendants_requeued' => 0,
            'skipped' => 0,
        ];
    }
}
