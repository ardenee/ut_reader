<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/CatalogRedirectArchive.php';
require_once dirname(__DIR__, 2) . '/lib/GameProfiles.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketBatchQueue;
use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketUploadIdentityStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadDuplicateDetector;
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

/** @return array{md5:string,sha1:string} */
function bucket_chunk_hash_identity(array $source): array
{
    $md5 = strtolower(trim((string)($source['md5'] ?? '')));
    $sha1 = strtolower(trim((string)($source['sha1'] ?? '')));
    if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
        throw new InvalidArgumentException('A valid browser-calculated MD5 and SHA-1 are required for an uncompressed file.');
    }
    return ['md5' => $md5, 'sha1' => $sha1];
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
            $launcher->requestStop($queueName);
            $status = $launcher->status($queueName, false);
        }
        $active = !empty($status['active']);
        if ($active) {
            $ready = false;
        }
        $runningJob = catalog_one(
            $db,
            'SELECT id,job_type,progress_json,updated_at FROM ue_background_jobs WHERE queue_name=? AND status="running" ORDER BY id LIMIT 1',
            [$queueName]
        );
        $progress = [];
        if ($runningJob && trim((string)($runningJob['progress_json'] ?? '')) !== '') {
            try {
                $decoded = json_decode((string)$runningJob['progress_json'], true, 128, JSON_THROW_ON_ERROR);
                $progress = is_array($decoded) ? $decoded : [];
            } catch (JsonException) {
                $progress = [];
            }
        }
        $workers[] = [
            'queue' => $queueName,
            'active' => $active,
            'stop_requested' => !empty($status['stop_requested']),
            'state' => is_array($status['state'] ?? null) ? $status['state'] : [],
            'running_job' => $runningJob ? [
                'id' => (int)$runningJob['id'],
                'job_type' => (string)$runningJob['job_type'],
                'percent' => (int)($progress['percent'] ?? 0),
                'message' => trim((string)($progress['message'] ?? '')),
                'file' => trim((string)($progress['file'] ?? $progress['original_name'] ?? $progress['source_relative_path'] ?? '')),
                'updated_at' => (string)($runningJob['updated_at'] ?? ''),
            ] : null,
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
    $identityStore = new CatalogBucketUploadIdentityStore($application->config);
    $allowedExtensions = bucket_chunk_allowed_extensions($application->db, $application->config);

    if ($action === 'begin_batch') {
        // Stale chunk-directory cleanup is maintenance work, not an interactive
        // upload prerequisite. Keeping it out of this request makes Phase 1 a
        // short worker-pause request instead of an unbounded filesystem scan.
        $processing = bucket_chunk_processing_state($application->db, $application->config, true);
        JsonResponse::send([
            'ok' => true,
            'cleanup_deferred' => true,
            'processing' => $processing,
        ], 200);
    }

    if ($action === 'batch_status') {
        JsonResponse::send([
            'ok' => true,
            'processing' => bucket_chunk_processing_state($application->db, $application->config, false),
        ], 200);
    }

    if ($action === 'preflight') {
        $originalName = bucket_chunk_clean_name((string)($_POST['original_name'] ?? ''));
        bucket_chunk_validate_name($originalName, $allowedExtensions, true);
        $fileSize = (int)($_POST['file_size'] ?? 0);
        if ($fileSize < 1) {
            JsonResponse::error('invalid_size', 'Upload file size must be greater than zero.', 400);
        }
        $redirect = catalog_redirect_archive_is_supported_filename($originalName);
        if ($redirect) {
            JsonResponse::send([
                'ok' => true,
                'duplicate' => false,
                'redirect_wrapper' => true,
                'identity' => null,
                'message' => 'Compressed redirect wrappers are not compared to package MD5/SHA-1 records. The real package identity will be calculated from the decompressed output after the complete batch uploads.',
            ], 200);
        }

        $identity = bucket_chunk_hash_identity($_POST);
        $inspection = (new CatalogUploadDuplicateDetector($application->db, $application->config))
            ->inspect($fileSize, $identity['md5'], $identity['sha1']);
        $duplicate = is_array($inspection['duplicate'] ?? null) ? $inspection['duplicate'] : null;
        if ($duplicate !== null) {
            JsonResponse::send([
                'ok' => true,
                'duplicate' => true,
                'redirect_wrapper' => false,
                'identity' => $identity,
                'match' => $duplicate,
                'message' => 'An identical physical file already exists in '
                    . ((string)$duplicate['location_kind'] === 'upload_bucket' ? 'the Upload Bucket' : 'catalog storage')
                    . ' as file #' . (int)$duplicate['file_id'] . '. The browser upload was skipped.',
            ], 200);
        }

        $missing = (int)($inspection['missing_physical_matches'] ?? 0);
        $missingBase = (int)($inspection['missing_base_game_matches'] ?? 0);
        JsonResponse::send([
            'ok' => true,
            'duplicate' => false,
            'redirect_wrapper' => false,
            'identity' => $identity,
            'identity_matches' => (int)($inspection['identity_matches'] ?? 0),
            'missing_physical_matches' => $missing,
            'missing_base_game_matches' => $missingBase,
            'message' => $missingBase > 0
                ? 'Matching official base-game identity metadata exists, but its physical file is missing. Upload is allowed so UnrealDB can retain the actual package.'
                : ($missing > 0
                    ? 'Matching database identity metadata exists, but no physical file could be confirmed. Upload is allowed.'
                    : 'No identical physical file is already stored.'),
        ], 200);
    }

    if ($action === 'init') {
        $originalName = bucket_chunk_clean_name((string)($_POST['original_name'] ?? ''));
        bucket_chunk_validate_name($originalName, $allowedExtensions, true);
        $fileSize = (int)($_POST['file_size'] ?? 0);
        if ($fileSize < 1) {
            JsonResponse::error('invalid_size', 'Chunked bucket upload file size must be greater than zero.', 400);
        }
        $redirect = catalog_redirect_archive_is_supported_filename($originalName);
        $identity = $redirect ? null : bucket_chunk_hash_identity($_POST);
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
        if (is_array($identity)) {
            $identityStore->save(
                (string)$state['upload_id'],
                $userId,
                $fileSize,
                $identity['md5'],
                $identity['sha1'],
                $originalName,
                $relativePath,
                false
            );
        }
        JsonResponse::send([
            'ok' => true,
            'upload' => $state,
            'identity' => $identity,
            'redirect_wrapper' => $redirect,
        ], 200);
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
        $redirect = catalog_redirect_archive_is_supported_filename($submittedName);
        $identity = $redirect ? null : $identityStore->load($uploadId, $userId);

        JsonResponse::send([
            'ok' => true,
            'upload_id' => $uploadId,
            'upload' => $state,
            'identity' => is_array($identity)
                ? ['md5' => (string)$identity['md5'], 'sha1' => (string)$identity['sha1']]
                : null,
            'redirect_wrapper' => $redirect,
            'messages' => [[
                'status' => 'uploaded',
                'file' => $relativePath !== '' ? $relativePath : $submittedName,
                'message' => $redirect
                    ? 'Redirect wrapper transfer completed. Its real package MD5/SHA-1 and duplicate check will run after decompression.'
                    : 'Transfer completed with its pre-calculated MD5 and SHA-1 retained in durable staging. Processing will begin after every selected file finishes uploading.',
                'file_size' => (int)($state['file_size'] ?? 0),
                'file_size_text' => catalog_bytes((int)($state['file_size'] ?? 0)),
            ]],
        ], 200);
    }

    if ($action === 'cancel') {
        $store->cancel($userId, (string)($_POST['upload_id'] ?? ''));
        JsonResponse::send(['ok' => true, 'status' => 'cancelled'], 200);
    }

    JsonResponse::error('invalid_action', 'Chunked bucket upload action must be begin_batch, batch_status, preflight, init, chunk, complete or cancel.', 400);
} catch (InvalidArgumentException $error) {
    JsonResponse::error('invalid_chunk_upload', $error->getMessage(), 400);
} catch (Throwable $error) {
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] chunked bucket upload failed: ' . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error('chunk_upload_failed', bucket_chunk_short_error($error), 500, ['request_id' => $requestId]);
}
