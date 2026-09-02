<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles bounded bulk restart/cancel requests and queues bulk terminal-history deletion.
 * Why: HTTP input/authentication belongs here while durable mutation SQL lives behind Infrastructure services.
 * Role: Thin HTTP API entry point.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoAffectedDependencyRetrySelection;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobBulkAction;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobFileTreeQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCompletedArchiveRerunSelection;
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
    $fileState = strtolower(trim((string)($payload['file_state'] ?? 'all')));
    $jobType = trim((string)($payload['job_type'] ?? ''));
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if (!in_array($action, ['restart', 'cancel', 'delete'], true)) {
        JsonResponse::error('invalid_action', 'Choose restart, cancel or delete.', 400);
    }
    if (!in_array($scope, ['selected', 'matching', 'file_matching'], true)) {
        JsonResponse::error('invalid_scope', 'Choose selected jobs, all matching jobs or all matching source jobs.', 400);
    }
    if ($scope === 'file_matching' && !in_array($fileState, ['all', 'working', 'issue', 'completed', 'stopped'], true)) {
        JsonResponse::error('invalid_file_state', 'Unsupported file state filter.', 400);
    }
    if ($scope === 'file_matching' && $jobType !== '' && !in_array($jobType, JobType::all(), true)) {
        JsonResponse::error('invalid_job_type', 'Unsupported job type filter.', 400);
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

    $matchingSourceTotal = 0;
    $matchingSourceLimited = false;
    if ($scope === 'file_matching') {
        $selection = (new PdoBackgroundJobFileTreeQuery($application->db))->matchingRootIds(
            $queueName,
            $fileState,
            $search,
            $jobType,
            10000
        );
        $jobIds = $selection['ids'];
        $matchingSourceTotal = max(0, (int)$selection['total']);
        $matchingSourceLimited = !empty($selection['limited']);
    }

    // Release the administrator session before bounded database work so one bulk
    // operation cannot serialize every other request from this browser.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $sourceSelection = in_array($scope, ['selected', 'file_matching'], true);
    $selectedSourceJobs = count($jobIds);
    $affectedRecovery = [
        'handled_root_ids' => [],
        'requested' => 0,
        'affected' => 0,
        'retry_blocked' => 0,
        'skipped' => 0,
    ];
    $completedArchiveRerun = [
        'handled_root_ids' => [],
        'requested' => 0,
        'affected' => 0,
        'descendants_requeued' => 0,
        'skipped' => 0,
    ];

    if ($action === 'restart' && $sourceSelection && $jobIds !== []) {
        $now = gmdate('Y-m-d H:i:s');

        $affectedRecovery = (new PdoAffectedDependencyRetrySelection($application->db))
            ->restartPartialRoots($queueName, $jobIds, $now);
        $handledRoots = array_fill_keys(
            array_map('intval', $affectedRecovery['handled_root_ids'] ?? []),
            true
        );
        if ($handledRoots !== []) {
            $jobIds = array_values(array_filter(
                $jobIds,
                static fn(int $id): bool => !isset($handledRoots[$id])
            ));
        }

        // A completed archive is not a failed job, so the generic retry policy must
        // remain failure-only. An explicit selection, however, is also the operator
        // control for replaying retained archive bytes after parser/routing changes.
        // Reset the completed archive tree here and remove those roots before the
        // remaining failure-oriented bulk action is evaluated.
        if ($jobIds !== []) {
            $completedArchiveRerun = (new PdoCompletedArchiveRerunSelection($application->db))
                ->rerunSelected($queueName, $jobIds, $now);
            $rerunRoots = array_fill_keys(
                array_map('intval', $completedArchiveRerun['handled_root_ids'] ?? []),
                true
            );
            if ($rerunRoots !== []) {
                $jobIds = array_values(array_filter(
                    $jobIds,
                    static fn(int $id): bool => !isset($rerunRoots[$id])
                ));
            }
        }
    }

    if (!$sourceSelection || $jobIds !== []) {
        $executionScope = $sourceSelection ? 'selected' : $scope;
        $result = (new PdoBackgroundJobBulkAction($application->db, $application->config))->execute(
            $action,
            $executionScope,
            $queueName,
            $status,
            $search,
            $jobIds,
            $userId
        );
    } else {
        $result = [
            'action' => $action,
            'scope' => $scope,
            'queue' => $queueName,
            'requested' => 0,
            'affected' => 0,
            'scheduled' => 0,
            'cleanup_job_id' => 0,
            'skipped' => 0,
            'retry_blocked' => 0,
            'deleted_staged_files' => 0,
            'limited' => false,
            'batch_limit' => 10000,
            'worker' => null,
            'worker_error' => '',
            'worker_start_required' => false,
        ];
    }

    if (($affectedRecovery['handled_root_ids'] ?? []) !== []) {
        $recoveryRequested = max(0, (int)($affectedRecovery['requested'] ?? 0));
        $recoveryAffected = max(0, (int)($affectedRecovery['affected'] ?? 0));
        $recoveryBlocked = max(0, (int)($affectedRecovery['retry_blocked'] ?? 0));
        $recoverySkipped = max(0, (int)($affectedRecovery['skipped'] ?? 0));

        $result['requested'] = max(0, (int)($result['requested'] ?? 0)) + $recoveryRequested;
        $result['affected'] = max(0, (int)($result['affected'] ?? 0)) + $recoveryAffected;
        $result['retry_blocked'] = max(0, (int)($result['retry_blocked'] ?? 0)) + $recoveryBlocked;
        $result['skipped'] = max(0, (int)($result['skipped'] ?? 0)) + $recoverySkipped;
        $result['worker_start_required'] = !empty($result['worker_start_required']) || $recoveryAffected > 0;
        $result['selected_source_jobs'] = $selectedSourceJobs;
        $result['affected_dependency_source_jobs'] = count($affectedRecovery['handled_root_ids']);
        $result['affected_dependency_recovery_jobs'] = $recoveryRequested;
        $result['retry_selection_expanded'] = true;
    }

    if (($completedArchiveRerun['handled_root_ids'] ?? []) !== []) {
        $archiveRequested = max(0, (int)($completedArchiveRerun['requested'] ?? 0));
        $archiveAffected = max(0, (int)($completedArchiveRerun['affected'] ?? 0));
        $archiveSkipped = max(0, (int)($completedArchiveRerun['skipped'] ?? 0));
        $archiveDescendants = max(0, (int)($completedArchiveRerun['descendants_requeued'] ?? 0));

        $result['requested'] = max(0, (int)($result['requested'] ?? 0)) + $archiveRequested;
        $result['affected'] = max(0, (int)($result['affected'] ?? 0)) + $archiveAffected;
        $result['skipped'] = max(0, (int)($result['skipped'] ?? 0)) + $archiveSkipped;
        $result['worker_start_required'] = !empty($result['worker_start_required']) || $archiveAffected > 0;
        $result['selected_source_jobs'] = $selectedSourceJobs;
        $result['completed_archive_source_jobs'] = count($completedArchiveRerun['handled_root_ids']);
        $result['completed_archive_descendant_jobs'] = $archiveDescendants;
        $result['archive_rerun_expanded'] = true;
    }

    $result['scope'] = $scope;
    if ($scope === 'file_matching') {
        $result['matching_source_jobs'] = $matchingSourceTotal;
        $result['selected_source_jobs'] = $selectedSourceJobs;
        $result['selection_limited'] = $matchingSourceLimited;
        $result['limited'] = !empty($result['limited']) || $matchingSourceLimited;
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
