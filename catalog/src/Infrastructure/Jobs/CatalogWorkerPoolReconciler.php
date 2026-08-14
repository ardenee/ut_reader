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

        /*
         * Start/resume must never kill a live worker merely because it has not
         * claimed a database row within an arbitrary number of seconds. Process
         * liveness is authoritative. A worker leaves the pool only through an
         * explicit stop, an actual process failure, or stale-code replacement.
         *
         * The previous "idle restart" path called stopQueue(), which is the hard
         * administrative stop path and could repeatedly tear down a healthy pool
         * while ready work was temporarily blocked by affinity/resource policy.
         */
        $idleRestart = null;

        $orphanRecovery = null;
        if (empty($before['active']) && ($queueCounts['queued'] > 0 || $queueCounts['running'] > 0)) {
            $orphanRecovery = (new CatalogOrphanedJobRecovery($this->db, $this->config))
                ->recoverInactiveQueue($queueName);
            $before = $launcher->status($queueName, false);
        }

        $restart = null;
        if (!empty($before['active']) && !empty($before['stale_code'])
            && ($queueCounts['queued'] > 0 || $queueCounts['running'] > 0)) {
            $staleWorker = $launcher->status($queueName, true);
            $restart = (new CatalogDetachedWorkerStop($this->db, $this->config))
                ->restartStaleQueue($queueName);
            if (empty($restart['restarted'])) {
                throw new CatalogWorkerPoolStaleRestartFailed($staleWorker, $restart);
            }
            $before = $launcher->status($queueName, false);
        }

        /*
         * Do not mass-rewrite queued rows in the Start/Resume request. Resource
         * policy is synchronized when administrator settings are saved, while new
         * rows receive current policy at enqueue time. Starting a worker pool must
         * remain O(worker-count), not O(queue-size), particularly when a dependency
         * workflow has tens of thousands of durable child rows.
         */
        $queuePolicySync = [
            'updated_jobs' => 0,
            'updated_limits' => 0,
            'projection_rows' => 0,
            'rekeyed_jobs' => 0,
            'per_class' => [],
            'skipped_on_start' => true,
        ];

        // Starting/resuming an empty queue is a successful no-op. Do not spawn
        // workers merely to watch them exit four idle passes later.
        $queueCounts = (new PdoBackgroundJobOperationalQuery($this->db, $this->config))
            ->queueCounts($queueName);
        if ($queueCounts['queued'] === 0 && $queueCounts['running'] === 0) {
            $worker = $launcher->status($queueName, true);
            return [
                'queue' => $queueName,
                'mode' => $mode,
                'workers' => $workerCount,
                'idle_restart' => $idleRestart,
                'orphan_recovery' => $orphanRecovery,
                'stale_restart' => $restart,
                'queue_policy_sync' => $queuePolicySync,
                'started' => false,
                'reason' => 'queue_empty',
                'requested_workers' => $workerCount,
                'started_workers' => 0,
                'stopping_workers' => 0,
                'worker' => $worker,
                'pool_satisfied' => true,
                'no_work' => true,
                'reconcile_attempts' => 0,
                'slot_summary' => $this->slotSummary($worker, $workerCount),
                'launch_errors' => [],
            ];
        }

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
         * Reconcile until every requested slot has remained active for two full
         * seconds. This is long enough to catch immediate bootstrap/first-claim
         * failures without inventing a job-claim timeout.
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
                if (microtime(true) - $satisfiedSince >= 2.0) {
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
