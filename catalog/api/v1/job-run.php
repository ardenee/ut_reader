<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobWorkerFactory;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('job_action');

    $payload = catalog_api_json_body();
    $queueName = trim((string)($payload['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
    if ($queueName === '' || strlen($queueName) > 80) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }

    $leaseSeconds = max(
        15,
        min((int)($application->config['queue']['lease_seconds'] ?? 120), 3600)
    );
    $workerId = 'web:' . (gethostname() ?: 'host') . ':' . bin2hex(random_bytes(8));

    // Release the PHP session lock before a potentially long import. This lets a
    // second browser request submit a cooperative stop/cancel action immediately.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ignore_user_abort(true);
    @set_time_limit(0);

    $worker = CatalogJobWorkerFactory::create(
        $application->db,
        $application->config,
        $queueName,
        $workerId,
        $leaseSeconds
    );
    $result = $worker->runOne();

    JsonResponse::send([
        'data' => [
            'queue' => $queueName,
            'worker_id' => $workerId,
            'result' => $result,
        ],
    ]);
} catch (Throwable $exception) {
    error_log('[UnrealDB web job runner] ' . $exception->getMessage());
    JsonResponse::error('run_failed', $exception->getMessage(), 500);
}
