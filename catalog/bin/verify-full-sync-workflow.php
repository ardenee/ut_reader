#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies the resumable compact-only Full Sync parent/child workflow.
 * Role: Read-only regression gate preventing a return to one multi-hour monolithic worker loop.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$withDatabase = in_array('--database', array_slice($argv, 1), true);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};

$phpFiles = [
    'full-sync.php',
    'api/v1/full-sync-job.php',
    'src/Application/Jobs/JobDeferred.php',
    'src/Application/Jobs/JobExecutionContext.php',
    'src/Application/Jobs/JobQueue.php',
    'src/Application/Jobs/JobWorker.php',
    'src/Domain/Jobs/ClaimedJob.php',
    'src/Domain/Jobs/JobType.php',
    'src/Domain/Jobs/JobResourcePolicy.php',
    'src/Infrastructure/Jobs/CatalogFullSyncJobHandler.php',
    'src/Infrastructure/Jobs/CatalogFullSyncUnitJobHandler.php',
    'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'src/Infrastructure/Maintenance/CatalogFullSyncDependencyBatchService.php',
    'src/Infrastructure/Maintenance/CatalogFullSyncProjectionService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceReimportService.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php',
    'src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php',
    'src/Infrastructure/Persistence/PdoJobClaimer.php',
    'src/Infrastructure/Persistence/PdoJobLeaseStore.php',
    'src/Infrastructure/Persistence/PdoJobRecovery.php',
    'src/Infrastructure/Persistence/PdoJobEnqueuer.php',
    'src/Infrastructure/Persistence/PdoWorkflowChildStateQuery.php',
];

