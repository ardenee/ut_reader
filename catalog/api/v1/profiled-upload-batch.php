<?php
/**
 * Browser profiled-upload batch ingress/finalization API.
 *
 * Per-file upload requests touch controlled filesystem staging and the append-only
 * batch manifest only. The database queue is touched once, after finalization.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogProfiledUploadBatchStore;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Settings\CatalogProgramSettingsStore;
use UnrealDb\Catalog\Infrastructure\Security\CatalogPublicAccessGuard;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

function profiled_upload_batch_name(string $value): string
{
    $value = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], trim($value)));
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';
    $value = rtrim(trim($value), ' .');
    if ($value === '' || $value === '.' || $value === '..') {
        throw new InvalidArgumentException('Uploaded filename is invalid.');
    }
    return $value;
}

/** @return list<string> */
function profiled_upload_allowed_extensions(mixed $json): array
{
    $decoded = is_array($json) ? $json : json_decode((string)$json, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    $result = [];
    foreach ($decoded as $extension) {
        $extension = strtolower(ltrim(trim((string)$extension), '.'));
        if ($extension !== '' && preg_match('/^[a-z0-9_]+$/', $extension) === 1) {
            $result[$extension] = $extension;
        }
    }
    foreach (CatalogArchiveExtractor::archiveExtensions() as $extension) {
        $result[$extension] = $extension;
    }
    $result = array_values($result);
    sort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

/** @return array{allowed:bool,is_pak:bool,is_redirect:bool,is_archive:bool,extension:string,reason:string} */
function profiled_upload_batch_file_policy(array $batch, string $originalName): array
{
    $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    $isPak = $extension === 'pak';
    $isRedirect = in_array($extension, ['uz', 'uz2', 'uz3'], true);
    $isArchive = CatalogArchiveExtractor::isArchiveName($originalName);
    $engine = strtoupper(trim((string)($batch['engine_key'] ?? '')));

    if ($isPak) {
        $allowed = in_array($engine, ['UE4', 'UE5'], true);
        return [
            'allowed' => $allowed,
            'is_pak' => true,
            'is_redirect' => false,
            'is_archive' => false,
            'extension' => $extension,
            'reason' => $allowed ? '' : 'PAK container upload requires a UE4 or UE5 target game.',
        ];
    }
    if ($isRedirect) {
        // Redirect wrappers are transport formats. The decompressed package is
        // still validated authoritatively by the background import pipeline.
        return [
            'allowed' => true,
            'is_pak' => false,
            'is_redirect' => true,
            'is_archive' => false,
            'extension' => $extension,
            'reason' => '',
        ];
    }
    if ($isArchive) {
        return [
            'allowed' => true,
            'is_pak' => false,
            'is_redirect' => false,
            'is_archive' => true,
            'extension' => $extension,
            'reason' => '',
        ];
    }

    $allowedExtensions = array_fill_keys(
        profiled_upload_allowed_extensions($batch['allowed_extensions'] ?? []),
        true
    );
    $allowed = $extension !== '' && isset($allowedExtensions[$extension]);
    return [
        'allowed' => $allowed,
        'is_pak' => false,
        'is_redirect' => false,
        'is_archive' => false,
        'extension' => $extension,
        'reason' => $allowed
            ? ''
            : 'File extension .' . ($extension !== '' ? $extension : '(none)') . ' is not allowed by the selected game profile.',
    ];
}

/** @return array{keys:list<string>,complete:bool} */
function profiled_upload_duplicate_snapshot(PDO $db, int $gameId): array
{
    // One indexed read at batch initialization replaces thousands of per-file DB
    // round trips while the browser is actively transferring data.
    $limit = 250000;
    $statement = $db->prepare(
        'SELECT CONCAT(file_size,":",LOWER(sha1)) identity_key FROM ue_files '
        . 'WHERE game_id=? AND scan_status="verified" AND sha1 IS NOT NULL AND sha1<>"" '
        . 'ORDER BY id LIMIT ' . ($limit + 1)
    );
    $statement->execute([$gameId]);
    $keys = array_values(array_filter(
        array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []),
        static fn(string $key): bool => $key !== ''
    ));
    $complete = count($keys) <= $limit;
    if (!$complete) {
        array_pop($keys);
    }
    return ['keys' => $keys, 'complete' => $complete];
}

try {
    catalog_api_require_admin(false);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('profiled_upload');

    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
    if ($userId < 1) {
        JsonResponse::error('unauthorized', 'Administrator authentication is required.', 401);
    }

    // Authentication and CSRF are complete. Never retain PHP's per-session lock
    // across file moves, manifest I/O, database work or detached-worker startup.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $config = catalog_config();
    $batchStore = new CatalogProfiledUploadBatchStore($config);
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    if (!in_array($action, ['status', 'cancel'], true)) {
        $transferDb = catalog_db($config);
        (new CatalogPublicAccessGuard($config))->transferAllowedOrThrow($transferDb, 'Upload');
    }

    if ($action === 'init') {
        $gameId = (int)($_POST['game_id'] ?? 0);
        $strictProfile = (string)($_POST['strict_profile'] ?? '1') === '1';
        $db = catalog_db($config);
        $game = $gameId > 0 ? catalog_one(
            $db,
            'SELECT g.id,g.name,COALESCE(p.engine_key,"") engine_key,p.allowed_extensions_json '
            . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=?',
            [$gameId]
        ) : null;
        if (!$game || trim((string)($game['engine_key'] ?? '')) === '') {
            JsonResponse::error('invalid_game', 'Choose a target game with an active profile.', 400);
        }

        $allowedExtensions = profiled_upload_allowed_extensions($game['allowed_extensions_json'] ?? '[]');
        $limits = (new CatalogProgramSettingsStore($db, $config))->uploadLimits();
        $snapshot = profiled_upload_duplicate_snapshot($db, $gameId);
        $batch = $batchStore->create(
            $userId,
            $gameId,
            $strictProfile,
            (string)$game['engine_key'],
            $allowedExtensions,
            (int)$limits['normal_upload_limit_bytes'],
            (int)$limits['container_upload_limit_bytes']
        );
        JsonResponse::send([
            'ok' => true,
            'batch' => $batch,
            'duplicate_keys' => $snapshot['keys'],
            'duplicate_snapshot_complete' => $snapshot['complete'],
            'message' => 'Upload batch initialized without creating background import jobs.',
        ], 201);
    }

    if ($action === 'stage') {
        $batchId = (string)($_POST['batch_id'] ?? '');
        $batch = $batchStore->info($batchId, $userId);
        if ((string)($batch['status'] ?? '') !== 'uploading') {
            JsonResponse::error('batch_closed', 'Upload batch is no longer accepting files.', 409);
        }

        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            JsonResponse::error('file_missing', 'Uploaded file is missing.', 400);
        }
        $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            JsonResponse::error('upload_error', 'PHP upload error ' . $errorCode . '.', 400);
        }
        $temporaryPath = (string)($file['tmp_name'] ?? '');
        if ($temporaryPath === '' || !is_file($temporaryPath)) {
            JsonResponse::error('file_missing', 'Uploaded temporary file is unavailable.', 400);
        }

        $originalName = profiled_upload_batch_name((string)($_POST['original_name'] ?? $file['name'] ?? 'upload.bin'));
        $policy = profiled_upload_batch_file_policy($batch, $originalName);
        if (!$policy['allowed']) {
            JsonResponse::error('invalid_extension', $policy['reason'], 415);
        }
        $sourceRelativePath = trim(str_replace(["\0", '\\'], ['', '/'], (string)($_POST['relative_path'] ?? $originalName)), '/');
        $size = filesize($temporaryPath);
        $normalLimit = max(1, (int)($batch['normal_upload_limit_bytes'] ?? ($config['max_upload_bytes'] ?? 0)));
        $containerLimit = max(
            $normalLimit,
            (int)($batch['container_upload_limit_bytes'] ?? ($config['max_container_upload_bytes'] ?? 0))
        );
        $limit = ($policy['is_pak'] || $policy['is_archive']) ? $containerLimit : $normalLimit;
        if ($size === false || $size < 1 || (int)$size > $limit) {
            JsonResponse::error(
                'file_too_large',
                'File size is outside the configured upload limit of ' . catalog_bytes($limit) . '.',
                413
            );
        }

        $incoming = new CatalogIncomingFileStore($config);
        $staged = $incoming->stageUploadedFile($temporaryPath, $originalName, false);
        try {
            $item = $batchStore->append($userId, $batchId, [
                // Archive type is inferred from original_name by the background
                // batch coordinator, preserving the existing manifest contract.
                'kind' => $policy['is_pak'] ? 'pak' : 'package',
                'staged_path' => (string)$staged['relative_path'],
                'original_name' => $originalName,
                'source_relative_path' => $sourceRelativePath,
                'size' => (int)$staged['size'],
                'game_id' => (int)$batch['game_id'],
            ]);
        } catch (Throwable $error) {
            $incoming->delete((string)$staged['relative_path']);
            throw $error;
        }

        JsonResponse::send([
            'ok' => true,
            'staged' => $item,
            'background_job_created' => false,
        ], 201);
    }

    if ($action === 'finalize') {
        $batchId = (string)($_POST['batch_id'] ?? '');
        $batch = $batchStore->finalize($userId, $batchId);
        $itemCount = max(0, (int)($batch['item_count'] ?? 0));
        if ($itemCount === 0) {
            JsonResponse::send([
                'ok' => true,
                'batch' => $batch,
                'job_id' => 0,
                'worker_error' => '',
                'message' => 'Upload batch completed with no files requiring background import.',
            ]);
        }

        $db = catalog_db($config);
        $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $jobId = (new PdoJobQueue($db))->enqueue(
            $queueName,
            JobType::PROFILED_UPLOAD_BATCH,
            [
                'batch_id' => $batchId,
                'game_id' => (int)$batch['game_id'],
                'user_id' => $userId,
                'item_count' => $itemCount,
                'byte_count' => max(0, (int)($batch['byte_count'] ?? 0)),
            ],
            4,
            null,
            'profiled-upload-batch:' . $batchId,
            $userId,
            3
        );

        $workerError = '';
        $worker = null;
        try {
            $state = (new CatalogQueueWorkerStarter($db, $config))->start($queueName, true, $userId);
            $workerError = trim((string)($state['worker_error'] ?? ''));
            $worker = is_array($state['worker'] ?? null) ? $state['worker'] : null;
        } catch (Throwable $error) {
            $workerError = trim($error->getMessage());
            error_log('[UnrealDB profiled upload batch worker launch] ' . $error->getMessage());
        }

        JsonResponse::send([
            'ok' => true,
            'batch' => $batch,
            'job_id' => $jobId,
            'worker' => $worker,
            'worker_error' => $workerError,
            'message' => 'Upload complete; background batch processing has started.',
        ], 202);
    }

    if ($action === 'cancel') {
        $batch = $batchStore->cancel($userId, (string)($_POST['batch_id'] ?? ''));
        JsonResponse::send([
            'ok' => true,
            'batch' => $batch,
            'background_job_created' => false,
            'message' => 'Upload batch cancelled; no background imports were started.',
        ]);
    }

    JsonResponse::error('invalid_action', 'Batch action must be init, stage, finalize or cancel.', 400);
} catch (InvalidArgumentException $error) {
    JsonResponse::error('invalid_upload_batch', $error->getMessage(), 400);
} catch (Throwable $error) {
    error_log('[UnrealDB profiled upload batch][' . catalog_request_id() . '] ' . $error->getMessage());
    JsonResponse::error('upload_batch_failed', $error->getMessage(), 500);
}
