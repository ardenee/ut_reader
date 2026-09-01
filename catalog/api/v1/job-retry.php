<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for restarting selected terminal jobs.
 * Why: HTTP validation remains here while queue mutation and worker lifecycle are delegated.
 * Role: Thin HTTP API entry point preserving the established job-retry compatibility contract.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobRetryAction;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('job_action');

    $payload = catalog_api_json_body();
    $queueName = trim((string)($payload['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
    if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }

    $rawIds = $payload['job_ids'] ?? [];
    if (!is_array($rawIds)) {
        $rawIds = [];
    }
    if (isset($payload['job_id'])) {
        $rawIds[] = $payload['job_id'];
    }

    $jobIds = [];
    foreach ($rawIds as $rawId) {
        $jobId = (int)$rawId;
        if ($jobId > 0) {
            $jobIds[$jobId] = $jobId;
        }
    }
    $jobIds = array_values($jobIds);
    if ($jobIds === []) {
        JsonResponse::error('invalid_jobs', 'Select at least one stopped or failed job to restart.', 400);
    }
    if (count($jobIds) > 1000) {
        JsonResponse::error('too_many_jobs', 'Restart no more than 1,000 jobs at a time.', 400);
    }

    $restarted = (new PdoBackgroundJobRetryAction($application->db))->restart($queueName, $jobIds);
    $worker = null;
    $workerError = '';
    if ($restarted > 0) {
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $workerState = (new CatalogQueueWorkerStarter($application->db, $application->config))
            ->start($queueName, true, $userId);
        $worker = is_array($workerState['worker'] ?? null) ? $workerState['worker'] : null;
        $workerError = trim((string)($workerState['worker_error'] ?? ''));
        if ($workerError !== '') {
            error_log('[UnrealDB job restart worker] ' . $workerError);
        }
    }

    JsonResponse::send([
        'data' => [
            'queue' => $queueName,
            'requested' => count($jobIds),
            'restarted' => $restarted,
            'skipped' => count($jobIds) - $restarted,
            'worker' => $worker,
            'worker_error' => $workerError,
        ],
    ]);
} catch (Throwable $error) {
    error_log('[UnrealDB job retry API] ' . $error->getMessage());
    JsonResponse::error('unavailable', catalog_public_error_message(), 500);
}
