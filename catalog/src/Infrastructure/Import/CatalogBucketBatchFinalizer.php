<?php
/**
 * Upload Bucket durable batch finalization.
 *
 * Finalization is intentionally append-only with respect to queue history:
 * it validates the supplied completed uploads, enqueues only those sources and
 * optionally wakes worker processes. It does not migrate legacy queues, recover
 * orphaned jobs, stop active workers or rewrite unrelated durable rows.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
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
     * $prepareQueue is retained for transport/API compatibility with the browser
     * coordinator. A durable queue no longer requires a destructive preparation
     * phase before another batch can be appended.
     *
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

        $results = [];
        foreach ($uploadIds as $uploadId) {
            try {
                $results[] = [
                    'upload_id' => $uploadId,
                    'result' => $queue->enqueueCompletedUpload($uploadId, $userId),
                    'error' => null,
                ];
            } catch (Throwable $error) {
                $message = trim($error->getMessage())
                    ?: get_class($error) . ' was thrown without an error message.';
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
            $start = (new CatalogQueueWorkerStarter($this->db, $this->config))
                ->start($queue->queueName(), true, $userId);
            $worker = is_array($start['worker'] ?? null) ? $start['worker'] : null;
            $workerError = trim((string)($start['worker_error'] ?? ''));
            if ($workerError !== '') {
                error_log('[UnrealDB bucket worker] ' . $workerError);
            }
        }

        return [
            'queue' => $queue->queueName(),
            'results' => $results,
            // Historical queue migration is explicit maintenance only.
            'legacy_migrated' => 0,
            'pending_jobs' => $pendingJobs,
            'prepare_queue' => $prepareQueue,
            'start_worker' => $startWorker,
            'worker' => $worker,
            'worker_error' => $workerError,
            // Automatic upload finalization never performs orphan recovery.
            'orphan_recovery' => [],
        ];
    }
}
