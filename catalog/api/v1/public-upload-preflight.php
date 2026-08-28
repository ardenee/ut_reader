<?php
/**
 * Public contribution manifest preflight: at most 100 files per indexed batch.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogPublicUploadBatchPreflight;
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
