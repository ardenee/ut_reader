<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorkerStop;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobResourceLimitStore;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogOrphanedJobRecovery;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

/** @return array{ready:int,running:int} */
function catalog_job_run_queue_counts(PDO $db, string $queueName): array
{
    return [
        'ready' => catalog_count(
            $db,
            'SELECT COUNT(*) c FROM ue_background_jobs '
                . 'WHERE queue_name=? AND status="queued" AND cancel_requested_at IS NULL AND available_at<=UTC_TIMESTAMP()',
            [$queueName]
        ),
        'running' => catalog_count(
            $db,
            'SELECT COUNT(*) c FROM ue_background_jobs WHERE queue_name=? AND status="running"',
            [$queueName]
        ),
    ];
}

/** @param array<string,mixed> $worker */
function catalog_job_run_slot_summary(array $worker, int $desiredWorkers): string
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
            : (!empty($slotWorker['launching']) ? 'launching' : strtolower(trim((string)($state['status'] ?? 'stopped'))));
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

/** @return array<string,mixed> */
function catalog_job_run_reconcile_pool(
    CatalogDetachedWorker $launcher,
    string $queueName,
    int $maxJobs,
    int $workerCount
): array {
    /*
     * Windows can take several seconds to create each hidden PHP process. The
     * detached launcher historically returned as soon as the first requested
     * slot acquired its lock, leaving Apply workers to finish at 1/4 or 2/4.
     * Keep reconciling until every requested slot has remained active for one
     * full second. Expired launching states are retried within the same request.
     */
    $deadline = microtime(true) + (PHP_OS_FAMILY === 'Windows' ? 45.0 : 25.0);
    $launchAttempts = 0;
    $lastResult = [];
    $launchErrors = [];
    $satisfiedSince = null;

    do {
        $worker = $launcher->status($queueName, true);
        $active = max(0, (int)($worker['active_count'] ?? 0));
        $launching = max(0, (int)($worker['launching_count'] ?? 0));

        if ($active >= $workerCount) {
            $satisfiedSince ??= microtime(true);
            if (microtime(true) - $satisfiedSince >= 1.0) {
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
        'slot_summary' => catalog_job_run_slot_summary($worker, $workerCount),
        'launch_errors' => array_slice($launchErrors, -5),
    ]);
}

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('job_action');

    $payload = catalog_api_json_body();
    $queueName = trim((string)($payload['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
    $mode = strtolower(trim((string)($payload['mode'] ?? 'drain')));
    if (!in_array($mode, ['next', 'drain'], true)) {
        JsonResponse::error('invalid_mode', 'Worker mode must be next or drain.', 400);
    }
    if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }

    $launcher = new CatalogDetachedWorker($application->config);
    $workerCount = $mode === 'next'
        ? 1
        : $launcher->normalizeWorkerCount((int)($payload['workers'] ?? $launcher->configuredWorkerCount()));
    $maxJobs = $mode === 'next' ? 1 : 1000000;
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $before = $launcher->status($queueName, true);
    $queueCounts = catalog_job_run_queue_counts($application->db, $queueName);
    $idleRestart = null;

    if (!empty($before['active']) && $queueCounts['ready'] > 0 && $queueCounts['running'] === 0) {
        $state = is_array($before['state'] ?? null) ? $before['state'] : [];
        $updatedAt = strtotime((string)($state['updated_at'] ?? $state['started_at'] ?? '')) ?: 0;
        $stateAge = $updatedAt > 0 ? max(0, time() - $updatedAt) : PHP_INT_MAX;
        if ($stateAge >= 5) {
            $idleRestart = (new CatalogDetachedWorkerStop($application->db, $application->config))
                ->stopQueue($queueName, $userId, 'Restarting an idle worker pool that was not claiming ready jobs.');
            $before = $launcher->status($queueName, true);
        }
    }

    $orphanRecovery = null;
    if (empty($before['active'])) {
        $orphanRecovery = (new CatalogOrphanedJobRecovery($application->db, $application->config))
            ->recoverInactiveQueue($queueName);
        $before = $launcher->status($queueName, true);
    }

    $restart = null;
    if (!empty($before['active']) && !empty($before['stale_code'])) {
        $restart = (new CatalogDetachedWorkerStop($application->db, $application->config))
            ->restartStaleQueue($queueName);
        if (empty($restart['restarted'])) {
            JsonResponse::error(
                'stale_worker_restart_failed',
                'The detached worker pool is running old code and could not be restarted automatically. Use Stop workers, then Start queued.',
                409,
                ['worker' => $before, 'restart' => $restart]
            );
        }
        $before = $launcher->status($queueName, true);
    }

    /*
     * Reconcile persisted queue policy after stale workers have been requeued
     * and before any new worker can claim them. This repairs old projection rows
     * that were stored as search-heavy/per-file work even though the handler is
     * protected by one global catalogue-maintenance lock.
     */
    $queuePolicySync = (new CatalogJobResourceLimitStore($application->db, $queueName))
        ->synchronizeQueuedPolicies();

    $result = catalog_job_run_reconcile_pool($launcher, $queueName, $maxJobs, $workerCount);
    if (empty($result['pool_satisfied'])) {
        $worker = is_array($result['worker'] ?? null) ? $result['worker'] : [];
        $active = max(0, (int)($worker['active_count'] ?? 0));
        $summary = trim((string)($result['slot_summary'] ?? catalog_job_run_slot_summary($worker, $workerCount)));
        $launchErrors = array_values(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($result['launch_errors'] ?? [])
        )));
        $lastLaunchError = $launchErrors !== [] ? end($launchErrors) : '';
        JsonResponse::error(
            'worker_pool_incomplete',
            'Requested ' . $workerCount . ' detached workers, but only ' . $active
                . ' acquired stable worker locks after reconciliation.'
                . ($summary !== '' ? ' ' . $summary : '')
                . ($lastLaunchError !== '' ? ' Last launch error: ' . mb_substr($lastLaunchError, 0, 500, 'UTF-8') : ''),
            409,
            ['worker' => $worker, 'reconciliation' => $result]
        );
    }

    JsonResponse::send([
        'data' => [
            'queue' => $queueName,
            'mode' => $mode,
            'workers' => $workerCount,
            'idle_restart' => $idleRestart,
            'orphan_recovery' => $orphanRecovery,
            'stale_restart' => $restart,
            'queue_policy_sync' => $queuePolicySync,
        ] + $result,
    ], 202);
} catch (InvalidArgumentException $exception) {
    JsonResponse::error('invalid_worker_request', $exception->getMessage(), 400);
} catch (Throwable $exception) {
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] detached job launcher failed: ' . get_class($exception) . ': ' . $exception->getMessage());
    JsonResponse::error(
        'launch_failed',
        trim($exception->getMessage()) ?: 'The detached queue worker pool could not be launched.',
        500,
        ['request_id' => $requestId]
    );
}
