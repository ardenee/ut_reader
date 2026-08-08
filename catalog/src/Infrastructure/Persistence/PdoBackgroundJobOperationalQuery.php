<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides small indexed operational reads for durable background jobs.
 * Why: Worker/status HTTP endpoints need exact live queued/running counts without rescanning terminal history every two seconds.
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

        // These values drive the live worker banner/restart decision and therefore
        // must never be hidden behind a multi-second cache. Both are narrow
        // queue/status index counts rather than a GROUP BY over queue history.
        $queued = $this->statusCount($queueName, 'queued');
        $running = $this->statusCount($queueName, 'running');

        $ready = 0;
        if ($queued > 0) {
            $statement = $this->db->prepare(
                'SELECT COUNT(*) FROM ue_background_jobs '
                . 'WHERE queue_name=? AND status="queued" AND cancel_requested_at IS NULL AND available_at<=UTC_TIMESTAMP()'
            );
            $statement->execute([$queueName]);
            $ready = (int)$statement->fetchColumn();
        }

        // Terminal history is not used for scheduling or the live banner, so it
        // can share the short aggregate cache and avoid scanning old rows every poll.
        $terminalCounts = (new CatalogBackgroundJobCountCache($this->config))->remember(
            'worker-terminal:' . $queueName,
            function () use ($queueName): array {
                $statement = $this->db->prepare(
                    'SELECT COUNT(*) FROM ue_background_jobs '
                    . 'WHERE queue_name=? AND status IN ("completed","failed","dead_letter","cancelled")'
                );
                $statement->execute([$queueName]);
                return ['terminal' => (int)$statement->fetchColumn()];
            }
        );
        $terminal = max(0, (int)($terminalCounts['terminal'] ?? 0));

        return [
            'queued' => $queued,
            'ready' => $ready,
            'running' => $running,
            'terminal' => $terminal,
            'total' => $queued + $running + $terminal,
        ];
    }

    private function statusCount(string $queueName, string $status): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_background_jobs WHERE queue_name=? AND status=?'
        );
        $statement->execute([$queueName, $status]);
        return (int)$statement->fetchColumn();
    }
}
