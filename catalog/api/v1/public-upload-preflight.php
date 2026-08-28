<?php
/**
 * Public contribution manifest preflight: at most 100 files per indexed batch.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogPublicUploadBatchPreflight;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_csrf('public_upload');
    $payload = catalog_api_json_body();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $files = $payload['files'] ?? null;
    if (!is_array($files)) {
        JsonResponse::error('invalid_manifest', 'Public upload preflight requires a files array.', 400);
    }

    $result = (new CatalogPublicUploadBatchPreflight($application->db, $application->config))->inspect(
        array_values($files),
        catalog_client_ip(),
        (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
    );

    if ((int)($result['expired_released'] ?? 0) > 0) {
        $configuredQueue = trim((string)($application->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $queueName = $configuredQueue . ':public-upload';
        (new PdoJobQueue($application->db))->enqueue(
            $queueName,
            JobType::PRUNE_PUBLIC_UPLOADS,
            ['source_relative_path' => 'Expired public upload quarantine cleanup'],
            200,
            null,
            'public-upload-prune',
            null,
            3
        );
        (new CatalogQueueWorkerStarter($application->db, $application->config))->start($queueName, true, null);
    }

    JsonResponse::send(['ok' => true, 'data' => $result], 200);
} catch (\InvalidArgumentException $error) {
    JsonResponse::error('invalid_manifest', $error->getMessage(), 400);
} catch (\RuntimeException $error) {
    $message = trim($error->getMessage()) ?: 'Public upload preflight is unavailable.';
    $status = preg_match('/limit|paused|reserve|busy/i', $message) === 1 ? 429 : 409;
    JsonResponse::error('public_upload_unavailable', $message, $status);
} catch (\Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] public upload preflight failed: '
        . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error('public_upload_failed', 'Public upload preflight failed.', 500, [
        'request_id' => catalog_request_id(),
    ]);
}
