<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorkerStop;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogOrphanedJobRecovery;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

/** @return array{ready:int,running:int} */
function catalog_job_run_queue_counts(PDO $db, string $queueName): array
{
    return [
        'ready' => catalog_count(
            $db,
            'SELECT COUNT(*) c FROM ue_background_jobs '
                . 'WHERE queue_name=? AND status="queued" AND cancel_requested_at IS NULL AND available_at<=UTC_TIMESTAMP()',
            [$queueName]
        ),
        'running' => catalog_count(
            $db,
            'SELECT COUNT(*) c FROM ue_background_jobs WHERE queue_name=? AND status="running"',
            [$queueName]
        ),
    ];
}

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('job_action');

    $payload = catalog_api_json_body();
    $queueName = trim((string)($payload['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
    $mode = strtolower(trim((string)($payload['mode'] ?? 'drain')));
    if (!in_array($mode, ['next', 'drain'], true)) {
        JsonResponse::error('invalid_mode', 'Worker mode must be next or drain.', 400);
    }
    if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }

    $launcher = new CatalogDetachedWorker($application->config);
    $workerCount = $mode === 'next'
        ? 1
        : $launcher->normalizeWorkerCount((int)($payload['workers'] ?? $launcher->configuredWorkerCount()));
    $maxJobs = $mode === 'next' ? 1 : 1000000;
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $before = $launcher->status($queueName, true);
    $queueCounts = catalog_job_run_queue_counts($application->db, $queueName);
    $idleRestart = null;

    /*
     * The Start button is also the recovery action for a pool whose PHP
     * processes still exist but have not claimed any ready database job. This
     * is explicit POST behaviour; the read-only status poll never restarts it.
     */
    if (!empty($before['active']) && $queueCounts['ready'] > 0 && $queueCounts['running'] === 0) {
        $state = is_array($before['state'] ?? null) ? $before['state'] : [];
        $updatedAt = strtotime((string)($state['updated_at'] ?? $state['started_at'] ?? '')) ?: 0;
        $stateAge = $updatedAt > 0 ? max(0, time() - $updatedAt) : PHP_INT_MAX;
        if ($stateAge >= 5) {
            $idleRestart = (new CatalogDetachedWorkerStop($application->db, $application->config))
                ->stopQueue($queueName, $userId, 'Restarting an idle worker pool that was not claiming ready jobs.');
            $before = $launcher->status($queueName, true);
        }
    }

    $orphanRecovery = null;
    if (empty($before['active'])) {
        $orphanRecovery = (new CatalogOrphanedJobRecovery($application->db, $application->config))
            ->recoverInactiveQueue($queueName);
        $before = $launcher->status($queueName, true);
    }

    $restart = null;
    if (!empty($before['active']) && !empty($before['stale_code'])) {
        $restart = (new CatalogDetachedWorkerStop($application->db, $application->config))
            ->restartStaleQueue($queueName);
        if (empty($restart['restarted'])) {
            JsonResponse::error(
                'stale_worker_restart_failed',
                'The detached worker pool is running old code and could not be restarted automatically. Use Stop workers, then Start queued.',
                409,
                ['worker' => $before, 'restart' => $restart]
            );
        }
    }

    $result = $launcher->start($queueName, $maxJobs, $workerCount);
    JsonResponse::send([
        'data' => [
            'queue' => $queueName,
            'mode' => $mode,
            'workers' => $workerCount,
            'idle_restart' => $idleRestart,
            'orphan_recovery' => $orphanRecovery,
            'stale_restart' => $restart,
        ] + $result,
    ], 202);
} catch (InvalidArgumentException $exception) {
    JsonResponse::error('invalid_worker_request', $exception->getMessage(), 400);
} catch (Throwable $exception) {
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] detached job launcher failed: ' . get_class($exception) . ': ' . $exception->getMessage());
    JsonResponse::error(
        'launch_failed',
        trim($exception->getMessage()) ?: 'The detached queue worker pool could not be launched.',
        500,
        ['request_id' => $requestId]
    );
}
