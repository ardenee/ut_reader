#!/usr/bin/env php
<?php
/**
 * Read-only regression contract for asynchronous Background Jobs history cleanup.
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
$cleanup = $read('src/Infrastructure/Jobs/CatalogBackgroundJobCleanup.php');
$chunkCleanup = $read('src/Infrastructure/Import/CatalogChunkedUploadCleanup.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$workerVersion = $read('src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
$bulk = $read('src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php');
$bulkApi = $read('api/v1/job-bulk.php');
$actionApi = $read('api/v1/job-action.php');
$resourceStore = $read('src/Infrastructure/Jobs/CatalogJobResourceLimitStore.php');
$page = $read('background-jobs.php');
$client = $read('assets/background-jobs-files.js');

$check(
    'job_type_registered',
    str_contains($jobType, "CLEAN_BACKGROUND_JOB_HISTORY = 'catalog.clean_background_job_history'")
        && str_contains($jobType, 'self::CLEAN_BACKGROUND_JOB_HISTORY'),
    'The history-cleanup job type must be registered.'
);

$check(
    'worker_factory_executes_cleanup_job',
    str_contains($factory, 'JobType::CLEAN_BACKGROUND_JOB_HISTORY')
        && str_contains($factory, 'new CatalogBackgroundJobHistoryCleanupJobHandler($db, $config)'),
    'A queued history cleanup must have a worker handler.'
);

$check(
    'cleanup_worker_is_resumable_and_child_bounded',
    str_contains($handler, 'private const BATCH_SIZE = 10000;')
        && str_contains($handler, 'private const MAX_WORKFLOW_ROWS_PER_CLAIM = 100000;')
        && str_contains($handler, 'cleanup_stack')
        && str_contains($handler, 'cleanup_root_id')
        && str_contains($handler, 'CatalogBackgroundJobSubtreePruner')
        && str_contains($handler, '$context->heartbeatIfDue($progress)')
        && str_contains($handler, '$context->defer(1, $progress)')
        && str_contains($handler, 'deleted_workflow_units'),
    'Large workflow trees must be drained leaf-first in bounded resumable batches without per-root forced checkpoints.'
);

$check(
    'hidden_children_use_full_cleanup_path',
    str_contains($handler, '$cleanup->deleteWorkflowJobs($children[\'leaf_ids\'])')
        && str_contains($handler, '$cleanup->deleteWorkflowJobs([$currentId])')
        && !str_contains($handler, '$pruner->deleteLeafRows(')
        && str_contains($cleanup, 'public function deleteWorkflowJobs(array $jobIds): array'),
    'Hidden child rows must not bypass event/staged-source cleanup.'
);

$check(
    'staged_cleanup_is_direct',
    !str_contains($cleanup, 'protectedStagedPaths')
        && !str_contains($cleanup, 'JSON_EXTRACT(')
        && str_contains($cleanup, 'local-pak:')
        && str_contains($cleanup, 'local-catalog:')
        && str_contains($cleanup, '$store->delete($relativePath)')
        && str_contains($cleanup, '$chunkCleanup->deleteWithStats($uploadId)'),
    'Cleanup must directly remove owned staged sources without scanning surviving jobs for references.'
);

$check(
    'workflow_child_detection_is_set_based',
    !str_contains($pruner, 'EXISTS(')
        && str_contains($pruner, 'SELECT DISTINCT parent_job_id FROM ue_background_jobs')
        && str_contains($pruner, 'array_chunk($childIds, 1000)')
        && !str_contains($handler, '$pruner->exists($currentId)'),
    'Workflow cleanup must classify child branches in bounded set-based queries without per-child or redundant per-node existence probes.'
);

$check(
    'staged_cleanup_reports_reclaimed_bytes',
    str_contains($cleanup, "'deleted_staged_bytes'")
        && str_contains($cleanup, '$chunkCleanup->deleteWithStats($uploadId)')
        && str_contains($chunkCleanup, 'public function deleteWithStats(string $uploadId): array')
        && str_contains($handler, 'deleted_staged_bytes')
        && str_contains($handler, 'formatBytes($deletedStagedBytes)'),
    'Cleanup results must report actual staged bytes reclaimed, including chunked uploads.'
);

$check(
    'retention_snapshot_is_bounded_and_continuous',
    str_contains($queue, 'public const SNAPSHOT_LIMIT = 10000;')
        && str_contains($queue, 'public function snapshotBefore(string $queueName, string $cutoff): array')
        && str_contains($queue, 'LIMIT \' . (self::SNAPSHOT_LIMIT + 1)')
        && !str_contains($queue, 'SELECT COUNT(*) FROM ue_background_jobs WHERE')
        && str_contains($queue, '$payload[\'retention_auto_continue\'] = true;')
        && str_contains($handler, 'retention_auto_continue')
        && str_contains($handler, 'snapshotBefore($targetQueue, $retentionCutoff)')
        && str_contains($handler, '\'snapshot_ids\' => $snapshotIds')
        && str_contains($handler, '\'snapshot_batch\' => $snapshotBatch'),
    'Retention cleanup must keep each SQL snapshot bounded while automatically continuing under the original cutoff.'
);

$check(
    'retention_keeps_unresolved_issues',
    str_contains($queue, 'NOT IN ("failed","rejected","unverified","partial","error")')
        && str_contains($queue, 'status="cancelled" OR')
        && str_contains($page, 'Issues are retained.'),
    'Automatic retention must remove resolved completed/stopped history without erasing unresolved operator work.'
);

$check(
    'retention_api_passes_fixed_cutoff',
    str_contains($actionApi, "if (\$action === 'cleanup')")
        && str_contains($actionApi, "\$snapshot['cutoff']")
        && str_contains($actionApi, "'auto_continue' => true")
        && str_contains($actionApi, 'CatalogBackgroundJobHistoryCleanupQueue'),
    'The HTTP request must freeze one cutoff and let the worker continue asynchronously.'
);

$check(
    'active_background_jobs_ui_reports_queued_cleanup',
    str_contains($page, 'assets/background-jobs-files.js')
        && str_contains($client, "cleanupButton.addEventListener('click'")
        && str_contains($client, 'Queued cleanup job #')
        && str_contains($client, 'automatically continue beyond the first 10,000 roots')
        && str_contains($client, 'staged files and reclaimed bytes'),
    'The active file-centric Background Jobs page must describe cleanup as queued background work and not claim immediate deletion.'
);

$check(
    'bulk_delete_queues_cleanup_instead_of_deleting_files',
    str_contains($bulk, 'new CatalogBackgroundJobHistoryCleanupQueue')
        && str_contains($bulk, "'cleanup_job_id'")
        && !str_contains($bulk, 'new CatalogBackgroundJobCleanup'),
    'Bulk Delete must enqueue cleanup instead of deleting staged files in the browser request.'
);

$check(
    'bulk_api_wakes_worker_for_cleanup',
    str_contains($bulkApi, "['restart', 'cancel', 'delete']")
        && str_contains($bulkApi, 'worker_start_required')
        && str_contains($bulkApi, 'CatalogQueueWorkerStarter'),
    'Bulk cleanup requests must wake a detached worker.'
);

$check(
    'cleanup_worker_fingerprint_tracks_storage_logic',
    str_contains($workerVersion, 'CatalogBackgroundJobHistoryCleanupJobHandler.php')
        && str_contains($workerVersion, 'CatalogBackgroundJobHistoryCleanupQueue.php')
        && str_contains($workerVersion, 'CatalogBackgroundJobSubtreePruner.php')
        && str_contains($workerVersion, 'CatalogBackgroundJobCleanup.php')
        && str_contains($workerVersion, 'CatalogChunkedUploadCleanup.php'),
    'Changing retention/staged cleanup code must force stale detached workers to reload.'
);

$check(
    'affected_dependency_children_keep_narrow_keys',
    str_contains($resourceStore, 'JSON_EXTRACT(payload_json,"$.affected_file_id") IS NULL')
        && str_contains($resourceStore, 'JSON_EXTRACT(payload_json,"$.affected_file_ids") IS NULL'),
    'Applying Job Resource Limits must rekey only the affected-dependency coordinator.'
);

$syntaxFailures = [];
foreach ([
    'src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupJobHandler.php',
    'src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupQueue.php',
    'src/Infrastructure/Jobs/CatalogBackgroundJobSubtreePruner.php',
    'src/Infrastructure/Jobs/CatalogBackgroundJobCleanup.php',
    'src/Infrastructure/Import/CatalogChunkedUploadCleanup.php',
    'api/v1/job-action.php',
    'background-jobs.php',
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
