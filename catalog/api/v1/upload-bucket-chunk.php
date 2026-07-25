<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/CatalogRedirectArchive.php';
require_once dirname(__DIR__, 2) . '/lib/GameProfiles.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketBatchQueue;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadCleanup;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadStore;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

function bucket_chunk_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return 0;
    }
    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([KMGTP]?)B?$/i', $value, $match) !== 1) {
        return max(0, (int)$value);
    }
    $power = match (strtoupper((string)$match[2])) {
        'K' => 1,
        'M' => 2,
        'G' => 3,
        'T' => 4,
        'P' => 5,
        default => 0,
    };
    return (int)floor((float)$match[1] * (1024 ** $power));
}

function bucket_chunk_effective_bytes(array $config): int
{
    $chunkConfig = is_array($config['chunk_upload'] ?? null) ? $config['chunk_upload'] : [];
    $bytes = max(1024 * 1024, min((int)($chunkConfig['chunk_bytes'] ?? (16 * 1024 * 1024)), 64 * 1024 * 1024));
    $phpLimits = array_filter([
        bucket_chunk_ini_bytes((string)ini_get('upload_max_filesize')),
        bucket_chunk_ini_bytes((string)ini_get('post_max_size')),
    ], static fn(int $limit): bool => $limit > 0);
    if ($phpLimits !== []) {
        $bytes = min($bytes, max(1024 * 1024, min($phpLimits) - (512 * 1024)));
    }
    return max(1024 * 1024, $bytes);
}

/** @return list<string> */
function bucket_chunk_allowed_extensions(PDO $db, array $config): array
{
    $extensions = [];
    foreach (gp_all_profiles($db) as $profile) {
        foreach (gp_extensions($profile) as $extension) {
            $extension = catalog_clean_unreal_extension((string)$extension);
            if ($extension !== '') {
                $extensions[$extension] = true;
            }
        }
    }
    if ($extensions === []) {
        foreach (($config['allowed_extensions'] ?? []) as $extension) {
            $extension = catalog_clean_unreal_extension((string)$extension);
            if ($extension !== '') {
                $extensions[$extension] = true;
            }
        }
    }
    return array_keys($extensions);
}

function bucket_chunk_clean_name(string $name): string
{
    $name = catalog_clean_unreal_filename(basename(str_replace('\\', '/', trim($name))));
    if ($name === '' || $name === '.' || $name === '..') {
        throw new InvalidArgumentException('Chunked bucket upload filename is missing.');
    }
    return $name;
}

function bucket_chunk_validate_name(string $name, array $allowedExtensions, bool $allowRedirectWrapper): void
{
    if ($allowRedirectWrapper && catalog_redirect_archive_is_supported_filename($name)) {
        return;
    }
    $extension = catalog_clean_unreal_extension((string)pathinfo($name, PATHINFO_EXTENSION));
    if ($allowedExtensions !== [] && !in_array($extension, $allowedExtensions, true)) {
        throw new InvalidArgumentException(
            'Extension .' . ($extension !== '' ? $extension : '(none)')
            . ' is not allowed by any active game profile.'
        );
    }
}

function bucket_chunk_store(array $config): CatalogChunkedUploadStore
{
    $storeConfig = $config;
    $storeConfig['max_upload_bytes'] = PHP_INT_MAX;
    $storeConfig['max_container_upload_bytes'] = PHP_INT_MAX;
    $chunkConfig = is_array($storeConfig['chunk_upload'] ?? null) ? $storeConfig['chunk_upload'] : [];
    $chunkConfig['chunk_bytes'] = bucket_chunk_effective_bytes($config);
    $storeConfig['chunk_upload'] = $chunkConfig;
    return new CatalogChunkedUploadStore($storeConfig);
}

function bucket_chunk_short_error(Throwable $error): string
{
    $message = trim($error->getMessage());
    return $message !== '' ? $message : 'Unknown chunked upload error';
}

