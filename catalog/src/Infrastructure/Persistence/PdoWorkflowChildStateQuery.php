<?php
/**
 * Canonical parent/child workflow status aggregation.
 *
 * Durable coordinators use the same state shape so queued/running/completed and
 * problem-unit semantics cannot drift independently between workflow handlers.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoWorkflowChildStateQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{total:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int}
     */
    public function fetch(int $parentJobId, ?string $unitPrefix = null): array
    {
        if ($parentJobId < 1) {
            throw new \InvalidArgumentException('A positive parent job id is required.');
        }

        $state = [
            'total' => 0,
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'dead_letter' => 0,
            'cancelled' => 0,
        ];

        $sql = 'SELECT status,COUNT(*) c FROM ue_background_jobs WHERE parent_job_id=?';
        $params = [$parentJobId];
        $unitPrefix = $unitPrefix !== null ? trim($unitPrefix) : null;
        if ($unitPrefix !== null && $unitPrefix !== '') {
            $sql .= ' AND workflow_unit_key LIKE ?';
            $params[] = $this->escapeLikePrefix($unitPrefix) . '%';
        }
        $sql .= ' GROUP BY status';

        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $count = max(0, (int)($row['c'] ?? 0));
            $state['total'] += $count;
            if (array_key_exists($status, $state)) {
                $state[$status] += $count;
            }
        }
        return $state;
    }

    private function escapeLikePrefix(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
