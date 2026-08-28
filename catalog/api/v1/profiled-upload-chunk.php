<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for profiled upload chunk.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; reusable work should be delegated to shared application/services rather than duplicated.
 * Audit: Active API surface unless its callers/tests prove otherwise; preserve request/response compatibility when
 *        consolidating.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogProfiledUploadBatchStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogProfiledUploadQueue;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Settings\CatalogProgramSettingsStore;
use UnrealDb\Catalog\Infrastructure\Security\CatalogPublicAccessGuard;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

function profiled_chunk_original_name_from_state(array $state): string
{
    $relativePath = trim(str_replace('\\', '/', (string)($state['relative_path'] ?? '')), '/');
    $name = basename($relativePath);
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
    $name = rtrim(trim($name), ' .');
    if ($name === '' || $name === '.' || $name === '..') {
        throw new RuntimeException('Completed chunked upload has no usable original filename.');
    }
    return $name;
}

/** @return list<string> */
function profiled_chunk_extensions(mixed $raw): array
{
    $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    $extensions = [];
    foreach ($decoded as $extension) {
        $extension = strtolower(ltrim(trim((string)$extension), '.'));
        if ($extension !== '' && preg_match('/^[a-z0-9_]+$/', $extension) === 1) {
            $extensions[$extension] = $extension;
        }
    }
    foreach (CatalogArchiveExtractor::archiveExtensions() as $extension) {
        $extensions[$extension] = $extension;
    }
    return array_values($extensions);
}

/** @return array{allowed:bool,is_pak:bool,is_redirect:bool,is_archive:bool,reason:string} */
function profiled_chunk_file_policy(string $originalName, string $engineKey, mixed $allowedExtensions): array
{
    $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    $isPak = $extension === 'pak';
    $isRedirect = in_array($extension, ['uz', 'uz2', 'uz3'], true);
    $isArchive = CatalogArchiveExtractor::isArchiveName($originalName);
    $engineKey = strtoupper(trim($engineKey));
    if ($isPak) {
        $allowed = in_array($engineKey, ['UE4', 'UE5'], true);
        return [
            'allowed' => $allowed,
            'is_pak' => true,
            'is_redirect' => false,
            'is_archive' => false,
            'reason' => $allowed ? '' : 'PAK container upload requires a UE4 or UE5 target game.',
        ];
    }
    if ($isRedirect) {
        return [
            'allowed' => true,
            'is_pak' => false,
            'is_redirect' => true,
            'is_archive' => false,
            'reason' => '',
        ];
    }
    if ($isArchive) {
        return [
            'allowed' => true,
            'is_pak' => false,
            'is_redirect' => false,
            'is_archive' => true,
            'reason' => '',
        ];
    }
    $allowed = in_array($extension, profiled_chunk_extensions($allowedExtensions), true);
    return [
        'allowed' => $allowed,
        'is_pak' => false,
        'is_redirect' => false,
        'is_archive' => false,
        'reason' => $allowed
            ? ''
            : 'File extension .' . ($extension !== '' ? $extension : '(none)') . ' is not allowed by the selected game profile.',
    ];
}

