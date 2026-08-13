#!/usr/bin/env php
<?php
/**
 * Read-only regression contract for asynchronous Background Jobs history cleanup
 * and the resource-limit synchronizer's per-file affected-dependency keys.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$failures = [];
$checks = [];
$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};
$check = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$jobType = $read('src/Domain/Jobs/JobType.php');
$handler = $read('src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupJobHandler.php');
$queue = $read('src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupQueue.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$bulk = $read('src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php');
$bulkApi = $read('api/v1/job-bulk.php');
$actionApi = $read('api/v1/job-action.php');
$resourceStore = $read('src/Infrastructure/Jobs/CatalogJobResourceLimitStore.php');
$page = $read('background-jobs.php');
$stableClient = $read('assets/background-jobs-stable.js');
$cleanupClient = $read('assets/background-jobs-async-cleanup.js');

$check(
    'job_type_registered',
    str_contains($jobType, "CLEAN_BACKGROUND_JOB_HISTORY = 'catalog.clean_background_job_history'")
        && str_contains($jobType, 'self::CLEAN_BACKGROUND_JOB_HISTORY'),
    'The history-cleanup job type must be part of JobType::all().'
);

$check(
    'worker_factory_executes_cleanup_job',
    str_contains($factory, 'new CatalogBackgroundJobHistoryCleanupJobHandler')
        && str_contains($factory, 'JobType::CLEAN_BACKGROUND_JOB_HISTORY => $jobHistoryCleanup'),
    'A queued history cleanup must have a registered worker handler.'
);

$check(
    'cleanup_worker_is_bounded_and_resumable',
    str_contains($handler, 'private const BATCH_SIZE = 200;')
        && str_contains($handler, 'snapshot_offset')
        && str_contains($handler, 'array_slice($ids, $offset, self::BATCH_SIZE)')
        && str_contains($handler, '$context->defer(')
        && str_contains($handler, 'CatalogBackgroundJobCleanup')
        && !str_contains($handler, 'for ($offset = 0; $offset < count($ids);'),
    'The worker must delete only a bounded snapshot slice per claim and persist the exact offset.'
);

$check(
    'cleanup_snapshot_is_bounded',
    str_contains($queue, 'private const SNAPSHOT_LIMIT = 10000;')
        && str_contains($queue, 'snapshotOlderThan(')
        && str_contains($queue, 'enqueueSnapshot(')
        && str_contains($queue, 'ORDER BY id ASC LIMIT ' . "' . self::SNAPSHOT_LIMIT"),
    'HTTP-time work may select a bounded immutable ID snapshot but must not perform the filesystem cleanup itself.'
);

$check(
    'bulk_delete_queues_cleanup_instead_of_deleting_files',
    str_contains($bulk, 'new CatalogBackgroundJobHistoryCleanupQueue')
        && str_contains($bulk, "'cleanup_job_id'")
        && str_contains($bulk, 'new PdoBackgroundJobSearchScope')
        && !str_contains($bulk, 'new CatalogBackgroundJobCleanup'),
    'Bulk Delete must reuse the visible job scope and enqueue cleanup instead of deleting staged files in the web request.'
);

$check(
    'bulk_api_wakes_worker_for_cleanup',
    str_contains($bulkApi, "['restart', 'cancel', 'delete']")
        && str_contains($bulkApi, "worker_start_required")
        && str_contains($bulkApi, 'CatalogQueueWorkerStarter'),
    'The bulk API must return quickly and wake the detached worker for queued cleanup/restart work.'
);

$check(
    'legacy_cleanup_routes_are_async',
    str_contains($actionApi, "if (\$action === 'delete_selected')")
        && str_contains($actionApi, "if (\$action === 'delete_matching')")
        && str_contains($actionApi, "if (\$action === 'cleanup')")
        && str_contains($actionApi, 'CatalogBackgroundJobHistoryCleanupQueue')
        && str_contains($actionApi, 'PdoBackgroundJobBulkAction')
        && !str_contains($actionApi, '->cleanup($queueName,')
        && !str_contains($actionApi, '->deleteTerminalJobs($jobIds'),
    'No legacy bulk/history-cleanup route may retain a synchronous filesystem-delete path.'
);

$check(
    'affected_dependency_children_keep_narrow_keys',
    str_contains($resourceStore, 'JSON_EXTRACT(payload_json,"$.affected_file_id") IS NULL')
        && str_contains($resourceStore, 'JSON_EXTRACT(payload_json,"$.affected_file_ids") IS NULL'),
    'Applying Job Resource Limits must rekey only the affected-dependency coordinator, never per-file or legacy compatibility children.'
);

$check(
    'background_jobs_page_loads_existing_clients',
    str_contains($page, 'assets/background-jobs-stable.js')
        && str_contains($page, 'assets/background-jobs-async-cleanup.js')
        && !str_contains($page, 'assets/background-jobs-v2.js')
        && $stableClient !== ''
        && $cleanupClient !== '',
    'The Background Jobs page must not reference a nonexistent client bundle.'
);

$check(
    'async_cleanup_notice_matches_current_ui',
    str_contains($cleanupClient, "getElementById('jobs-message')")
        && str_contains($cleanupClient, 'cleanup_job_id')
        && str_contains($cleanupClient, 'Actual deleted/skipped/staged-file counts will be reported by the cleanup job.'),
    'The current page must report cleanup as queued work instead of immediately claiming rows were deleted.'
);

$check(
    'stable_client_still_owns_general_job_ui',
    str_contains($stableClient, "cleanupButton.addEventListener('click'")
        && str_contains($stableClient, 'runBulk(')
        && str_contains($cleanupClient, 'all other')
        && str_contains($cleanupClient, 'background-jobs-stable.js'),
    'The compatibility shim should only correct async cleanup notices, not replace the established Background Jobs client.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
