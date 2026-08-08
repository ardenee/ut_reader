<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reconciles detached worker processes with durable queue state and the requested worker-pool size.
 * Why: Worker start/restart/recovery policy belongs in one reusable orchestration service, not in HTTP endpoints.
 * Role: Infrastructure process orchestration used by the Background Jobs run API.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobOperationalQuery;

final class CatalogWorkerPoolReconciler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /** @return array<string,mixed> */
    public function run(
        string $queueName,
        string $mode,
        ?int $requestedWorkers = null,
        ?int $userId = null
    ): array {
        $launcher = new CatalogDetachedWorker($this->config);
        $workerCount = $mode === 'next'
            ? 1
            : $launcher->normalizeWorkerCount(
                $requestedWorkers ?? $launcher->configuredWorkerCount()
            );
        $maxJobs = $mode === 'next' ? 1 : 1000000;

        $before = $launcher->status($queueName, false);
        $queueCounts = (new PdoBackgroundJobOperationalQuery($this->db, $this->config))
            ->queueCounts($queueName);
        $idleRestart = null;

        if (!empty($before['active']) && $queueCounts['ready'] > 0 && $queueCounts['running'] === 0) {
            $state = is_array($before['state'] ?? null) ? $before['state'] : [];
            $updatedAt = strtotime((string)($state['updated_at'] ?? $state['started_at'] ?? '')) ?: 0;
            $stateAge = $updatedAt > 0 ? max(0, time() - $updatedAt) : PHP_INT_MAX;
            if ($stateAge >= 5) {
                $idleRestart = (new CatalogDetachedWorkerStop($this->db, $this->config))
                    ->stopQueue($queueName, $userId, 'Restarting an idle worker pool that was not claiming ready jobs.');
                $before = $launcher->status($queueName, false);
            }
        }

        $orphanRecovery = null;
        if (empty($before['active'])) {
            $orphanRecovery = (new CatalogOrphanedJobRecovery($this->db, $this->config))
                ->recoverInactiveQueue($queueName);
            $before = $launcher->status($queueName, false);
        }

        $restart = null;
        if (!empty($before['active']) && !empty($before['stale_code'])) {
            $staleWorker = $launcher->status($queueName, true);
            $restart = (new CatalogDetachedWorkerStop($this->db, $this->config))
                ->restartStaleQueue($queueName);
            if (empty($restart['restarted'])) {
                throw new CatalogWorkerPoolStaleRestartFailed($staleWorker, $restart);
            }
            $before = $launcher->status($queueName, false);
        }

        // Persisted queue rows must be reconciled before new workers can claim
        // them. This keeps current resource/concurrency policy authoritative.
        $queuePolicySync = (new CatalogJobResourceLimitStore($this->db, $queueName))
            ->synchronizeQueuedPolicies();

        $result = $this->reconcilePool($launcher, $queueName, $maxJobs, $workerCount);

        return [
            'queue' => $queueName,
            'mode' => $mode,
            'workers' => $workerCount,
            'idle_restart' => $idleRestart,
            'orphan_recovery' => $orphanRecovery,
            'stale_restart' => $restart,
            'queue_policy_sync' => $queuePolicySync,
        ] + $result;
    }

    /** @return array<string,mixed> */
    private function reconcilePool(
        CatalogDetachedWorker $launcher,
        string $queueName,
        int $maxJobs,
        int $workerCount
    ): array {
        /*
         * Windows can take several seconds to create each hidden PHP process.
         * Reconcile until every requested slot has remained active for one full
         * second. Polling intentionally excludes log tails; logs are loaded only
         * if reconciliation ultimately fails.
         */
        $deadline = microtime(true) + (PHP_OS_FAMILY === 'Windows' ? 45.0 : 25.0);
        $launchAttempts = 0;
        $lastResult = [];
        $launchErrors = [];
        $satisfiedSince = null;

        do {
            $worker = $launcher->status($queueName, false);
            $active = max(0, (int)($worker['active_count'] ?? 0));
            $launching = max(0, (int)($worker['launching_count'] ?? 0));

            if ($active >= $workerCount) {
                $satisfiedSince ??= microtime(true);
                if (microtime(true) - $satisfiedSince >= 1.0) {
                    $worker = $launcher->status($queueName, true);
                    return array_merge($lastResult, [
                        'started' => !empty($lastResult['started']),
                        'reason' => (string)($lastResult['reason'] ?? 'pool_already_satisfied'),
                        'requested_workers' => $workerCount,
                        'started_workers' => (int)($lastResult['started_workers'] ?? 0),
                        'stopping_workers' => (int)($lastResult['stopping_workers'] ?? 0),
                        'worker' => $worker,
                        'pool_satisfied' => true,
                        'reconcile_attempts' => $launchAttempts,
                        'launch_errors' => array_slice($launchErrors, -5),
                    ]);
                }
            } else {
                $satisfiedSince = null;
            }

            if ($active + $launching < $workerCount) {
                try {
                    $lastResult = $launcher->start($queueName, $maxJobs, $workerCount);
                } catch (Throwable $error) {
                    $launchErrors[] = trim($error->getMessage()) !== ''
                        ? trim($error->getMessage())
                        : get_class($error);
                }
                $launchAttempts++;
            }

            usleep(250000);
        } while (microtime(true) < $deadline);

        $worker = $launcher->status($queueName, true);
        return array_merge($lastResult, [
            'started' => !empty($lastResult['started']),
            'reason' => (string)($lastResult['reason'] ?? 'pool_not_satisfied'),
            'requested_workers' => $workerCount,
            'started_workers' => (int)($lastResult['started_workers'] ?? 0),
            'stopping_workers' => (int)($lastResult['stopping_workers'] ?? 0),
            'worker' => $worker,
            'pool_satisfied' => max(0, (int)($worker['active_count'] ?? 0)) >= $workerCount,
            'reconcile_attempts' => $launchAttempts,
            'slot_summary' => $this->slotSummary($worker, $workerCount),
            'launch_errors' => array_slice($launchErrors, -5),
        ]);
    }

    /** @param array<string,mixed> $worker */
    private function slotSummary(array $worker, int $desiredWorkers): string
    {
        $slots = [];
        foreach ((array)($worker['workers'] ?? []) as $slotWorker) {
            if (!is_array($slotWorker)) {
                continue;
            }
            $slot = max(1, (int)($slotWorker['slot'] ?? 1));
            if ($slot > $desiredWorkers) {
                continue;
            }
            $state = is_array($slotWorker['state'] ?? null) ? $slotWorker['state'] : [];
            $status = !empty($slotWorker['active'])
                ? 'running'
                : (!empty($slotWorker['launching'])
                    ? 'launching'
                    : strtolower(trim((string)($state['status'] ?? 'stopped'))));
            $reason = strtolower(trim((string)($state['exit_reason'] ?? '')));
            $error = trim((string)($state['error'] ?? ''));
            $detail = 'slot ' . $slot . '=' . ($status !== '' ? $status : 'stopped');
            if ($reason !== '') {
                $detail .= '(' . $reason . ')';
            } elseif ($error !== '') {
                $detail .= '(' . mb_substr($error, 0, 160, 'UTF-8') . ')';
            }
            $slots[$slot] = $detail;
        }

        for ($slot = 1; $slot <= $desiredWorkers; $slot++) {
            if (!isset($slots[$slot])) {
                $slots[$slot] = 'slot ' . $slot . '=missing';
            }
        }
        ksort($slots);
        return implode('; ', $slots);
    }
}