/** @return array{ready:bool,workers:list<array<string,mixed>>} */
function bucket_chunk_processing_state(PDO $db, array $config, bool $requestPause): array
{
    $queues = new CatalogBucketBatchQueue($db, $config);
    $launcher = new CatalogDetachedWorker($config);
    $workers = [];
    $ready = true;

    foreach ([$queues->queueName(), $queues->legacyQueueName()] as $queueName) {
        $status = $launcher->status($queueName, false);
        if ($requestPause && !empty($status['active'])) {
            // This is deliberately cooperative. The worker finishes its current
            // package, sees the stop file before claiming another, then exits.
            $launcher->requestStop($queueName);
            $status = $launcher->status($queueName, false);
        }
        $active = !empty($status['active']);
        if ($active) {
            $ready = false;
        }
        $workers[] = [
            'queue' => $queueName,
            'active' => $active,
            'stop_requested' => !empty($status['stop_requested']),
            'state' => is_array($status['state'] ?? null) ? $status['state'] : [],
        ];
    }

    return ['ready' => $ready, 'workers' => $workers];
}

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

    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $store = bucket_chunk_store($application->config);
    $allowedExtensions = bucket_chunk_allowed_extensions($application->db, $application->config);

    if ($action === 'begin_batch') {
        $pruned = (new CatalogChunkedUploadCleanup($application->config))->pruneIncomplete();
        $processing = bucket_chunk_processing_state($application->db, $application->config, true);
        JsonResponse::send(['ok' => true, 'cleanup' => $pruned, 'processing' => $processing], 200);
    }

    if ($action === 'batch_status') {
        JsonResponse::send([
            'ok' => true,
            'processing' => bucket_chunk_processing_state($application->db, $application->config, false),
        ], 200);
    }

    if ($action === 'init') {
        $originalName = bucket_chunk_clean_name((string)($_POST['original_name'] ?? ''));
        bucket_chunk_validate_name($originalName, $allowedExtensions, true);
        $fileSize = (int)($_POST['file_size'] ?? 0);
        if ($fileSize < 1) {
            JsonResponse::error('invalid_size', 'Chunked bucket upload file size must be greater than zero.', 400);
        }
        $relativePath = trim((string)($_POST['relative_path'] ?? '')) ?: $originalName;
        $state = $store->initialize(
            $userId,
            (string)($_POST['client_key'] ?? ''),
            'bucket-' . substr(hash('sha256', $originalName), 0, 24) . '.pak',
            $relativePath,
            $fileSize,
            1,
            false
        );
        JsonResponse::send(['ok' => true, 'upload' => $state], 200);
    }

    if ($action === 'chunk') {
        $file = $_FILES['chunk'] ?? null;
        if (!is_array($file)) {
            JsonResponse::error('chunk_missing', 'The upload chunk is missing.', 400);
        }
        $state = $store->writeChunk(
            $userId,
            (string)($_POST['upload_id'] ?? ''),
            (int)($_POST['chunk_index'] ?? -1),
            (string)($file['tmp_name'] ?? ''),
            (int)($file['error'] ?? UPLOAD_ERR_NO_FILE)
        );
        JsonResponse::send(['ok' => true, 'upload' => $state], 200);
    }

    if ($action === 'complete') {
        $uploadId = strtolower(trim((string)($_POST['upload_id'] ?? '')));
        $state = $store->complete($userId, $uploadId);
        $relativePath = (string)($state['relative_path'] ?? '');
        $submittedName = bucket_chunk_clean_name(basename(str_replace('\\', '/', $relativePath)));
        bucket_chunk_validate_name($submittedName, $allowedExtensions, true);

        JsonResponse::send([
            'ok' => true,
            'upload_id' => $uploadId,
            'upload' => $state,
            'messages' => [[
                'status' => 'uploaded',
                'file' => $relativePath !== '' ? $relativePath : $submittedName,
                'message' => 'Transfer completed and retained in durable staging. Processing will begin after every selected file finishes uploading.',
                'file_size' => (int)($state['file_size'] ?? 0),
                'file_size_text' => catalog_bytes((int)($state['file_size'] ?? 0)),
            ]],
        ], 200);
    }

    if ($action === 'cancel') {
        $store->cancel($userId, (string)($_POST['upload_id'] ?? ''));
        JsonResponse::send(['ok' => true, 'status' => 'cancelled'], 200);
    }

    JsonResponse::error('invalid_action', 'Chunked bucket upload action must be begin_batch, batch_status, init, chunk, complete or cancel.', 400);
} catch (InvalidArgumentException $error) {
    JsonResponse::error('invalid_chunk_upload', $error->getMessage(), 400);
} catch (Throwable $error) {
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] chunked bucket upload failed: ' . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error('chunk_upload_failed', bucket_chunk_short_error($error), 500, ['request_id' => $requestId]);
}
