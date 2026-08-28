<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for job actions.
 * Why: Transport validation remains here while queue persistence, cleanup, worker stopping and recovery are delegated.
 * Role: HTTP API entry point preserving established Background Jobs action contracts.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobCleanup;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobHistoryCleanupQueue;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorkerStop;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogManualJobRecovery;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobBulkAction;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    // Queue controls are routine administrator operations. A valid logged-in
    // administrator session plus CSRF protection is sufficient; forcing recent
    // password/MFA reauthentication can interrupt the worker being controlled.
    catalog_api_require_admin(false);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('job_action');

    $payload = catalog_api_json_body();
    $action = strtolower(trim((string)($payload['action'] ?? '')));
    $queue = new PdoJobQueue($application->db);
    $queueName = trim((string)($payload['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
    if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if ($action === 'cancel') {
        $jobId = (int)($payload['job_id'] ?? 0);
        if ($jobId < 1) {
            JsonResponse::error('invalid_job', 'A positive job_id is required.', 400);
        }
        $result = (new CatalogDetachedWorkerStop($application->db, $application->config))->stopJob(
            $jobId,
            $userId,
            (string)($payload['reason'] ?? 'Cancelled by administrator.')
        );
        $status = (string)($result['status'] ?? 'not_found');
        if ($status === 'not_found') {
            JsonResponse::error('not_found', 'The requested job was not found.', 404);
        }
        JsonResponse::send([
            'data' => [
                'job_id' => $jobId,
                'status' => $status,
                'worker_terminated' => !empty($result['terminated']),
                'worker_inactive' => !empty($result['worker_inactive']),
                'worker_pid' => (int)($result['pid'] ?? 0),
            ],
        ]);
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

    // Deleting one terminal row is bounded and remains immediate. Potentially
    // large selected/matching/retention cleanup requests below are snapshotted
    // into catalog.clean_background_job_history instead.
    if ($action === 'delete') {
        $jobId = (int)($payload['job_id'] ?? 0);
        if ($jobId < 1) {
            JsonResponse::error('invalid_job', 'A positive job_id is required.', 400);
        }
        $result = (new CatalogBackgroundJobCleanup($application->db, $application->config))
            ->deleteTerminalJob($jobId);
        if ((int)$result['deleted_jobs'] !== 1) {
            JsonResponse::error('not_deletable', 'Only completed, failed, dead-letter or cancelled jobs can be deleted.', 409);
        }
        JsonResponse::send(['data' => ['job_id' => $jobId] + $result]);
    }

    if ($action === 'delete_selected') {
        $jobIds = $payload['job_ids'] ?? [];
        if (!is_array($jobIds) || $jobIds === []) {
            JsonResponse::error('invalid_jobs', 'Select at least one terminal job to delete.', 400);
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $jobIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === [] || count($ids) > 10000) {
            JsonResponse::error('invalid_jobs', 'Select between 1 and 10,000 terminal jobs.', 400);
        }
        $result = (new PdoBackgroundJobBulkAction($application->db, $application->config))->execute(
            'delete',
            'selected',
            $queueName,
            '',
            '',
            $ids,
            $userId
        );
        if ((int)($result['cleanup_job_id'] ?? 0) < 1) {
            JsonResponse::error('not_deletable', 'None of the selected jobs are terminal jobs in this queue.', 409);
        }
        $worker = (new CatalogQueueWorkerStarter($application->db, $application->config))->start($queueName, true, $userId);
        $result['worker'] = $worker['worker'];
        $result['worker_error'] = (string)$worker['worker_error'];
        JsonResponse::send(['data' => $result], 202);
    }

    if ($action === 'delete_matching') {
        $status = strtolower(trim((string)($payload['status'] ?? '')));
        if ($status !== '' && !in_array($status, ['completed', 'failed', 'dead_letter', 'cancelled'], true)) {
            JsonResponse::error('invalid_status', 'Bulk deletion is available only for terminal job statuses.', 400);
        }
        $result = (new PdoBackgroundJobBulkAction($application->db, $application->config))->execute(
            'delete',
            'matching',
            $queueName,
            $status,
            trim((string)($payload['search'] ?? '')),
            [],
            $userId
        );
        if ((int)($result['cleanup_job_id'] ?? 0) > 0) {
            $worker = (new CatalogQueueWorkerStarter($application->db, $application->config))->start($queueName, true, $userId);
            $result['worker'] = $worker['worker'];
            $result['worker_error'] = (string)$worker['worker_error'];
        }
        JsonResponse::send(['data' => $result], 202);
    }

    if ($action === 'cleanup') {
        $retentionDays = max(1, min((int)($payload['retention_days'] ?? 30), 3650));
        $cleanupQueue = new CatalogBackgroundJobHistoryCleanupQueue($application->db, $application->config);
        $snapshot = $cleanupQueue->snapshotOlderThan($queueName, $retentionDays);
        $queued = $cleanupQueue->enqueueSnapshot(
            $queueName,
            $snapshot['ids'],
            $snapshot['requested'],
            $snapshot['limited'],
            $userId,
            'Clean terminal jobs older than ' . $retentionDays . ' day(s)',
            $snapshot['cutoff']
        );
        $worker = (new CatalogQueueWorkerStarter($application->db, $application->config))->start($queueName, true, $userId);
        JsonResponse::send([
            'data' => [
                'queue' => $queueName,
                'retention_days' => $retentionDays,
                'cutoff' => $snapshot['cutoff'],
                'cleanup_job_id' => $queued['job_id'],
                'scheduled' => $queued['scheduled'],
                'requested' => $queued['requested'],
                'limited' => $queued['limited'],
                'auto_continue' => true,
                'worker' => $worker['worker'],
                'worker_error' => (string)$worker['worker_error'],
            ],
        ], 202);
    }

    if ($action === 'recover') {
        JsonResponse::send([
            'data' => (new CatalogManualJobRecovery($application->db, $application->config))->recover($queueName),
        ]);
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

    if ($action === 'enqueue_reconcile_unverified') {
        $maxFiles = max(1, min((int)($payload['max_files'] ?? 1000), 10000));
        $jobId = $queue->enqueue(
            $queueName,
            JobType::RECONCILE_UNVERIFIED_STORAGE,
            ['max_files' => $maxFiles],
            25,
            null,
            'reconcile-unverified-storage',
            $userId,
            3
        );
        JsonResponse::send([
            'data' => [
                'job_id' => $jobId,
                'status' => 'queued',
                'type' => JobType::RECONCILE_UNVERIFIED_STORAGE,
                'max_files' => $maxFiles,
            ],
        ], 202);
    }

    if ($action === 'cleanup_storage') {
        $minimumAge = max(60, min((int)($payload['minimum_age_seconds'] ?? 60), 30 * 86400));
        $jobId = $queue->enqueue(
            $queueName,
            JobType::PRUNE_STALE_ARTIFACTS,
            [
                'storage_only' => true,
                'orphan_min_age_seconds' => $minimumAge,
            ],
            20,
            null,
            'prune-job-storage',
            $userId,
            2
        );
        $worker = (new CatalogQueueWorkerStarter($application->db, $application->config))
            ->start($queueName, true, $userId);
        $storageRoot = rtrim((string)($application->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'jobs';
        JsonResponse::send([
            'data' => [
                'job_id' => $jobId,
                'status' => 'queued',
                'type' => JobType::PRUNE_STALE_ARTIFACTS,
                'storage_only' => true,
                'minimum_age_seconds' => $minimumAge,
                'job_storage_root' => $storageRoot,
                'worker' => $worker['worker'],
                'worker_error' => (string)$worker['worker_error'],
            ],
        ], 202);
    }

    if ($action === 'enqueue_prune_artifacts') {
        $maxAge = max(3600, min((int)($payload['incoming_max_age_seconds'] ?? 172800), 30 * 86400));
        $jobId = $queue->enqueue(
            $queueName,
            JobType::PRUNE_STALE_ARTIFACTS,
            ['incoming_max_age_seconds' => $maxAge],
            200,
            null,
            'prune-stale-artifacts',
            $userId,
            2
        );
        JsonResponse::send([
            'data' => [
                'job_id' => $jobId,
                'status' => 'queued',
                'type' => JobType::PRUNE_STALE_ARTIFACTS,
                'incoming_max_age_seconds' => $maxAge,
            ],
        ], 202);
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
        'Supported actions are cancel, retry, delete, delete_selected, delete_matching, cleanup, cleanup_storage, recover, enqueue_rebuild_game, enqueue_rebuild_file, enqueue_rebuild_affected, enqueue_source_identity_file, enqueue_source_identity_game, enqueue_reconcile_unverified, enqueue_prune_artifacts and enqueue_prune.',
        400
    );
} catch (Throwable $exception) {
    error_log('[UnrealDB job action API] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'The jobs service is temporarily unavailable.', 503);
}
