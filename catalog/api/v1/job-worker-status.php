<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

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
    $counts = ['queued' => 0, 'running' => 0, 'terminal' => 0, 'total' => 0];
    foreach (catalog_all(
        $application->db,
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

    $active = !empty($worker['active']);
    if ($active) {
        $authoritative = 'running';
        $message = 'Worker process is running.';
    } elseif ($counts['running'] > 0) {
        $authoritative = 'orphaned';
        $message = $counts['running'] . ' database job(s) still say running, but no worker process owns this queue.';
    } elseif ($counts['queued'] > 0) {
        $authoritative = 'stopped_with_queue';
        $message = 'Worker is stopped with ' . $counts['queued'] . ' queued job(s).';
    } else {
        $authoritative = 'stopped';
        $message = 'Worker is stopped and the queue has no active work.';
    }

    $worker['authoritative_status'] = $authoritative;
    $worker['authoritative_message'] = $message;
    $worker['queue_counts'] = $counts;

    JsonResponse::send(['data' => ['worker' => $worker]]);
} catch (InvalidArgumentException $exception) {
    JsonResponse::error('invalid_worker_request', $exception->getMessage(), 400);
} catch (Throwable $exception) {
    error_log('[UnrealDB detached worker status] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'Detached worker status is unavailable.', 503);
}