try {
    catalog_api_require_admin(false);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('profiled_upload_chunk');

    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
    if ($userId < 1) {
        JsonResponse::error('unauthorized', 'Administrator authentication is required.', 401);
    }

    // Chunk writes and assembly can be slow. Authentication/CSRF are complete,
    // so release PHP's per-session lock before any file or database operation.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $config = catalog_config();
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    if (!in_array($action, ['status', 'cancel'], true)) {
        $transferDb = catalog_db($config);
        (new CatalogPublicAccessGuard($config))->transferAllowedOrThrow($transferDb, 'Upload');
    }
    $batchId = strtolower(trim((string)($_POST['batch_id'] ?? '')));
    $batchStore = new CatalogProfiledUploadBatchStore($config);
    if ($batchId !== '' && in_array($action, ['init', 'chunk', 'complete'], true)) {
        $batchStore->touch($userId, $batchId);
    }
    $store = new CatalogChunkedUploadStore($config);

    if ($action === 'init') {
        $gameId = (int)($_POST['game_id'] ?? 0);
        $originalName = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], trim((string)($_POST['original_name'] ?? ''))));
        $originalName = rtrim(trim($originalName), ' .');
        if ($originalName === '') {
            JsonResponse::error('invalid_name', 'Chunked upload filename is missing.', 400);
        }

        $engineKey = '';
        $allowedExtensions = [];
        $normalLimit = max(1, (int)($config['max_upload_bytes'] ?? 0));
        $containerLimit = max($normalLimit, (int)($config['max_container_upload_bytes'] ?? 0));
        if ($batchId !== '') {
            $batch = $batchStore->info($batchId, $userId);
            if ((string)($batch['status'] ?? '') !== 'uploading' || (int)($batch['game_id'] ?? 0) !== $gameId) {
                JsonResponse::error('invalid_batch', 'Chunked upload does not match the active upload batch.', 409);
            }
            $engineKey = (string)($batch['engine_key'] ?? '');
            $allowedExtensions = $batch['allowed_extensions'] ?? [];
            $normalLimit = max(1, (int)($batch['normal_upload_limit_bytes'] ?? $normalLimit));
            $containerLimit = max(
                $normalLimit,
                (int)($batch['container_upload_limit_bytes'] ?? $containerLimit)
            );
        } else {
            $db = catalog_db($config);
            $game = $gameId > 0 ? catalog_one(
                $db,
                'SELECT g.id,p.engine_key,p.allowed_extensions_json FROM ue_games g '
                . 'JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=?',
                [$gameId]
            ) : null;
            if (!$game) {
                JsonResponse::error('invalid_game', 'Chunked upload requires a target game with an active profile.', 400);
            }
            $engineKey = (string)($game['engine_key'] ?? '');
            $allowedExtensions = $game['allowed_extensions_json'] ?? '[]';
            $limits = (new CatalogProgramSettingsStore($db, $config))->uploadLimits();
            $normalLimit = (int)$limits['normal_upload_limit_bytes'];
            $containerLimit = (int)$limits['container_upload_limit_bytes'];
        }

        $policy = profiled_chunk_file_policy($originalName, $engineKey, $allowedExtensions);
        if (!$policy['allowed']) {
            JsonResponse::error($policy['is_pak'] ? 'invalid_game' : 'invalid_extension', $policy['reason'], $policy['is_pak'] ? 400 : 415);
        }

        $fileSize = (int)($_POST['file_size'] ?? 0);
        $container = $policy['is_pak'] || $policy['is_archive'];
        $limit = $container ? $containerLimit : $normalLimit;
        if ($fileSize < 1 || $fileSize > $limit) {
            JsonResponse::error(
                'file_too_large',
                'File exceeds the configured ' . ($container ? 'container' : 'normal upload')
                    . ' limit of ' . catalog_bytes($limit) . '.',
                413
            );
        }

        // Stale chunk cleanup is maintenance work. Never recursively scan/prune
        // upload storage from a live file-init request.
        $storageName = $policy['is_pak']
            ? $originalName
            : 'package-' . substr(hash('sha256', $originalName), 0, 24) . '.pak';
        $relativePath = trim((string)($_POST['relative_path'] ?? ''));
        if ($relativePath === '') {
            $relativePath = $originalName;
        }

        $state = $store->initialize(
            $userId,
            (string)($_POST['client_key'] ?? ''),
            $storageName,
            $relativePath,
            $fileSize,
            $gameId,
            (string)($_POST['strict_profile'] ?? '1') === '1'
        );
        $state['logical_original_name'] = $originalName;
        $state['upload_kind'] = $policy['is_pak'] ? 'pak' : ($policy['is_archive'] ? 'archive' : 'package');
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
        $uploadId = (string)($_POST['upload_id'] ?? '');
        $state = $store->complete($userId, $uploadId);
        $originalName = profiled_chunk_original_name_from_state($state);
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        $isPak = $extension === 'pak';

        if ($batchId !== '') {
            $batch = $batchStore->info($batchId, $userId);
            if ((string)($batch['status'] ?? '') !== 'uploading'
                || (int)($batch['game_id'] ?? 0) !== (int)($state['game_id'] ?? 0)) {
                JsonResponse::error('invalid_batch', 'Completed chunk does not match the active upload batch.', 409);
            }
            $item = $batchStore->append($userId, $batchId, [
                // Archive type is inferred from original_name by the batch
                // coordinator, preserving the established manifest kinds.
                'kind' => $isPak ? 'pak' : 'package',
                'staged_path' => 'chunk-upload:' . $uploadId,
                'original_name' => $originalName,
                'source_relative_path' => (string)$state['relative_path'],
                'size' => (int)$state['file_size'],
                'game_id' => (int)$state['game_id'],
            ]);
            JsonResponse::send([
                'ok' => true,
                'staged' => $item,
                'upload' => $state,
                'background_job_created' => false,
            ], 201);
        }

        // Compatibility path for older/non-batch clients.
        $deferWorkerStart = (string)($_POST['defer_worker_start'] ?? '0') === '1';
        $db = catalog_db($config);
        $config = (new CatalogProgramSettingsStore($db, $config))->applyUploadLimits($config);
        $queue = new CatalogProfiledUploadQueue($db, $config);
        if ($isPak) {
            $job = $queue->enqueueChunkedPak(
                (int)$state['game_id'],
                $uploadId,
                $state + ['original_name' => $originalName],
                (bool)$state['strict_profile'],
                $userId,
                $deferWorkerStart
            );
        } else {
            $job = $queue->enqueueStaged(
                (int)$state['game_id'],
                [
                    'relative_path' => 'chunk-upload:' . $uploadId,
                    'size' => (int)$state['file_size'],
                ],
                $originalName,
                (string)$state['relative_path'],
                (bool)$state['strict_profile'],
                $userId,
                $deferWorkerStart
            );
        }

        $worker = null;
        $workerError = '';
        if (!$deferWorkerStart) {
            try {
                $worker = (new CatalogDetachedWorker($config))->start(
                    (string)($config['queue']['name'] ?? 'catalog'),
                    10000
                );
            } catch (Throwable $error) {
                $workerError = trim($error->getMessage());
                error_log('[UnrealDB chunked profiled upload worker launch] ' . $error->getMessage());
            }
        }
        JsonResponse::send([
            'ok' => true,
            'jobs' => [$job],
            'upload' => $state,
            'worker' => $worker,
            'worker_error' => $workerError,
            'worker_deferred' => $deferWorkerStart,
        ], 202);
    }

    if ($action === 'cancel') {
        $store->cancel($userId, (string)($_POST['upload_id'] ?? ''));
        JsonResponse::send(['ok' => true, 'status' => 'cancelled'], 200);
    }

    JsonResponse::error('invalid_action', 'Chunked upload action must be init, chunk, complete or cancel.', 400);
} catch (InvalidArgumentException $error) {
    JsonResponse::error('invalid_chunk_upload', $error->getMessage(), 400);
} catch (Throwable $error) {
    error_log('[UnrealDB chunked profiled upload] ' . $error->getMessage());
    JsonResponse::error('chunk_upload_failed', $error->getMessage(), 500);
}
