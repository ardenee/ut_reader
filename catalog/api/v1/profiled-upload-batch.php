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
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogProfiledUploadBatchStore;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
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

    if ($action === 'init') {
        $gameId = (int)($_POST['game_id'] ?? 0);
        $strictProfile = (string)($_POST['strict_profile'] ?? '1') === '1';
        $db = catalog_db($config);
        $game = $gameId > 0 ? catalog_one(
            $db,
            'SELECT g.id,g.name,COALESCE(p.engine_key,"") engine_key '
            . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=?',
            [$gameId]
        ) : null;
        if (!$game || trim((string)($game['engine_key'] ?? '')) === '') {
            JsonResponse::error('invalid_game', 'Choose a target game with an active profile.', 400);
        }
        $snapshot = profiled_upload_duplicate_snapshot($db, $gameId);
        $batch = $batchStore->create($userId, $gameId, $strictProfile, (string)$game['engine_key']);
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
        $sourceRelativePath = trim(str_replace(["\0", '\\'], ['', '/'], (string)($_POST['relative_path'] ?? $originalName)), '/');
        $size = filesize($temporaryPath);
        $isPak = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) === 'pak';
        $normalLimit = max(1, (int)($config['max_upload_bytes'] ?? 0));
        $containerLimit = max(
            $normalLimit,
            (int)($config['max_container_upload_bytes'] ?? (64 * 1024 * 1024 * 1024))
        );
        $limit = $isPak ? $containerLimit : $normalLimit;
        if ($size === false || $size < 1 || (int)$size > $limit) {
            JsonResponse::error(
                'file_too_large',
                'File size is outside the configured upload limit of ' . catalog_bytes($limit) . '.',
                413
            );
        }
        if ($isPak && preg_match('/^UE[45]/i', (string)($batch['engine_key'] ?? '')) !== 1) {
            JsonResponse::error('invalid_game', 'PAK container upload requires a UE4 or UE5 target game.', 400);
        }

        $incoming = new CatalogIncomingFileStore($config);
        $staged = $incoming->stageUploadedFile($temporaryPath, $originalName, false);
        try {
            $item = $batchStore->append($userId, $batchId, [
                'kind' => $isPak ? 'pak' : 'package',
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
