<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorkerStop;
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
    if ($queueName === '' || strlen($queueName) > 80) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }

    $cancelRunning = !array_key_exists('cancel_running', $payload) || (bool)$payload['cancel_running'];
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    $stopper = new CatalogDetachedWorkerStop($application->db, $application->config);
    $result = $stopper->stopQueue(
        $queueName,
        $userId,
        $cancelRunning ? 'Stopped from Background Jobs.' : 'Detached worker stop requested.'
    );

    $worker = is_array($result['worker'] ?? null) ? $result['worker'] : [];
    if (!empty($worker['active'])) {
        JsonResponse::error(
            'worker_stop_incomplete',
            'The stop request was written, but the detached worker is still running. The current job was not reported as stopped.',
            409,
            [
                'queue' => $queueName,
                'worker_pid' => (int)($result['pid'] ?? 0),
                'worker_terminated' => !empty($result['terminated']),
                'worker' => $worker,
            ]
        );
    }

    JsonResponse::send([
        'data' => [
            'queue' => $queueName,
            'stop_requested' => true,
            'stop_completed' => true,
            'worker_terminated' => !empty($result['terminated']),
            'worker_pid' => (int)($result['pid'] ?? 0),
            'running_jobs_notified' => (int)($result['cancelled_jobs'] ?? 0) + (int)($result['cooperative_jobs'] ?? 0),
            'running_jobs_cancelled' => (int)($result['cancelled_jobs'] ?? 0),
            'worker' => $worker,
        ],
    ]);
} catch (InvalidArgumentException $exception) {
    JsonResponse::error('invalid_worker_request', $exception->getMessage(), 400);
} catch (Throwable $exception) {
    error_log('[UnrealDB detached worker action] ' . $exception->getMessage());
    JsonResponse::error('worker_action_failed', $exception->getMessage(), 500);
}
