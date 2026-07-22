<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadCleanup;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogProfiledUploadQueue;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('profiled_upload_chunk');

    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
    if ($userId < 1) {
        JsonResponse::error('unauthorized', 'Administrator authentication is required.', 401);
    }
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $store = new CatalogChunkedUploadStore($application->config);

    if ($action === 'init') {
        // Clear abandoned browser uploads before accepting another large container.
        (new CatalogChunkedUploadCleanup($application->config))->pruneIncomplete();
        $gameId = (int)($_POST['game_id'] ?? 0);
        $game = $gameId > 0 ? catalog_one(
            $application->db,
            'SELECT g.id,p.engine_key FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=?',
            [$gameId]
        ) : null;
        if (!$game || preg_match('/^UE[45]/i', trim((string)($game['engine_key'] ?? ''))) !== 1) {
            JsonResponse::error('invalid_game', 'Chunked PAK upload requires a UE4 or UE5 target game.', 400);
        }
        $state = $store->initialize(
            $userId,
            (string)($_POST['client_key'] ?? ''),
            (string)($_POST['original_name'] ?? ''),
            (string)($_POST['relative_path'] ?? ''),
            (int)($_POST['file_size'] ?? 0),
            $gameId,
            (string)($_POST['strict_profile'] ?? '1') === '1'
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
        $uploadId = (string)($_POST['upload_id'] ?? '');
        $state = $store->complete($userId, $uploadId);
        $queue = new CatalogProfiledUploadQueue($application->db, $application->config);
        $job = $queue->enqueueChunkedPak(
            (int)$state['game_id'],
            $uploadId,
            $state,
            (bool)$state['strict_profile'],
            $userId
        );
        $worker = null;
        $workerError = '';
        try {
            $worker = (new CatalogDetachedWorker($application->config))->start(
                (string)($application->config['queue']['name'] ?? 'catalog'),
                10000
            );
        } catch (Throwable $error) {
            $workerError = trim($error->getMessage());
            error_log('[UnrealDB chunked PAK worker launch] ' . $error->getMessage());
        }
        JsonResponse::send([
            'ok' => true,
            'jobs' => [$job],
            'upload' => $state,
            'worker' => $worker,
            'worker_error' => $workerError,
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
    error_log('[UnrealDB chunked PAK upload] ' . $error->getMessage());
    JsonResponse::error('chunk_upload_failed', $error->getMessage(), 500);
}
