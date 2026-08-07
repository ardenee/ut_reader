<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for job action.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; reusable work should be delegated to shared application/services rather than duplicated
 *       here.
 * Audit: Active API surface unless its callers/tests prove otherwise; preserve request/response compatibility when
 *        consolidating.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobCleanup;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorkerStop;
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
        $result = (new CatalogBackgroundJobCleanup($application->db, $application->config))
            ->deleteTerminalJobs(array_values($jobIds), $queueName);
        if ((int)$result['deleted_jobs'] < 1) {
            JsonResponse::error('not_deletable', 'None of the selected jobs are terminal jobs in this queue.', 409);
        }
        JsonResponse::send(['data' => ['queue' => $queueName] + $result]);
    }

    if ($action === 'delete_matching') {
        $status = strtolower(trim((string)($payload['status'] ?? '')));
        if ($status !== '' && !in_array($status, ['completed', 'failed', 'dead_letter', 'cancelled'], true)) {
            JsonResponse::error('invalid_status', 'Bulk deletion is available only for terminal job statuses.', 400);
        }
        $result = (new CatalogBackgroundJobCleanup($application->db, $application->config))
            ->deleteTerminalMatching($queueName, $status);
        JsonResponse::send([
            'data' => [
                'queue' => $queueName,
                'status' => $status !== '' ? $status : null,
            ] + $result,
        ]);
    }

    if ($action === 'cleanup') {
        $retentionDays = max(1, min((int)($payload['retention_days'] ?? 30), 3650));
        $result = (new CatalogBackgroundJobCleanup($application->db, $application->config))
            ->cleanup($queueName, $retentionDays);
        JsonResponse::send([
            'data' => [
                'queue' => $queueName,
                'retention_days' => $retentionDays,
            ] + $result,
        ]);
    }

    if ($action === 'recover') {
        $launcher = new CatalogDetachedWorker($application->config);
        $worker = $launcher->status($queueName, false);
        $orphanedRequeued = 0;
        $orphanedCancelled = 0;

        if (empty($worker['active'])) {
            $now = gmdate('Y-m-d H:i:s');

            // A detached process cannot still own these rows when its queue lock is
            // not held. Preserve administrator cancellation requests; requeue all
            // other orphaned rows without consuming another attempt.
            $cancel = $application->db->prepare(
                'UPDATE ue_background_jobs SET status="cancelled",dedupe_key=NULL,worker_id=NULL,lease_token=NULL,'
                . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,completed_at=?,updated_at=? '
                . 'WHERE queue_name=? AND status="running" AND worker_id LIKE "detached:%" '
                . 'AND cancel_requested_at IS NOT NULL'
            );
            $cancel->execute([$now, $now, $queueName]);
            $orphanedCancelled = $cancel->rowCount();

            $requeue = $application->db->prepare(
                'UPDATE ue_background_jobs SET status="queued",attempts=GREATEST(attempts-1,0),available_at=?,'
                . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
                . 'progress_json=NULL,progress_updated_at=NULL,last_error="Detached worker process disappeared; orphaned job requeued without consuming an attempt.",'
                . 'updated_at=? WHERE queue_name=? AND status="running" AND worker_id LIKE "detached:%" '
                . 'AND cancel_requested_at IS NULL'
            );
            $requeue->execute([$now, $now, $queueName]);
            $orphanedRequeued = $requeue->rowCount();

            if ($orphanedRequeued > 0 || $orphanedCancelled > 0) {
                $launcher->writeState($queueName, [
                    'status' => 'stopped',
                    'queue' => $queueName,
                    'ended_at' => gmdate('c'),
                    'exit_reason' => 'orphan_recovery',
                    'orphaned_requeued' => $orphanedRequeued,
                    'orphaned_cancelled' => $orphanedCancelled,
                ]);
                $launcher->clearStopRequest($queueName);
            }
        }

        $expired = $queue->recoverExpiredLeases($queueName);
        JsonResponse::send([
            'data' => [
                'queue' => $queueName,
                'orphaned_requeued' => $orphanedRequeued,
                'orphaned_cancelled' => $orphanedCancelled,
                'requeued' => $orphanedRequeued + (int)($expired['requeued'] ?? 0),
                'cancelled' => $orphanedCancelled + (int)($expired['cancelled'] ?? 0),
                'dead_lettered' => (int)($expired['dead_lettered'] ?? 0),
            ],
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
        'Supported actions are cancel, retry, delete, delete_selected, delete_matching, cleanup, recover, enqueue_rebuild_game, enqueue_rebuild_file, enqueue_rebuild_affected, enqueue_source_identity_file, enqueue_source_identity_game, enqueue_reconcile_unverified, enqueue_prune_artifacts and enqueue_prune.',
        400
    );
} catch (Throwable $exception) {
    error_log('[UnrealDB job action API] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'The jobs service is temporarily unavailable.', 503);
}
