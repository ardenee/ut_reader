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
        if ($gameId < 1 || !catalog_one($application->db, 'SELECT id FROM ue_games WHERE id=?', [$gameId])) {
            JsonResponse::error('invalid_game', 'A valid game_id is required.', 400);
        }
        $jobId = $queue->enqueue(
            $queueName,
            JobType::REBUILD_GAME_DEPENDENCIES,
            ['game_id' => $gameId],
            20,
            null,
            'rebuild-game:' . $gameId,
            $userId,
            3
        );
        JsonResponse::send(['data' => ['job_id' => $jobId, 'status' => 'queued', 'type' => JobType::REBUILD_GAME_DEPENDENCIES]], 202);
    }

    if ($action === 'enqueue_rebuild_file') {
        $fileId = (int)($payload['file_id'] ?? 0);
        $file = $fileId > 0
            ? catalog_one($application->db, 'SELECT id FROM ue_files WHERE id=? AND scan_status="verified"', [$fileId])
            : null;
        if (!$file) {
            JsonResponse::error('invalid_file', 'A valid verified file_id is required.', 400);
        }
        $jobId = $queue->enqueue(
            $queueName,
            JobType::REBUILD_AFFECTED_DEPENDENCIES,
            ['file_id' => $fileId],
            40,
            null,
            'rebuild-file:' . $fileId,
            $userId,
            3
        );
        JsonResponse::send(['data' => ['job_id' => $jobId, 'status' => 'queued', 'type' => JobType::REBUILD_AFFECTED_DEPENDENCIES]], 202);
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
        'Supported actions are cancel, retry, recover, enqueue_rebuild_game, enqueue_rebuild_file and enqueue_prune.',
        400
    );
} catch (Throwable $exception) {
    error_log('[UnrealDB job action API] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'The jobs service is temporarily unavailable.', 503);
}
