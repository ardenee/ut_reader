<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Aggregates Background Jobs display groups for the operator job view.
 * Why: Internal workflow units roll up into their parent job without making a waiting workflow look actively executed.
 * Role: Infrastructure query object used by cursor/background-job APIs.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;

final class PdoBackgroundJobDisplayCountQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param list<mixed> $params
     * @return array{all:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int,partial_archive:int}
     */
    public function counts(string $fromSql, string $whereSql, array $params): array
    {
        $counts = [
            'all' => 0,
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'dead_letter' => 0,
            'cancelled' => 0,
            'partial_archive' => 0,
        ];

        /*
         * Do not GROUP BY BackgroundJobDisplaySql::operatorStatus(). That expression
         * contains an EXISTS(child) lookup, so grouping a large operator ledger can
         * make MySQL evaluate a correlated child query for every visible job while
         * also building a temporary aggregate. This previously allowed one browser
         * poll to run for thousands of seconds.
         *
         * First aggregate only persisted/indexed status columns. Then perform one
         * separate indexed count for the only synthetic state we need: a queued
         * top-level workflow with a currently-running child is shown as Running.
         */
        $sql = 'SELECT j.status,j.display_status,j.job_type,COUNT(*) AS total FROM ' . $fromSql
            . ($whereSql !== '' ? ' WHERE ' . $whereSql : '')
            . ' GROUP BY j.status,j.display_status,j.job_type';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $amount = (int)($row['total'] ?? 0);
            $counts['all'] += $amount;
            $queueStatus = strtolower(trim((string)($row['status'] ?? '')));
            $displayStatus = strtolower(trim((string)($row['display_status'] ?? '')));
            $jobType = trim((string)($row['job_type'] ?? ''));
            if ($queueStatus === 'completed'
                && $displayStatus === 'partial'
                && in_array($jobType, [JobType::PROCESS_BUCKET_ARCHIVE, JobType::IMPORT_STAGED_ARCHIVE], true)) {
                $counts['partial_archive'] += $amount;
            }

            $group = CatalogJobDisplayStatus::groupDisplayStatus($queueStatus, $displayStatus);
            if (array_key_exists($group, $counts)) {
                $counts[$group] += $amount;
            }
        }

        if ($counts['queued'] > 0) {
            $promotionWhere = [];
            if ($whereSql !== '') {
                $promotionWhere[] = '(' . $whereSql . ')';
            }
            $promotionWhere[] = 'j.parent_job_id IS NULL';
            $promotionWhere[] = 'j.status="queued"';
            $promotionWhere[] = 'EXISTS('
                . 'SELECT 1 FROM ue_background_jobs running_child '
                . 'WHERE running_child.parent_job_id=j.id '
                . 'AND running_child.status="running" LIMIT 1'
                . ')';

            $promotion = $this->db->prepare(
                'SELECT COUNT(*) FROM ' . $fromSql . ' WHERE ' . implode(' AND ', $promotionWhere)
            );
            $promotion->execute($params);
            $promoted = max(0, min($counts['queued'], (int)$promotion->fetchColumn()));
            if ($promoted > 0) {
                $counts['queued'] -= $promoted;
                $counts['running'] += $promoted;
            }
        }

        return $counts;
    }
}
