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
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;

final class PdoBackgroundJobDisplayCountQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param list<mixed> $params
     * @return array{all:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int}
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
        ];

        $operatorStatusSql = BackgroundJobDisplaySql::operatorStatus('j');
        $sql = 'SELECT ' . $operatorStatusSql . ' AS operator_status,j.status,j.display_status,COUNT(*) AS total FROM ' . $fromSql
            . ($whereSql !== '' ? ' WHERE ' . $whereSql : '')
            . ' GROUP BY operator_status,j.status,j.display_status';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $amount = (int)($row['total'] ?? 0);
            $counts['all'] += $amount;
            $queueStatus = strtolower(trim((string)($row['status'] ?? '')));
            $operatorStatus = strtolower(trim((string)($row['operator_status'] ?? $queueStatus)));
            $group = $operatorStatus !== $queueStatus
                ? $operatorStatus
                : CatalogJobDisplayStatus::groupDisplayStatus(
                    $queueStatus,
                    (string)($row['display_status'] ?? '')
                );
            if (array_key_exists($group, $counts)) {
                $counts[$group] += $amount;
            }
        }
        return $counts;
    }
}
