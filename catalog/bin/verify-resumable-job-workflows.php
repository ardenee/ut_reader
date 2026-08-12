#!/usr/bin/env php
<?php
/**
 * Read-only architectural regression gate for durable/recoverable background work.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$withDatabase = in_array('--database', array_slice($argv, 1), true);
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

$recoveryFiles = [
    'src/Infrastructure/Persistence/PdoJobClaimer.php',
    'src/Infrastructure/Persistence/PdoJobLeaseStore.php',
    'src/Infrastructure/Persistence/PdoJobRecovery.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobRetryAction.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php',
    'src/Infrastructure/Jobs/CatalogManualJobRecovery.php',
    'src/Infrastructure/Jobs/CatalogDetachedWorkerStop.php',
];
$destructive = [];
foreach ($recoveryFiles as $relative) {
    $source = $read($relative);
    if ($source === '') {
        $destructive[] = $relative . ' missing';
        continue;
    }
    if (preg_match('/progress_json\s*=\s*NULL/i', $source) === 1) {
        $destructive[] = $relative . ' clears progress_json';
    }
}
$check(
    'restart_paths_preserve_progress',
    $destructive === [],
    $destructive === [] ? 'No recovery/restart path erases the durable progress snapshot.' : implode(' | ', $destructive)
);

$migration = $read('migrations/202608120001_job_workflow_recovery_logging.php');
$enqueuer = $read('src/Infrastructure/Persistence/PdoJobEnqueuer.php');
$check(
    'workflow_children_are_durable_and_idempotent',
    str_contains($migration, 'parent_job_id')
        && str_contains($migration, 'workflow_unit_key')
        && str_contains($migration, 'uq_ue_background_jobs_parent_unit')
        && str_contains($migration, 'ON DELETE CASCADE')
        && str_contains($enqueuer, 'LAST_INSERT_ID(id)'),
    'Parent/unit identity must survive coordinator replay without duplicating successful child jobs.'
);

$context = $read('src/Application/Jobs/JobExecutionContext.php');
$worker = $read('src/Application/Jobs/JobWorker.php');
$leaseStore = $read('src/Infrastructure/Persistence/PdoJobLeaseStore.php');
$check(
    'coordinator_wait_releases_worker_without_failure',
    str_contains($context, 'public function defer(')
        && str_contains($worker, 'catch (JobDeferred $deferred)')
        && str_contains($leaseStore, 'attempts=GREATEST(attempts-1,0)'),
    'Waiting on children must not occupy a worker slot or consume retry attempts.'
);

$fullSync = $read('src/Infrastructure/Jobs/CatalogFullSyncJobHandler.php');
$check(
    'full_sync_is_parent_child_workflow',
    str_contains($fullSync, 'JobType::FULL_SYNC_FILE')
        && str_contains($fullSync, 'JobType::FULL_SYNC_DEPENDENCY_FILE')
        && str_contains($fullSync, 'full_sync_finalize')
        && !str_contains($fullSync, "execute('sync_reimport'")
        && !str_contains($fullSync, 'foreach ($files'),
    'The Full Sync coordinator may plan/wait/finalize but must not run the multi-hour file loop inline.'
);

$dependencies = $read('src/Infrastructure/Jobs/CatalogDependencyRefreshJobHandler.php');
$check(
    'whole_game_dependencies_use_file_children',
    str_contains($dependencies, 'workflow_parent_job_id')
        && str_contains($dependencies, "'dependency:' . \$fileId")
        && str_contains($dependencies, 'dependency_game_wait')
        && str_contains($dependencies, 'workflow_defer_game_stats'),
    'Whole-game dependency work must retain successful per-file units and publish game stats once.'
);

$maintenance = $read('src/Infrastructure/Jobs/CatalogMaintenanceJobHandler.php');
$check(
    'source_identity_game_uses_file_children',
    str_contains($maintenance, 'source_identity_game_plan')
        && str_contains($maintenance, "'source_identity:' . \$fileId")
        && str_contains($maintenance, "'dependencies'")
        && !str_contains($maintenance, 'catalog_source_identity_rebuild_game('),
    'Whole-game source identity repair must be child-based and use the resumable dependency workflow.'
);

$matches = $read('src/Infrastructure/Jobs/CatalogUnverifiedGameMatchRefreshJobHandler.php');
$check(
    'bucket_match_refresh_is_child_workflow',
    str_contains($matches, 'bucket_match_plan')
        && str_contains($matches, "'match:' . \$fileId")
        && str_contains($matches, 'bucket_match_wait'),
    'Bucket-wide exact matching must retain successful per-file cache results.'
);

$backupImport = $read('src/Infrastructure/Jobs/GameBackupImportJobHandler.php');
$check(
    'backup_import_is_entry_workflow',
    str_contains($backupImport, 'IMPORT_GAME_BACKUP_ENTRY')
        && str_contains($backupImport, 'backup_import_wait_canonical')
        && str_contains($backupImport, 'backup_import_wait_aliases')
        && str_contains($backupImport, "'dependencies'"),
    'Backup restore must retain successful manifest entries, preserve canonical-before-alias ordering and nest the resumable dependency workflow.'
);

$sourceDiscovery = $read('src/Infrastructure/Source/CatalogSourceScanDiscovery.php');
$sourceRunner = $read('src/Infrastructure/Source/CatalogSourceScanRunner.php');
$sourceHandler = $read('src/Infrastructure/Jobs/CatalogSourceScanJobHandler.php');
$check(
    'source_scan_has_exact_restart_cursor',
    str_contains($sourceDiscovery, 'usort($files')
        && str_contains($sourceRunner, 'scan_last_relative_path')
        && str_contains($sourceHandler, '$context->checkpoint($progress)')
        && str_contains($sourceHandler, 'source_containers_complete'),
    'Loose-source scanning must checkpoint every completed path in deterministic order and retain the container-preparation phase.'
);

$logging = $read('src/Infrastructure/Jobs/CatalogJobLoggingSettingsStore.php');
$eventLog = $read('src/Infrastructure/Jobs/CatalogJobEventLog.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$check(
    'job_logging_defaults_to_actionable_errors',
    str_contains($logging, "'event_errors'")
        && str_contains($logging, "'default' => true")
        && str_contains($logging, "'event_success'")
        && str_contains($logging, "'event_progress'")
        && str_contains($eventLog, 'shouldWriteEvent($status)')
        && str_contains($factory, "'source_kind' => 'background-job'"),
    'Routine event streams must be suppressible while terminal background-job failures still enter System Errors.'
);

$browserScope = $read('src/Infrastructure/Persistence/PdoBackgroundJobSearchScope.php');
$check(
    'routine_workflow_children_are_hidden_by_default',
    str_contains($browserScope, 'j.parent_job_id IS NULL')
        && str_contains($browserScope, '"failed","dead_letter","cancelled"'),
    'The normal operator queue must show parent workflows and child units needing attention, not thousands of successful units.'
);

if ($withDatabase) {
    try {
        require_once $root . '/bootstrap.php';
        $application = catalog_bootstrap(false);
        $db = $application->db;
        $columns = $db->query(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_background_jobs" '
            . 'AND COLUMN_NAME IN ("parent_job_id","workflow_unit_key")'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $check(
            'database_workflow_columns',
            in_array('parent_job_id', $columns, true) && in_array('workflow_unit_key', $columns, true),
            'Apply migration 202608120001 before starting workers.'
        );
        $settings = (int)$db->query(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_job_logging_settings"'
        )->fetchColumn();
        $check('database_job_logging_settings', $settings === 1, 'Apply migration 202608120001.');
    } catch (Throwable $error) {
        $check('database_checks', false, get_class($error) . ': ' . $error->getMessage());
    }
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
