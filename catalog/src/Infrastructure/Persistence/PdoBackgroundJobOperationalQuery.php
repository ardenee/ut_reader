<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides small indexed operational reads for durable background jobs.
 * Why: Worker/status services need exact live queue state without embedding durable-job SQL in Presentation or process orchestration.
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

    /** @return array{id:int,job_type:string,progress_json:?string,updated_at:string}|null */
    public function firstRunningJob(string $queueName): ?array
    {
        $queueName = PdoJobQueueSupport::requiredIdentifier($queueName, 'queue');
        $statement = $this->db->prepare(
            'SELECT id,job_type,progress_json,updated_at FROM ue_background_jobs '
            . 'WHERE queue_name=? AND status="running" ORDER BY id LIMIT 1'
        );
        $statement->execute([$queueName]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'job_type' => (string)$row['job_type'],
            'progress_json' => $row['progress_json'] !== null ? (string)$row['progress_json'] : null,
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    public function queuedCount(string $queueName): int
    {
        return $this->statusCount(PdoJobQueueSupport::requiredIdentifier($queueName, 'queue'), 'queued');
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
