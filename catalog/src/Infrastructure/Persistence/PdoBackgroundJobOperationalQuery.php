<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides small indexed operational reads for durable background jobs.
 * Why: Worker/status HTTP endpoints should not own queue SQL or rescan stable queue aggregates every two seconds.
 * Role: Infrastructure query object for live worker/queue diagnostics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobCountCache;

final class PdoBackgroundJobOperationalQuery
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /** @return array{queued:int,ready:int,running:int,terminal:int,total:int} */
    public function queueCounts(string $queueName): array
    {
        $queueName = PdoJobQueueSupport::requiredIdentifier($queueName, 'queue');
        $counts = (new CatalogBackgroundJobCountCache($this->config))->remember(
            'worker-operational:' . $queueName,
            fn(): array => $this->baseCounts($queueName)
        );
        $counts += ['queued' => 0, 'running' => 0, 'terminal' => 0, 'total' => 0];
        $counts['ready'] = 0;

        // Readiness depends on UTC_TIMESTAMP(), so keep this one small indexed
        // query live even while queue-status aggregates are short-cached.
        if ((int)$counts['queued'] > 0) {
            $ready = $this->db->prepare(
                'SELECT COUNT(*) FROM ue_background_jobs '
                . 'WHERE queue_name=? AND status="queued" AND cancel_requested_at IS NULL AND available_at<=UTC_TIMESTAMP()'
            );
            $ready->execute([$queueName]);
            $counts['ready'] = (int)$ready->fetchColumn();
        }

        return [
            'queued' => (int)$counts['queued'],
            'ready' => (int)$counts['ready'],
            'running' => (int)$counts['running'],
            'terminal' => (int)$counts['terminal'],
            'total' => (int)$counts['total'],
        ];
    }

    /** @return array{queued:int,running:int,terminal:int,total:int} */
    private function baseCounts(string $queueName): array
    {
        $counts = ['queued' => 0, 'running' => 0, 'terminal' => 0, 'total' => 0];
        $statement = $this->db->prepare(
            'SELECT status,COUNT(*) AS total FROM ue_background_jobs WHERE queue_name=? GROUP BY status'
        );
        $statement->execute([$queueName]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $count = (int)($row['total'] ?? 0);
            $counts['total'] += $count;
            if ($status === 'queued') {
                $counts['queued'] += $count;
            } elseif ($status === 'running') {
                $counts['running'] += $count;
            } elseif (in_array($status, ['completed', 'failed', 'dead_letter', 'cancelled'], true)) {
                $counts['terminal'] += $count;
            }
        }
        return $counts;
    }
}
