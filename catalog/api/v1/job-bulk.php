<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles bounded bulk restart/cancel requests and queues bulk terminal-history deletion.
 * Why: HTTP input/authentication belongs here while durable mutation SQL lives behind Infrastructure services.
 * Role: Thin HTTP API entry point.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoAffectedDependencyRetrySelection;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobBulkAction;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('job_action');

    $payload = catalog_api_json_body();
    $action = strtolower(trim((string)($payload['action'] ?? '')));
    $scope = strtolower(trim((string)($payload['scope'] ?? 'selected')));
    $queueName = trim((string)($payload['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
    $status = strtolower(trim((string)($payload['status'] ?? '')));
    $search = trim((string)($payload['search'] ?? ''));
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if (!in_array($action, ['restart', 'cancel', 'delete'], true)) {
        JsonResponse::error('invalid_action', 'Choose restart, cancel or delete.', 400);
    }
    if (!in_array($scope, ['selected', 'matching'], true)) {
        JsonResponse::error('invalid_scope', 'Choose selected jobs or all matching jobs.', 400);
    }
    if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }
    if ($status !== '' && !CatalogJobDisplayStatus::isValidFilter($status)) {
        JsonResponse::error('invalid_status', 'Unsupported job status filter.', 400);
    }
    if (mb_strlen($search, 'UTF-8') > 200) {
        JsonResponse::error('invalid_search', 'Search text is too long.', 400);
    }

    $jobIds = [];
    if ($scope === 'selected') {
        $rawIds = $payload['job_ids'] ?? [];
        if (!is_array($rawIds)) {
            JsonResponse::error('invalid_jobs', 'Select at least one job.', 400);
        }
        foreach ($rawIds as $rawId) {
            $jobId = (int)$rawId;
            if ($jobId > 0) {
                $jobIds[$jobId] = $jobId;
            }
        }
        $jobIds = array_values($jobIds);
        if ($jobIds === []) {
            JsonResponse::error('invalid_jobs', 'Select at least one job.', 400);
        }
        if (count($jobIds) > 10000) {
            JsonResponse::error('too_many_jobs', 'No more than 10,000 selected jobs can be changed at once.', 400);
        }
    }

    // Release the administrator session before bounded database work so one bulk
    // operation cannot serialize every other request from this browser.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $selectedSourceJobs = count($jobIds);
    $retrySelectionExpanded = false;
    if ($action === 'restart' && $scope === 'selected' && $jobIds !== []) {
        $expandedJobIds = (new PdoAffectedDependencyRetrySelection($application->db))
            ->expand($queueName, $jobIds);
        $retrySelectionExpanded = $expandedJobIds !== $jobIds;
        $jobIds = $expandedJobIds;
    }

    $result = (new PdoBackgroundJobBulkAction($application->db, $application->config))->execute(
        $action,
        $scope,
        $queueName,
        $status,
        $search,
        $jobIds,
        $userId
    );

    if ($retrySelectionExpanded) {
        $result['selected_source_jobs'] = $selectedSourceJobs;
        $result['expanded_recovery_jobs'] = count($jobIds);
        $result['retry_selection_expanded'] = true;
    }

    if (!empty($result['worker_start_required'])) {
        $workerState = (new CatalogQueueWorkerStarter($application->db, $application->config))
            ->start($queueName, true, $userId);
        $result['worker'] = $workerState['worker'];
        $result['worker_error'] = (string)$workerState['worker_error'];
    }

    JsonResponse::send(['data' => $result]);
} catch (Throwable $error) {
    error_log('[UnrealDB job bulk API] ' . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error('unavailable', catalog_public_error_message(), 500);
}