$syntaxFailures = [];
if (!function_exists('proc_open')) {
    $syntaxFailures[] = 'proc_open unavailable';
} else {
    foreach ($phpFiles as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            $syntaxFailures[] = $relative . ' is missing';
            continue;
        }
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$page = $read('full-sync.php');
$api = $read('api/v1/full-sync-job.php');
$handler = $read('src/Infrastructure/Jobs/CatalogFullSyncJobHandler.php');
$unit = $read('src/Infrastructure/Jobs/CatalogFullSyncUnitJobHandler.php');
$jobType = $read('src/Domain/Jobs/JobType.php');
$policy = $read('src/Domain/Jobs/JobResourcePolicy.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$context = $read('src/Application/Jobs/JobExecutionContext.php');
$claimer = $read('src/Infrastructure/Persistence/PdoJobClaimer.php');
$leases = $read('src/Infrastructure/Persistence/PdoJobLeaseStore.php');
$recovery = $read('src/Infrastructure/Persistence/PdoJobRecovery.php');
$enqueuer = $read('src/Infrastructure/Persistence/PdoJobEnqueuer.php');
$childStateQuery = $read('src/Infrastructure/Persistence/PdoWorkflowChildStateQuery.php');
$migration = $read('migrations/202608120001_job_workflow_recovery_logging.php');
$worker = $read('src/Application/Jobs/JobWorker.php');

$record(
    'full_sync_browser_only_enqueues',
    str_contains($page, 'api/v1/full-sync-job.php')
        && str_contains($page, 'durable parent/child workflow')
        && !str_contains($page, "execute('sync_reimport'")
        && str_contains($api, 'JobType::FULL_SYNC_GAME'),
    'The browser must enqueue a coordinator and must never own the long package loop.'
);

$record(
    'workflow_schema_has_idempotent_children',
    str_contains($migration, 'parent_job_id')
        && str_contains($migration, 'workflow_unit_key')
        && str_contains($migration, 'uq_ue_background_jobs_parent_unit')
        && str_contains($migration, 'ON DELETE CASCADE')
        && str_contains($enqueuer, 'parentJobId')
        && str_contains($enqueuer, 'workflowUnitKey')
        && str_contains($enqueuer, 'LAST_INSERT_ID(id)'),
    'Parent/unit identity must make child planning idempotent and cleanup must own the child ledger.'
);

$record(
    'full_sync_child_types_are_explicit',
    str_contains($jobType, "FULL_SYNC_FILE = 'catalog.full_sync_file'")
        && str_contains($jobType, "FULL_SYNC_DEPENDENCY_FILE = 'catalog.full_sync_dependency_file'")
        && str_contains($factory, 'JobType::FULL_SYNC_FILE => static fn() => new CatalogFullSyncUnitJobHandler')
        && str_contains($factory, 'JobType::FULL_SYNC_DEPENDENCY_FILE => static fn() => new CatalogFullSyncUnitJobHandler')
        && str_contains($policy, 'self::FULL_SYNC_UNIT'),
    'Reimport and dependency work must be independently claimable durable units through the lazy worker factory.'
);

$record(
    'coordinator_does_not_reimport_files_inline',
    !str_contains($handler, "execute('sync_reimport'")
        && !str_contains($handler, 'CatalogFullSyncDependencyBatchService')
        && str_contains($handler, 'JobType::FULL_SYNC_FILE')
        && str_contains($handler, 'JobType::FULL_SYNC_DEPENDENCY_FILE')
        && str_contains($handler, 'private function planUnits(')
        && str_contains($handler, "\$prefix . ':' . \$fileId")
        && str_contains($handler, '$context->defer('),
    'The parent may plan/wait/finalize, but it must not parse thousands of files itself.'
);

$record(
    'full_sync_phase_checkpoints_are_resumable',
    str_contains($handler, "'full_sync_plan_reimport'")
        && str_contains($handler, "'full_sync_wait_reimport'")
        && str_contains($handler, "'full_sync_prepare_providers'")
        && str_contains($handler, "'full_sync_plan_dependencies'")
        && str_contains($handler, "'full_sync_wait_dependencies'")
        && str_contains($handler, "'full_sync_finalize'")
        && str_contains($handler, '$context->resumeProgress()')
        && str_contains($handler, "if (\$legacyStage === 'full_sync_finalize')"),
    'A retry must resume the persisted phase; an old 97% finalization failure must not replay package work.'
);

$record(
    'successful_children_are_not_replayed',
    str_contains($handler, 'childState($job->id')
        && str_contains($handler, 'PdoWorkflowChildStateQuery')
        && str_contains($handler, 'Restart only those failed child jobs')
        && str_contains($handler, "'planned_units'")
        && str_contains($childStateQuery, 'SELECT status,COUNT(*) c FROM ue_background_jobs WHERE parent_job_id=?')
        && str_contains($childStateQuery, 'workflow_unit_key LIKE ?'),
    'The parent must derive state through the shared durable child-state query and wait on errors instead of rebuilding successful units.'
);

$record(
    'unit_handler_is_one_file_only',
    str_contains($unit, 'JobType::FULL_SYNC_FILE')
        && str_contains($unit, 'JobType::FULL_SYNC_DEPENDENCY_FILE')
        && str_contains($unit, "execute('sync_reimport'")
        && str_contains($unit, '->refresh($gameId, [$fileId])')
        && !str_contains($unit, 'foreach ($files')
        && !str_contains($unit, 'CatalogFileMaintenanceRemovalService')
        && !str_contains($unit, "'status' => 'removed_invalid'"),
    'Each child must own only one file; validation failure is visible and must preserve the verified file.'
);

$record(
    'queue_preserves_checkpoint_on_claim_retry_recovery',
    str_contains($claimer, '$resumeProgress')
        && !str_contains($claimer, 'progress_json=NULL')
        && !str_contains($leases, 'progress_json=NULL')
        && !str_contains($recovery, 'progress_json=NULL')
        && str_contains($context, 'public function resumeProgress()')
        && str_contains($context, 'public function defer('),
    'Claim, retry, lease recovery and manual Restart must not erase durable progress.'
);

$record(
    'coordinator_wait_is_not_failure',
    str_contains($leases, 'attempts=GREATEST(attempts-1,0)')
        && str_contains($leases, 'public function defer(')
        && str_contains($worker, 'catch (JobDeferred $deferred)')
        && str_contains($worker, '$this->queue->defer('),
    'Waiting for child jobs must release a worker slot and must not consume retry attempts.'
);

$reimport = $read('src/Infrastructure/Maintenance/CatalogFileMaintenanceReimportService.php');
$record(
    'maintenance_reimport_preserves_verified_identity',
    str_contains($reimport, "'maintenance_replace_file_id' => \$fileId")
        && str_contains($reimport, 'restoreExistingSnapshot($snapshot)')
        && !str_contains($reimport, 'DELETE FROM ue_files'),
    'A Full Sync child must repair the existing verified identity rather than replace it with a new row.'
);

$batch = $read('src/Infrastructure/Maintenance/CatalogFullSyncDependencyBatchService.php');
$record(
    'dependency_unit_repair_is_format2_safe',
    str_contains($batch, 'clearstatcache()')
        && str_contains($batch, 'isCompactMetadataIntegrityFailure')
        && str_contains($batch, "execute('sync_reimport'")
        && str_contains($batch, 'private readonly bool $recordFailures = true'),
    'A dependency unit may repair an unreadable format-2 container and retry without generating transient error noise.'
);

$writer = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php');
$record(
    'blocked_metadata_publish_clears_stat_cache',
    str_contains($writer, 'clearstatcache(true, $path)'),
    'Replacing a .uedb2 must invalidate PHP stat caching before size/hash verification.'
);

if ($withDatabase) {
    try {
        require_once $root . '/bootstrap.php';
        $application = catalog_bootstrap(false);
        $columns = $application->db->query(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_background_jobs" '
            . 'AND COLUMN_NAME IN ("parent_job_id","workflow_unit_key")'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $record(
            'database_workflow_columns',
            in_array('parent_job_id', $columns, true) && in_array('workflow_unit_key', $columns, true),
            'Run catalog/bin/migrate.php migrate if these columns are missing.'
        );
        $settings = (int)$application->db->query(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_job_logging_settings"'
        )->fetchColumn();
        $record('database_job_logging_settings', $settings === 1, 'Job logging settings table must exist.');
    } catch (Throwable $error) {
        $record('database_checks', false, get_class($error) . ': ' . $error->getMessage());
    }
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
