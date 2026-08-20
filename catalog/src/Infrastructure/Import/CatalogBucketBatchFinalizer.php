<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Orchestrates Upload Bucket batch finalization, queue preparation, durable enqueueing and optional worker start.
 * Why: The HTTP endpoint should validate/serialize transport data rather than coordinate workers, orphan recovery and durable queue state.
 * Role: Infrastructure import orchestration; preserves existing Upload Bucket batch semantics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogOrphanedJobRecovery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobOperationalQuery;

final class CatalogBucketBatchFinalizer
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /**
     * @param list<string> $uploadIds
     * @return array{
     *   queue:string,
     *   results:list<array<string,mixed>>,
     *   legacy_migrated:int,
     *   pending_jobs:int,
     *   prepare_queue:bool,
     *   start_worker:bool,
     *   worker:?array<string,mixed>,
     *   worker_error:string,
     *   orphan_recovery:array<string,mixed>
     * }
     */
    public function finalize(
        array $uploadIds,
        int $userId,
        bool $prepareQueue,
        bool $startWorker
    ): array {
        $queue = new CatalogBucketBatchQueue($this->db, $this->config);
        $launcher = new CatalogDetachedWorker($this->config);
        $orphanRecovery = [];

        if ($prepareQueue || $startWorker) {
            $activeQueues = [];
            foreach ([$queue->queueName(), $queue->legacyQueueName()] as $queueName) {
                $workerStatus = $launcher->status($queueName, false);
                if ($prepareQueue && empty($workerStatus['active'])) {
                    $recovery = (new CatalogOrphanedJobRecovery($this->db, $this->config))
                        ->recoverInactiveQueue($queueName);
                    if (!empty($recovery['recovered'])) {
                        $orphanRecovery[$queueName] = $recovery;
                    }
                    $workerStatus = $launcher->status($queueName, false);
                }
                if (!empty($workerStatus['active'])) {
                    $activeQueues[] = $queueName;
                }
            }
            if ($activeQueues !== []) {
                throw new CatalogBucketProcessingActive($activeQueues);
            }
        }

        $legacyMigrated = $prepareQueue ? $queue->migrateLegacyQueuedJobs() : 0;
        $results = [];
        foreach ($uploadIds as $uploadId) {
            try {
                $results[] = [
                    'upload_id' => $uploadId,
                    'result' => $queue->enqueueCompletedUpload($uploadId, $userId),
                    'error' => null,
                ];
            } catch (Throwable $error) {
                $message = trim($error->getMessage()) ?: get_class($error) . ' was thrown without an error message.';
                $results[] = [
                    'upload_id' => $uploadId,
                    'result' => null,
                    'error' => [
                        'class' => get_class($error),
                        'message' => $message,
                    ],
                ];
            }
        }

        $pendingJobs = (new PdoBackgroundJobOperationalQuery($this->db, $this->config))
            ->queuedCount($queue->queueName());
        $worker = null;
        $workerError = '';
        if ($startWorker && $pendingJobs > 0) {
            try {
                (new CatalogOrphanedJobRecovery($this->db, $this->config))
                    ->recoverInactiveQueue($queue->queueName());

                // Automatic Upload Bucket starts must honor the durable pool
                // preference chosen by the operator. Passing the configured
                // default here used to overwrite a live/manual 1- or 2-worker
                // choice back to four workers whenever a new batch was queued.
                $worker = $launcher->start($queue->queueName(), 10000);
            } catch (Throwable $error) {
                $workerError = trim($error->getMessage()) ?: get_class($error) . ' was thrown without an error message.';
                error_log('[UnrealDB bucket worker] ' . get_class($error) . ': ' . $workerError);
            }
        }

        return [
            'queue' => $queue->queueName(),
            'results' => $results,
            'legacy_migrated' => $legacyMigrated,
            'pending_jobs' => $pendingJobs,
            'prepare_queue' => $prepareQueue,
            'start_worker' => $startWorker,
            'worker' => $worker,
            'worker_error' => $workerError,
            'orphan_recovery' => $orphanRecovery,
        ];
    }
}
