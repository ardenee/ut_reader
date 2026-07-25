<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogOrphanedJobRecovery;
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
    $recovery = (new CatalogOrphanedJobRecovery($application->db, $application->config))
        ->recoverInactiveQueue($queueName);

    $launcher = new CatalogDetachedWorker($application->config);
    $queued = catalog_count(
        $application->db,
        'SELECT COUNT(*) c FROM ue_background_jobs WHERE queue_name=? AND status="queued"',
        [$queueName]
    );
    $worker = null;
    $workerError = '';
    if ($queued > 0) {
        try {
            $worker = $launcher->start($queueName, 10000);
        } catch (Throwable $error) {
            $workerError = trim($error->getMessage()) ?: 'The recovered queue worker could not be started.';
        }
    }

    JsonResponse::send([
        'data' => [
            'queue' => $queueName,
            'queued' => $queued,
            'worker' => $worker,
            'worker_error' => $workerError,
        ] + $recovery,
    ]);
} catch (InvalidArgumentException $error) {
    JsonResponse::error('invalid_recovery_request', $error->getMessage(), 400);
} catch (Throwable $error) {
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] orphaned queue recovery failed: ' . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error(
        'orphan_recovery_failed',
        trim($error->getMessage()) ?: 'The orphaned job could not be recovered.',
        500,
        ['request_id' => $requestId]
    );
}
