<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Wakes a durable queue after feature code enqueues new work.
 * Why: Automatic producers must be able to start missing worker processes without
 *      recovering, reclassifying or rewriting unrelated historical jobs.
 * Role: Side-effect-limited feature wake adapter. Explicit operator Start/Resume
 *       continues to own queue recovery/reconciliation through CatalogWorkerPoolReconciler.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;

final class CatalogQueueWorkerStarter
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /**
     * @return array{worker:?array<string,mixed>,worker_error:string,recovery:?array<string,mixed>}
     */
    public function start(string $queueName, bool $shouldStart = true, ?int $userId = null): array
    {
        // $userId is retained for API compatibility/provenance at call sites.
        // Automatic feature wake must not mutate durable job rows.
        if (!$shouldStart) {
            return ['worker' => null, 'worker_error' => '', 'recovery' => null];
        }

        try {
            $launcher = new CatalogDetachedWorker($this->config);
            $worker = $launcher->start($queueName, 10000);

            if (($worker['reason'] ?? '') === 'stale_worker_running') {
                return [
                    'worker' => $worker,
                    'worker_error' => 'Existing workers are running older code. They will finish their current job before exiting; use Background Jobs Start / resume to reconcile the pool if required.',
                    'recovery' => null,
                ];
            }

            return ['worker' => $worker, 'worker_error' => '', 'recovery' => null];
        } catch (Throwable $error) {
            return [
                'worker' => null,
                'worker_error' => trim($error->getMessage()) !== ''
                    ? trim($error->getMessage())
                    : 'The detached worker pool could not be started.',
                'recovery' => null,
            ];
        }
    }}
