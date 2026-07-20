<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

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
    $maxJobs = $mode === 'next' ? 1 : 10000;

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $launcher = new CatalogDetachedWorker($application->config);
    $result = $launcher->start($queueName, $maxJobs);
    JsonResponse::send(['data' => ['queue' => $queueName, 'mode' => $mode] + $result], 202);
} catch (InvalidArgumentException $exception) {
    JsonResponse::error('invalid_worker_request', $exception->getMessage(), 400);
} catch (Throwable $exception) {
    error_log('[UnrealDB detached job launcher] ' . $exception->getMessage());
    JsonResponse::error('launch_failed', $exception->getMessage(), 500);
}
