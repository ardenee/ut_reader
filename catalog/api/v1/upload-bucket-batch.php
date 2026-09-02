<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for Upload Bucket batch finalization.
 * Why: HTTP validation and response formatting stay here while queue/worker orchestration is delegated.
 * Role: Thin HTTP API entry point.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/CatalogRedirectArchive.php';
require_once dirname(__DIR__, 2) . '/lib/GameProfiles.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketBatchFinalizer;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('upload_bucket_chunk');

    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
    if ($userId < 1) {
        JsonResponse::error('unauthorized', 'Administrator authentication is required.', 401);
    }

    // Queue finalisation can touch many uploaded files. Authentication and CSRF
    // are complete, so do not serialize the administrator's other page requests
    // behind this operation via PHP's session lock.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $payload = catalog_api_json_body();
    $rawIds = $payload['upload_ids'] ?? [];
    if (!is_array($rawIds)) {
        JsonResponse::error('invalid_uploads', 'Upload Bucket source identifiers must be an array.', 400);
    }
    if (count($rawIds) > 10000) {
        JsonResponse::error('too_many_uploads', 'Finalize no more than 10,000 uploaded files per request.', 400);
    }

    $startWorker = true;
    if (array_key_exists('start_worker', $payload)) {
        if (!is_bool($payload['start_worker'])) {
            JsonResponse::error('invalid_start_worker', 'start_worker must be a JSON boolean.', 400);
        }
        $startWorker = $payload['start_worker'];
    }

    $prepareQueue = false;
    if (array_key_exists('prepare_queue', $payload)) {
        if (!is_bool($payload['prepare_queue'])) {
            JsonResponse::error('invalid_prepare_queue', 'prepare_queue must be a JSON boolean.', 400);
        }
        $prepareQueue = $payload['prepare_queue'];
    }

    $uploadIds = [];
    foreach ($rawIds as $rawId) {
        $uploadId = strtolower(trim((string)$rawId));
        if (preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
            JsonResponse::error('invalid_upload', 'A completed Upload Bucket source identifier is invalid.', 400);
        }
        $uploadIds[$uploadId] = $uploadId;
    }
    $uploadIds = array_values($uploadIds);

    $finalized = (new CatalogBucketBatchFinalizer($application->db, $application->config))->finalize(
        $uploadIds,
        $userId,
        $prepareQueue,
        $startWorker
    );

    $messages = [];
    $jobIds = [];
    $queued = 0;
    $duplicates = 0;
    $failed = 0;

    foreach ($finalized['results'] as $item) {
        $uploadId = (string)($item['upload_id'] ?? '');
        $error = is_array($item['error'] ?? null) ? $item['error'] : null;
        if ($error !== null) {
            $failed++;
            $requestId = catalog_request_id();
            $errorClass = trim((string)($error['class'] ?? 'RuntimeException')) ?: 'RuntimeException';
            $errorMessage = trim((string)($error['message'] ?? '')) ?: $errorClass . ' was thrown without an error message.';
            error_log('[UnrealDB][' . $requestId . '] bucket file ' . $uploadId . ' failed: ' . $errorClass . ': ' . $errorMessage);
            $messages[] = [
                'status' => 'failed',
                'file' => $uploadId,
                'message' => $errorMessage . ' | reference: ' . $requestId,
            ];
            continue;
        }

        $result = is_array($item['result'] ?? null) ? $item['result'] : [];
        $jobId = (int)($result['job_id'] ?? 0);
        if ($jobId > 0) {
            $jobIds[$jobId] = $jobId;
        }

        $md5 = trim((string)($result['md5'] ?? ''));
        $sha1 = trim((string)($result['sha1'] ?? ''));
        $identityText = $md5 !== '' && $sha1 !== ''
            ? ' MD5: ' . $md5 . ' | SHA-1: ' . $sha1
            : '';

        if (!empty($result['deduplicated'])) {
            $duplicates++;
            $kind = (string)($result['duplicate_kind'] ?? '');
            $duplicateFileId = (int)($result['duplicate_file_id'] ?? 0);
            if (in_array($kind, ['upload_bucket', 'catalog_storage'], true)) {
                $message = 'An identical physical file already exists in '
                    . ($kind === 'upload_bucket' ? 'the Upload Bucket' : 'catalog storage')
                    . ' as file #' . $duplicateFileId . '.';
            } else {
                $message = 'Exact uploaded source content already belongs to active processing job #' . $jobId . '.';
            }
            if (!empty($result['duplicate_source_removed'])) {
                $message .= ' The repeated staged upload was deleted before package processing.';
            }
            $messages[] = [
                'status' => 'duplicate',
                'file' => (string)$result['source_relative_path'],
                'file_id' => $duplicateFileId,
                'message' => $message . $identityText,
                'file_size' => (int)$result['size'],
                'file_size_text' => catalog_bytes((int)$result['size']),
                'job_id' => $jobId,
            ];
            continue;
        }

        $queued++;
        $messages[] = [
            'status' => 'queued',
            'file' => (string)$result['source_relative_path'],
            'message' => $identityText !== ''
                ? 'Upload completed, retained its pre-calculated package MD5/SHA-1 and passed the final physical duplicate check. Processing job #' . $jobId . ' was created.'
                : 'Redirect wrapper upload completed. Processing job #' . $jobId . ' will decompress it, calculate the real package MD5/SHA-1 and then run the physical duplicate check.',
            'file_size' => (int)$result['size'],
            'file_size_text' => catalog_bytes((int)($result['size'] ?? 0)),
            'job_id' => $jobId,
        ];
    }

    JsonResponse::send([
        'ok' => true,
        'data' => [
            'queue' => (string)$finalized['queue'],
            'requested' => count($uploadIds),
            'queued' => $queued,
            'duplicates' => $duplicates,
            'failed' => $failed,
            'legacy_migrated' => (int)$finalized['legacy_migrated'],
            'pending_jobs' => (int)$finalized['pending_jobs'],
            'prepare_queue' => (bool)$finalized['prepare_queue'],
            'start_worker' => (bool)$finalized['start_worker'],
            'job_ids' => array_values($jobIds),
            'worker' => $finalized['worker'],
            'worker_error' => (string)$finalized['worker_error'],
            'orphan_recovery' => $finalized['orphan_recovery'],
            'messages' => $messages,
        ],
    ], 200);
} catch (Throwable $error) {
    $requestId = catalog_request_id();
    $errorMessage = trim($error->getMessage()) ?: get_class($error) . ' was thrown without an error message.';
    error_log('[UnrealDB][' . $requestId . '] bucket file finalization failed: ' . get_class($error) . ': ' . $errorMessage);
    JsonResponse::error(
        'bucket_file_finalization_failed',
        $errorMessage,
        500,
        ['request_id' => $requestId]
    );
}
