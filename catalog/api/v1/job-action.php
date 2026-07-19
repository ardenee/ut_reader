<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('job_action');

    $payload = catalog_api_json_body();
    $action = strtolower(trim((string)($payload['action'] ?? '')));
    $queue = new PdoJobQueue($application->db);
    $queueName = trim((string)($payload['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
    if ($queueName === '' || strlen($queueName) > 80) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if ($action === 'cancel') {
        $jobId = (int)($payload['job_id'] ?? 0);
        if ($jobId < 1) {
            JsonResponse::error('invalid_job', 'A positive job_id is required.', 400);
        }
        $status = $queue->requestCancellation($jobId, $userId, (string)($payload['reason'] ?? 'Cancelled by administrator.'));
        if ($status === 'not_found') {
            JsonResponse::error('not_found', 'The requested job was not found.', 404);
        }
        JsonResponse::send(['data' => ['job_id' => $jobId, 'status' => $status]]);
    }

    if ($action === 'retry') {
        $jobId = (int)($payload['job_id'] ?? 0);
        if ($jobId < 1) {
            JsonResponse::error('invalid_job', 'A positive job_id is required.', 400);
        }
        if (!$queue->retryDeadLetter($jobId)) {
            JsonResponse::error('not_retryable', 'The job is not in a retryable terminal state.', 409);
        }
        JsonResponse::send(['data' => ['job_id' => $jobId, 'status' => 'queued']]);
    }

    if ($action === 'recover') {
        $result = $queue->recoverExpiredLeases($queueName);
        JsonResponse::send(['data' => ['queue' => $queueName] + $result]);
    }

    if ($action === 'enqueue_rebuild_game') {
        $gameId = (int)($payload['game_id'] ?? 0);
        $offset = max(0, min((int)($payload['offset'] ?? 0), 2000000000));
        if ($gameId < 1 || !catalog_one($application->db, 'SELECT id FROM ue_games WHERE id=?', [$gameId])) {
            JsonResponse::error('invalid_game', 'A valid game_id is required.', 400);
        }
        $jobId = $queue->enqueue(
            $queueName,
            JobType::REBUILD_GAME_DEPENDENCIES,
            ['game_id' => $gameId, 'offset' => $offset],
            20,
            null,
            'rebuild-game:' . $gameId . ':offset:' . $offset,
            $userId,
            3
        );
        JsonResponse::send([
            'data' => [
                'job_id' => $jobId,
                'status' => 'queued',
                'type' => JobType::REBUILD_GAME_DEPENDENCIES,
                'offset' => $offset,
            ],
        ], 202);
    }

    if ($action === 'enqueue_rebuild_file' || $action === 'enqueue_rebuild_affected') {
        $fileId = (int)($payload['file_id'] ?? 0);
        $file = $fileId > 0
            ? catalog_one($application->db, 'SELECT id FROM ue_files WHERE id=? AND scan_status="verified"', [$fileId])
            : null;
        if (!$file) {
            JsonResponse::error('invalid_file', 'A valid verified file_id is required.', 400);
        }

        $affected = $action === 'enqueue_rebuild_affected';
        $type = $affected ? JobType::REBUILD_AFFECTED_DEPENDENCIES : JobType::REBUILD_FILE_DEPENDENCIES;
        $dedupeKey = ($affected ? 'rebuild-affected-file:' : 'rebuild-file:') . $fileId;
        $jobId = $queue->enqueue(
            $queueName,
            $type,
            ['file_id' => $fileId],
            40,
            null,
            $dedupeKey,
            $userId,
            3
        );
        JsonResponse::send(['data' => ['job_id' => $jobId, 'status' => 'queued', 'type' => $type]], 202);
    }

    if ($action === 'enqueue_source_identity_file') {
        $fileId = (int)($payload['file_id'] ?? 0);
        $file = $fileId > 0
            ? catalog_one(
                $application->db,
                'SELECT f.id,UPPER(COALESCE(NULLIF(f.detected_engine_key,""),p.engine_key,"")) engine_key '
                . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id '
                . 'WHERE f.id=? AND f.scan_status="verified"',
                [$fileId]
            )
            : null;
        if (!$file) {
            JsonResponse::error('invalid_file', 'A valid verified file_id is required.', 400);
        }
        if (!in_array((string)$file['engine_key'], ['UE4', 'UE5'], true)) {
            JsonResponse::error('unsupported_engine', 'Mounted source identity repair is only available for UE4/UE5 files.', 409);
        }

        $jobId = $queue->enqueue(
            $queueName,
            JobType::REPAIR_SOURCE_IDENTITY_FILE,
            ['file_id' => $fileId],
            10,
            null,
            'source-identity-file:' . $fileId,
            $userId,
            3
        );
        JsonResponse::send(['data' => ['job_id' => $jobId, 'status' => 'queued', 'type' => JobType::REPAIR_SOURCE_IDENTITY_FILE]], 202);
    }

    if ($action === 'enqueue_source_identity_game') {
        $gameId = (int)($payload['game_id'] ?? 0);
        $game = $gameId > 0
            ? catalog_one(
                $application->db,
                'SELECT g.id,UPPER(COALESCE(p.engine_key,"")) engine_key '
                . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id WHERE g.id=?',
                [$gameId]
            )
            : null;
        if (!$game) {
            JsonResponse::error('invalid_game', 'A valid game_id is required.', 400);
        }
        if (!in_array((string)$game['engine_key'], ['UE4', 'UE5'], true)) {
            JsonResponse::error('unsupported_engine', 'Mounted source identity repair is only available for UE4/UE5 games.', 409);
        }

        $jobId = $queue->enqueue(
            $queueName,
            JobType::REPAIR_SOURCE_IDENTITY_GAME,
            ['game_id' => $gameId],
            10,
            null,
            'source-identity-game:' . $gameId,
            $userId,
            3
        );
        JsonResponse::send(['data' => ['job_id' => $jobId, 'status' => 'queued', 'type' => JobType::REPAIR_SOURCE_IDENTITY_GAME]], 202);
    }

    if ($action === 'enqueue_prune') {
        $maxAge = max(60, min((int)($payload['max_age_seconds'] ?? 86400), 604800));
        $jobId = $queue->enqueue(
            $queueName,
            JobType::PRUNE_UPLOAD_PROGRESS,
            ['max_age_seconds' => $maxAge],
            200,
            null,
            'prune-upload-progress',
            $userId,
            2
        );
        JsonResponse::send(['data' => ['job_id' => $jobId, 'status' => 'queued', 'type' => JobType::PRUNE_UPLOAD_PROGRESS]], 202);
    }

    JsonResponse::error(
        'invalid_action',
        'Supported actions are cancel, retry, recover, enqueue_rebuild_game, enqueue_rebuild_file, enqueue_rebuild_affected, enqueue_source_identity_file, enqueue_source_identity_game and enqueue_prune.',
        400
    );
} catch (Throwable $exception) {
    error_log('[UnrealDB job action API] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'The jobs service is temporarily unavailable.', 503);
}
