#!/usr/bin/env php
<?php
/**
 * Read-only final integration gate for durable job-history cleanup and resource-policy synchronization.
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
    $value = @file_get_contents($path);
    return is_string($value) ? $value : '';
};
$check = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$jobType = $read('src/Domain/Jobs/JobType.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$handler = $read('src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupJobHandler.php');
$snapshot = $read('src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupQueue.php');
$bulk = $read('src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php');
$action = $read('api/v1/job-action.php');
$bulkApi = $read('api/v1/job-bulk.php');
$resourceStore = $read('src/Infrastructure/Jobs/CatalogJobResourceLimitStore.php');
$page = $read('background-jobs.php');
$stable = $read('assets/background-jobs-stable.js');
$async = $read('assets/background-jobs-async-cleanup.js');

$check(
    'cleanup_type_and_handler',
    str_contains($jobType, 'CLEAN_BACKGROUND_JOB_HISTORY')
        && str_contains($factory, 'CLEAN_BACKGROUND_JOB_HISTORY => $jobHistoryCleanup')
        && str_contains($factory, 'new CatalogBackgroundJobHistoryCleanupJobHandler'),
    'The cleanup job type must exist and be executable by the worker factory.'
);

$check(
    'bounded_resume_cursor',
    str_contains($handler, 'BATCH_SIZE = 200')
        && str_contains($handler, 'snapshot_offset')
        && str_contains($handler, 'array_slice($ids, $offset, self::BATCH_SIZE)')
        && str_contains($handler, '$context->defer('),
    'History cleanup must process bounded slices and persist an exact offset between claims.'
);

$check(
    'immutable_bounded_snapshot',
    str_contains($snapshot, 'SNAPSHOT_LIMIT = 10000')
        && str_contains($snapshot, 'snapshotOlderThan(')
        && str_contains($snapshot, 'enqueueSnapshot(')
        && str_contains($snapshot, "if (\$ids === [])"),
    'The HTTP boundary may snapshot at most 10,000 IDs and must not create a no-op job for an empty snapshot.'
);

$check(
    'bulk_delete_is_async_and_scope_aligned',
    str_contains($bulk, 'PdoBackgroundJobSearchScope')
        && str_contains($bulk, 'CatalogBackgroundJobHistoryCleanupQueue')
        && str_contains($bulk, "'cleanup_job_id'")
        && !str_contains($bulk, 'new CatalogBackgroundJobCleanup'),
    'Bulk deletion must use the same visible search scope and enqueue worker cleanup instead of deleting files synchronously.'
);

$check(
    'legacy_cleanup_routes_are_async',
    str_contains($action, "\$action === 'delete_selected'")
        && str_contains($action, "\$action === 'delete_matching'")
        && str_contains($action, "\$action === 'cleanup'")
        && str_contains($action, 'CatalogBackgroundJobHistoryCleanupQueue')
        && str_contains($bulkApi, 'worker_start_required'),
    'Both current and compatibility Background Jobs APIs must return after queueing cleanup and wake the worker.'
);

$check(
    'resource_rekey_preserves_children',
    str_contains($resourceStore, '$.affected_file_id')
        && str_contains($resourceStore, '$.affected_file_ids')
        && substr_count($resourceStore, 'IS NULL') >= 2,
    'Applying resource limits must not overwrite per-file or legacy affected-dependency child concurrency keys.'
);

$check(
    'background_jobs_loads_real_clients',
    str_contains($page, 'background-jobs-stable.js')
        && str_contains($page, 'background-jobs-async-cleanup.js')
        && !str_contains($page, 'background-jobs-v2.js')
        && $stable !== ''
        && $async !== '',
    'The Background Jobs page must load existing client files; no nonexistent v2 bundle may be referenced.'
);

$check(
    'cleanup_ui_reports_queue_not_fake_delete',
    str_contains($async, "getElementById('jobs-message')")
        && str_contains($async, 'cleanup_job_id')
        && str_contains($async, 'Queued background-job cleanup #')
        && str_contains($async, 'Actual deleted/skipped/staged-file counts'),
    'The UI must describe cleanup as queued work and leave actual deletion counts to the worker result.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
