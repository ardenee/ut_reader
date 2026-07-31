<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogOrphanedJobRecovery;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

/** @return array{queued:int,running:int,terminal:int,total:int} */
function job_worker_status_counts(PDO $db, string $queueName): array
{
    $counts = ['queued' => 0, 'running' => 0, 'terminal' => 0, 'total' => 0];
    foreach (catalog_all(
        $db,
        'SELECT status,COUNT(*) c FROM ue_background_jobs WHERE queue_name=? GROUP BY status',
        [$queueName]
    ) as $row) {
        $status = strtolower(trim((string)($row['status'] ?? '')));
        $count = (int)($row['c'] ?? 0);
        $counts['total'] += $count;
        if ($status === 'queued') {
            $counts['queued'] += $count;
        } elseif ($status === 'running') {
            $counts['running'] += $count;
        } elseif (in_array($status, ['completed', 'failed', 'dead_letter', 'cancelled'], true)) {
            $counts['terminal'] += $count;
        }
    }
    return $counts;
}

try {
    $application = catalog_api_application();
    catalog_api_require_admin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        JsonResponse::error('method_not_allowed', 'Only GET is supported.', 405);
    }

    $queueName = trim((string)($_GET['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
    if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }

    $launcher = new CatalogDetachedWorker($application->config);
    $worker = $launcher->status($queueName, true);
    $counts = job_worker_status_counts($application->db, $queueName);
    $autoRecovery = null;
    $autoStart = null;
    $autoStartError = '';

    // No slot lock means no detached process can still own a detached:* lease.
    if (empty($worker['active']) && $counts['running'] > 0) {
        $autoRecovery = (new CatalogOrphanedJobRecovery($application->db, $application->config))
            ->recoverInactiveQueue($queueName);
        $counts = job_worker_status_counts($application->db, $queueName);
        $worker = $launcher->status($queueName, true);

        if ($counts['queued'] > 0) {
            try {
                $autoStart = $launcher->start(
                    $queueName,
                    10000,
                    (int)($worker['desired_count'] ?? $launcher->configuredWorkerCount())
                );
            } catch (Throwable $error) {
                $autoStartError = trim($error->getMessage()) ?: 'Recovered jobs are queued, but the worker pool could not restart.';
                error_log('[UnrealDB orphan auto-start] ' . get_class($error) . ': ' . $autoStartError);
            }
        }
        $worker = $launcher->status($queueName, true);
        $counts = job_worker_status_counts($application->db, $queueName);
    }

    $workerState = is_array($worker['state'] ?? null) ? $worker['state'] : [];
    $exitReason = strtolower(trim((string)($workerState['exit_reason'] ?? '')));
    $crashStopped = in_array($exitReason, ['fatal_shutdown', 'uncaught_exception', 'orphan_recovery'], true)
        || strtolower(trim((string)($workerState['status'] ?? ''))) === 'failed';
    if (empty($worker['active']) && $counts['running'] === 0 && $counts['queued'] > 0 && $crashStopped) {
        try {
            $autoStart = $launcher->start(
                $queueName,
                10000,
                (int)($worker['desired_count'] ?? $launcher->configuredWorkerCount())
            );
        } catch (Throwable $error) {
            $autoStartError = trim($error->getMessage()) ?: 'Crash-recovered jobs are queued, but the worker pool could not restart.';
            error_log('[UnrealDB crash queue auto-start] ' . get_class($error) . ': ' . $autoStartError);
        }
        $worker = $launcher->status($queueName, true);
        $counts = job_worker_status_counts($application->db, $queueName);
    }

    $active = !empty($worker['active']);
    $activeCount = max(0, (int)($worker['active_count'] ?? 0));
    $desiredCount = max(1, (int)($worker['desired_count'] ?? $launcher->configuredWorkerCount()));
    if ($active) {
        $authoritative = 'running';
        $message = $activeCount . ' of ' . $desiredCount . ' detached worker process(es) are running.';
    } elseif ($counts['running'] > 0) {
        $authoritative = 'orphaned';
        $message = $counts['running'] . ' database job(s) still say running, but no detached worker process owns this queue.';
    } elseif ($counts['queued'] > 0) {
        $authoritative = 'stopped_with_queue';
        $message = 'Worker pool is stopped with ' . $counts['queued'] . ' queued job(s).';
    } else {
        $authoritative = 'stopped';
        $message = 'Worker pool is stopped and the queue has no active work.';
    }

    $worker['authoritative_status'] = $authoritative;
    $worker['authoritative_message'] = $message;
    $worker['queue_counts'] = $counts;
    $worker['auto_recovery'] = $autoRecovery;
    $worker['auto_start'] = $autoStart;
    $worker['auto_start_error'] = $autoStartError;

    JsonResponse::send(['data' => ['worker' => $worker]]);
} catch (InvalidArgumentException $exception) {
    JsonResponse::error('invalid_worker_request', $exception->getMessage(), 400);
} catch (Throwable $exception) {
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] detached worker status failed: ' . get_class($exception) . ': ' . $exception->getMessage());
    JsonResponse::error(
        'unavailable',
        trim($exception->getMessage()) ?: 'Detached worker status is unavailable.',
        503,
        ['request_id' => $requestId]
    );
}
