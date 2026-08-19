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
$pruner = $read('src/Infrastructure/Jobs/CatalogBackgroundJobSubtreePruner.php');
$queue = $read('src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupQueue.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$workerVersion = $read('src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
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
    str_contains($factory, 'JobType::CLEAN_BACKGROUND_JOB_HISTORY')
        && str_contains($factory, 'new CatalogBackgroundJobHistoryCleanupJobHandler($db, $config)'),
    'A queued history cleanup must have a registered worker handler, including the current lazy-factory route.'
);

$check(
    'cleanup_worker_is_resumable_and_child_bounded',
    str_contains($handler, 'private const MAX_WORKFLOW_ROWS_PER_CLAIM = 100000;')
        && str_contains($handler, 'cleanup_stack')
        && str_contains($handler, 'cleanup_root_id')
        && str_contains($handler, 'CatalogBackgroundJobSubtreePruner')
        && str_contains($handler, 'deleteLeafRows(')
        && str_contains($handler, '$context->defer(1, $progress)')
        && str_contains($handler, 'deleted_workflow_units'),
    'Large parent workflow trees must be drained leaf-first in bounded batches with durable stack/cursor progress.'
);

$check(
    'subtree_pruner_avoids_large_fk_cascades',
    str_contains($pruner, 'public const CHILD_SCAN_LIMIT = 5000;')
        && str_contains($pruner, 'WHERE c.parent_job_id=?')
        && str_contains($pruner, 'EXISTS(')
        && str_contains($pruner, 'WHERE gc.parent_job_id=c.id LIMIT 1')
        && str_contains($pruner, 'deleteLeafRows')
        && str_contains($pruner, 'DELETE FROM ue_background_jobs WHERE id IN ('),
    'Cleanup must use the indexed parent relationship to remove observed leaves before deleting workflow parents.'
);

$check(
    'cleanup_progress_exposes_hidden_work',
    str_contains($handler, 'hidden workflow row(s) drained')
        && str_contains($handler, 'Draining hidden workflow history under job #'),
    'Operator progress must explain when a small visible job snapshot owns a much larger hidden execution ledger.'
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
    'cleanup_worker_fingerprint_tracks_subtree_logic',
    str_contains($workerVersion, 'CatalogBackgroundJobHistoryCleanupJobHandler.php')
        && str_contains($workerVersion, 'CatalogBackgroundJobSubtreePruner.php')
        && str_contains($workerVersion, 'CatalogBackgroundJobCleanup.php'),
    'Changing history cleanup code must force stale detached workers to reload before continuing a cleanup job.'
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
    'stable_client_remains_primary_with_scoped_cleanup_interception',
    str_contains($stableClient, "cleanupButton.addEventListener('click'")
        && str_contains($stableClient, 'runBulk(')
        && str_contains($cleanupClient, 'const isBulkDelete =')
        && str_contains($cleanupClient, 'const isRetentionCleanup =')
        && str_contains($cleanupClient, 'if (!isBulkDelete && !isRetentionCleanup) return response;'),
    'The established Background Jobs client must remain present while async cleanup response handling stays explicitly scoped to cleanup requests.'
);

$syntaxFailures = [];
foreach ([
    'src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupJobHandler.php',
    'src/Infrastructure/Jobs/CatalogBackgroundJobSubtreePruner.php',
] as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = $relative . ' could not be linted';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$check('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
