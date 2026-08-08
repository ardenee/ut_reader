<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads queue-level job totals for the Background Jobs queue switcher.
 * Why: The page should render a read model instead of owning aggregate SQL.
 * Role: Infrastructure read query for Presentation.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoBackgroundJobQueueSummaryQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,array{total:int,queued:int,running:int}> */
    public function all(): array
    {
        $statement = $this->db->query(
            'SELECT queue_name,COUNT(*) total,'
            . 'SUM(status="queued") queued_total,SUM(status="running") running_total '
            . 'FROM ue_background_jobs GROUP BY queue_name ORDER BY queue_name'
        );
        $options = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $name = trim((string)($row['queue_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $options[$name] = [
                'total' => (int)($row['total'] ?? 0),
                'queued' => (int)($row['queued_total'] ?? 0),
                'running' => (int)($row['running_total'] ?? 0),
            ];
        }
        return $options;
    }
}
