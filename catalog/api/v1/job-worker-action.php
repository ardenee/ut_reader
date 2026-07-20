<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('job_action');

    $payload = catalog_api_json_body();
    $action = strtolower(trim((string)($payload['action'] ?? '')));
    $queueName = trim((string)($payload['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
    if ($action !== 'stop') {
        JsonResponse::error('invalid_action', 'Supported worker action is stop.', 400);
    }

    $launcher = new CatalogDetachedWorker($application->config);
    $worker = $launcher->requestStop($queueName);
    $cancelRunning = !array_key_exists('cancel_running', $payload) || (bool)$payload['cancel_running'];
    $cancelled = 0;
    if ($cancelRunning) {
        $rows = catalog_all(
            $application->db,
            'SELECT id FROM ue_background_jobs WHERE queue_name=? AND status="running" ORDER BY id',
            [$queueName]
        );
        $queue = new PdoJobQueue($application->db);
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        foreach ($rows as $row) {
            $status = $queue->requestCancellation(
                (int)$row['id'],
                $userId,
                'Stopped from Background Jobs.'
            );
            if (in_array($status, ['cancelled', 'cancel_requested'], true)) {
                $cancelled++;
            }
        }
    }

    JsonResponse::send([
        'data' => [
            'queue' => $queueName,
            'stop_requested' => true,
            'running_jobs_notified' => $cancelled,
            'worker' => $worker,
        ],
    ]);
} catch (InvalidArgumentException $exception) {
    JsonResponse::error('invalid_worker_request', $exception->getMessage(), 400);
} catch (Throwable $exception) {
    error_log('[UnrealDB detached worker action] ' . $exception->getMessage());
    JsonResponse::error('worker_action_failed', $exception->getMessage(), 500);
}
