<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads and optionally pauses Upload Bucket worker queues for browser batch coordination.
 * Why: Worker process control and running-job SQL must not live in the chunk-upload HTTP endpoint.
 * Role: Infrastructure orchestration used by Upload Bucket v2 begin/status actions.
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

    /** @return array{ready:bool,workers:list<array<string,mixed>>} */
    public function status(bool $requestPause): array
    {
        $queues = new CatalogBucketBatchQueue($this->db, $this->config);
        $launcher = new CatalogDetachedWorker($this->config);
        $query = new PdoBackgroundJobOperationalQuery($this->db, $this->config);
        $workers = [];
        $ready = true;

        foreach ([$queues->queueName(), $queues->legacyQueueName()] as $queueName) {
            $status = $launcher->status($queueName, false);
            if ($requestPause && !empty($status['active'])) {
                $launcher->requestStop($queueName);
                $status = $launcher->status($queueName, false);
            }
            $active = !empty($status['active']);
            if ($active) {
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
