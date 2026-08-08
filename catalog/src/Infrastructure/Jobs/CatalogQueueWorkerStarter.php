<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Starts or reconciles a durable queue after feature code enqueues work.
 * Why: Upload, backup and maintenance controllers must not each implement detached-worker recovery/start policy.
 * Role: Infrastructure orchestration adapter around orphan recovery and the authoritative worker-pool reconciler.
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
        $recovery = null;
        try {
            // Preserve the established enqueue-path behaviour: recover durable
            // rows left by a vanished detached process before deciding whether a
            // fresh pool is required.
            $recovery = (new CatalogOrphanedJobRecovery($this->db, $this->config))
                ->recoverInactiveQueue($queueName);

            if (!$shouldStart) {
                return ['worker' => null, 'worker_error' => '', 'recovery' => $recovery];
            }

            $worker = (new CatalogWorkerPoolReconciler($this->db, $this->config))
                ->run($queueName, 'drain', null, $userId);
            if (empty($worker['pool_satisfied'])) {
                $active = max(0, (int)($worker['worker']['active_count'] ?? 0));
                $requested = max(1, (int)($worker['workers'] ?? 1));
                return [
                    'worker' => $worker,
                    'worker_error' => 'The jobs were queued, but only ' . $active . ' of '
                        . $requested . ' configured detached workers became active.',
                    'recovery' => $recovery,
                ];
            }

            return ['worker' => $worker, 'worker_error' => '', 'recovery' => $recovery];
        } catch (CatalogWorkerPoolStaleRestartFailed $error) {
            return [
                'worker' => null,
                'worker_error' => trim($error->getMessage()) !== ''
                    ? trim($error->getMessage())
                    : 'The detached worker pool is running stale code and could not be restarted.',
                'recovery' => $recovery,
            ];
        } catch (Throwable $error) {
            return [
                'worker' => null,
                'worker_error' => trim($error->getMessage()) !== ''
                    ? trim($error->getMessage())
                    : 'The detached worker pool could not be started.',
                'recovery' => $recovery,
            ];
        }
    }
}
