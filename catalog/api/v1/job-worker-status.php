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
    $launcher = new CatalogDetachedWorker($application->config);
    JsonResponse::send(['data' => ['worker' => $launcher->status($queueName)]]);
} catch (InvalidArgumentException $exception) {
    JsonResponse::error('invalid_worker_request', $exception->getMessage(), 400);
} catch (Throwable $exception) {
    error_log('[UnrealDB detached worker status] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'Detached worker status is unavailable.', 503);
}
