<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

/** @return array{queued:int,ready:int,running:int,terminal:int,total:int} */
function job_worker_status_counts(PDO $db, string $queueName): array
{
    $counts = ['queued' => 0, 'ready' => 0, 'running' => 0, 'terminal' => 0, 'total' => 0];
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
    if ($counts['queued'] > 0) {
        $counts['ready'] = catalog_count(
            $db,
            'SELECT COUNT(*) c FROM ue_background_jobs '
                . 'WHERE queue_name=? AND status="queued" AND cancel_requested_at IS NULL AND available_at<=UTC_TIMESTAMP()',
            [$queueName]
        );
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

    /*
     * Status polling must never start, stop, recover or otherwise mutate the
     * queue. A browser GET runs every two seconds; allowing it to auto-heal a
     * failed launch hid the real error behind an endless launching state and
     * disabled the explicit Start button.
     */
    $launcher = new CatalogDetachedWorker($application->config);
    $worker = $launcher->status($queueName, true);
    $counts = job_worker_status_counts($application->db, $queueName);

    $active = !empty($worker['active']);
    $activeCount = max(0, (int)($worker['active_count'] ?? 0));
    $launchingCount = max(0, (int)($worker['launching_count'] ?? 0));
    $desiredCount = max(1, (int)($worker['desired_count'] ?? $launcher->configuredWorkerCount()));
    $workerState = is_array($worker['state'] ?? null) ? $worker['state'] : [];
    $stateStatus = strtolower(trim((string)($workerState['status'] ?? '')));
    $exitReason = strtolower(trim((string)($workerState['exit_reason'] ?? '')));
    $lastError = trim((string)($workerState['error'] ?? ''));

    if ($active) {
        $authoritative = 'running';
        $message = $activeCount . ' of ' . $desiredCount . ' detached worker process(es) are running.';
    } elseif ($counts['running'] > 0) {
        $authoritative = 'orphaned';
        $message = $counts['running'] . ' database job(s) still say running, but no detached worker process owns this queue.';
    } elseif ($counts['queued'] > 0) {
        $authoritative = 'stopped_with_queue';
        if ($stateStatus === 'failed' || in_array($exitReason, ['fatal_shutdown', 'uncaught_exception'], true)) {
            $message = 'Worker pool stopped after a failure with ' . $counts['queued'] . ' queued job(s).';
            if ($lastError !== '') {
                $message .= ' ' . $lastError;
            }
        } elseif ($counts['ready'] === 0) {
            $message = 'Worker pool is stopped with ' . $counts['queued'] . ' queued job(s), but none is ready yet.';
        } elseif ($exitReason === 'queue_empty') {
            $message = 'Worker pool exited without claiming any of the ' . $counts['ready'] . ' ready queued job(s). Review the worker log and restart explicitly.';
        } elseif ($launchingCount > 0) {
            $message = 'A worker launch was requested but no process owns a worker lock.';
        } else {
            $message = 'Worker pool is stopped with ' . $counts['queued'] . ' queued job(s).';
        }
    } else {
        $authoritative = 'stopped';
        $message = 'Worker pool is stopped and the queue has no active work.';
    }

    $worker['authoritative_status'] = $authoritative;
    $worker['authoritative_message'] = $message;
    $worker['queue_counts'] = $counts;
    $worker['status_read_only'] = true;
    $worker['auto_recovery'] = null;
    $worker['auto_start'] = null;
    $worker['auto_start_error'] = '';

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
