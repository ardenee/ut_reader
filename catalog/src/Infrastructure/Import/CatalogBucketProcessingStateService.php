<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads Upload Bucket worker state for compatibility/status reporting.
 * Why: Upload ingress must never stop or pause unrelated durable queue work.
 * Role: Read-only infrastructure projection used by legacy begin/status actions.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use JsonException;
use PDO;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobOperationalQuery;

final class CatalogBucketProcessingStateService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /**
     * @return array{ready:bool,workers:list<array<string,mixed>>}
     *
     * $requestPause is retained only for source/API compatibility with older
     * callers. It is intentionally ignored: upload/status requests may observe
     * worker state but must never stop a queue.
     */
    public function status(bool $requestPause = false): array
    {
        $queues = new CatalogBucketBatchQueue($this->db, $this->config);
        $launcher = new CatalogDetachedWorker($this->config);
        $query = new PdoBackgroundJobOperationalQuery($this->db, $this->config);
        $workers = [];
        $ready = true;

        foreach ([$queues->queueName(), $queues->legacyQueueName()] as $queueName) {
            $status = $launcher->status($queueName, false);
            $busy = !empty($status['active']) || (int)($status['launching_count'] ?? 0) > 0;
            $active = !empty($status['active']);
            $launching = (int)($status['launching_count'] ?? 0);
            if ($busy) {
                $ready = false;
            }

            $runningJob = $query->firstRunningJob($queueName);
            $progress = [];
            if (is_array($runningJob) && trim((string)($runningJob['progress_json'] ?? '')) !== '') {
                try {
                    $decoded = json_decode((string)$runningJob['progress_json'], true, 128, JSON_THROW_ON_ERROR);
                    $progress = is_array($decoded) ? $decoded : [];
                } catch (JsonException) {
                    $progress = [];
                }
            }

            $workers[] = [
                'queue' => $queueName,
                'active' => $active,
                'launching' => $launching,
                'busy' => $busy,
                'stop_requested' => !empty($status['stop_requested']),
                'state' => is_array($status['state'] ?? null) ? $status['state'] : [],
                'running_job' => is_array($runningJob) ? [
                    'id' => (int)$runningJob['id'],
                    'job_type' => (string)$runningJob['job_type'],
                    'percent' => (int)($progress['percent'] ?? 0),
                    'message' => trim((string)($progress['message'] ?? '')),
                    'file' => trim((string)($progress['file'] ?? $progress['original_name'] ?? $progress['source_relative_path'] ?? '')),
                    'updated_at' => (string)($runningJob['updated_at'] ?? ''),
                ] : null,
            ];
        }

        return ['ready' => $ready, 'workers' => $workers];
    }
}
