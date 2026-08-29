<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for resumable Upload Bucket v2 chunks.
 * Why: HTTP validation and action serialization stay here while file/profile policy, transfer-store composition and worker-state orchestration are delegated.
 * Role: Thin HTTP API entry point for chunk transfer.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketProcessingStateService;
use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketUploadIdentityStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketUploadTransferStoreFactory;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadBucketFilePolicy;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadDuplicateDetector;
use UnrealDb\Catalog\Infrastructure\Security\CatalogPublicAccessGuard;
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

    // Upload Bucket hashing checks, chunk writes and file assembly must never
    // retain PHP's per-session lock. Without this, another page from the same
    // admin login blocks behind every long upload request and looks DB-locked.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    if (!in_array($action, ['batch_status', 'cancel'], true)) {
        (new CatalogPublicAccessGuard($application->config))
            ->transferAllowedOrThrow($application->db, 'Upload');
    }
    $store = CatalogBucketUploadTransferStoreFactory::create($application->config);
    $identityStore = new CatalogBucketUploadIdentityStore($application->config);
    $filePolicy = new CatalogUploadBucketFilePolicy($application->db, $application->config);
    $processingState = new CatalogBucketProcessingStateService($application->db, $application->config);

    if ($action === 'begin_batch') {
        // Stale chunk-directory cleanup is maintenance work, not an interactive
        // upload prerequisite. Keeping it out of this request makes Phase 1 a
        // short worker-pause request instead of an unbounded filesystem scan.
        JsonResponse::send([
            'ok' => true,
            'cleanup_deferred' => true,
            'processing' => $processingState->status(true),
        ], 200);
    }

    if ($action === 'batch_status') {
        JsonResponse::send([
            'ok' => true,
            'processing' => $processingState->status(false),
        ], 200);
    }

    if ($action === 'preflight') {
        $originalName = $filePolicy->cleanName((string)($_POST['original_name'] ?? ''));
        $filePolicy->validateName($originalName, true);
        $fileSize = (int)($_POST['file_size'] ?? 0);
        if ($fileSize < 1) {
            JsonResponse::error('invalid_size', 'Upload file size must be greater than zero.', 400);
        }
        $redirect = $filePolicy->isRedirectWrapper($originalName);
        $archive = $filePolicy->isArchive($originalName);
        if ($redirect || $archive) {
            JsonResponse::send([
                'ok' => true,
                'duplicate' => false,
                'redirect_wrapper' => $redirect,
                'archive_container' => $archive,
                'identity' => null,
                'message' => $redirect
                    ? 'Compressed redirect wrappers are not compared to package MD5/SHA-1 records. The real package identity will be calculated from the decompressed output after the complete batch uploads.'
                    : 'Archive containers are unpack-only transport files. Unreal package identities and duplicate checks will be calculated from each extracted supported file after upload.',
            ], 200);
        }

        $identity = $filePolicy->browserIdentity($_POST);
        $inspection = (new CatalogUploadDuplicateDetector($application->db, $application->config))
            ->inspectFastAdmin($fileSize, $identity['md5'], $identity['sha1']);
        $duplicate = is_array($inspection['duplicate'] ?? null) ? $inspection['duplicate'] : null;
        if ($duplicate !== null) {
            JsonResponse::send([
                'ok' => true,
                'duplicate' => true,
                'redirect_wrapper' => false,
                'archive_container' => false,
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
            'archive_container' => false,
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
        $originalName = $filePolicy->cleanName((string)($_POST['original_name'] ?? ''));
        $filePolicy->validateName($originalName, true);
        $fileSize = (int)($_POST['file_size'] ?? 0);
        if ($fileSize < 1) {
            JsonResponse::error('invalid_size', 'Chunked bucket upload file size must be greater than zero.', 400);
        }
        $redirect = $filePolicy->isRedirectWrapper($originalName);
        $archive = $filePolicy->isArchive($originalName);
        $transportContainer = $redirect || $archive;
        $identity = $transportContainer ? null : $filePolicy->browserIdentity($_POST);
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
            'archive_container' => $archive,
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
        $submittedName = $filePolicy->cleanName(basename(str_replace('\\', '/', $relativePath)));
        $filePolicy->validateName($submittedName, true);
        $redirect = $filePolicy->isRedirectWrapper($submittedName);
        $archive = $filePolicy->isArchive($submittedName);
        $identity = ($redirect || $archive) ? null : $identityStore->load($uploadId, $userId);

        JsonResponse::send([
            'ok' => true,
            'upload_id' => $uploadId,
            'upload' => $state,
            'identity' => is_array($identity)
                ? ['md5' => (string)$identity['md5'], 'sha1' => (string)$identity['sha1']]
                : null,
            'redirect_wrapper' => $redirect,
            'archive_container' => $archive,
            'messages' => [[
                'status' => 'uploaded',
                'file' => $relativePath !== '' ? $relativePath : $submittedName,
                'message' => $redirect
                    ? 'Redirect wrapper transfer completed. Its real package MD5/SHA-1 and duplicate check will run after decompression.'
                    : ($archive
                        ? 'Archive transfer completed. Supported Unreal files will be unpacked into durable child jobs after every selected file finishes uploading.'
                        : 'Transfer completed with its pre-calculated MD5 and SHA-1 retained in durable staging. Processing will begin after every selected file finishes uploading.'),
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
    $message = trim($error->getMessage()) ?: 'Unknown chunked upload error';
    error_log('[UnrealDB][' . $requestId . '] chunked bucket upload failed: ' . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error('chunk_upload_failed', $message, 500, ['request_id' => $requestId]);
}
