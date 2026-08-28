#!/usr/bin/env php
<?php
/**
 * Read-only architectural regression gate for durable/recoverable background work.
 *
 * This deliberately checks design boundaries rather than implementation output:
 * long operations must decompose into durable units or persist an exact cursor,
 * restart paths must retain recovery state, browser upload transport must end
 * before resumable server work begins, and routine event logging must stay
 * independently suppressible from durable progress/result storage.
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

// -------------------------------------------------------------------------
// Queue/restart foundation
// -------------------------------------------------------------------------
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
    $destructive === []
        ? 'No recovery/restart path erases the durable progress snapshot.'
        : implode(' | ', $destructive)
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
$claimedJob = $read('src/Domain/Jobs/ClaimedJob.php');
$check(
    'coordinator_wait_releases_worker_without_failure',
    str_contains($context, 'public function defer(')
        && str_contains($worker, 'catch (JobDeferred $deferred)')
        && str_contains($leaseStore, 'attempts=GREATEST(attempts-1,0)'),
    'Waiting on children must not occupy a worker slot or consume retry attempts.'
);
$check(
    'claimed_job_exposes_previous_checkpoint',
    str_contains($claimedJob, 'resumeProgress')
        && str_contains($context, 'resumeProgress'),
    'A recovered handler must receive the last durable checkpoint instead of inferring position from display text.'
);

// -------------------------------------------------------------------------
// Long workflows
// -------------------------------------------------------------------------
$fullSync = $read('src/Infrastructure/Jobs/CatalogFullSyncJobHandler.php');
$check(
    'full_sync_is_parent_child_workflow',
    str_contains($fullSync, 'JobType::FULL_SYNC_FILE')
        && str_contains($fullSync, 'JobType::FULL_SYNC_DEPENDENCY_FILE')
        && str_contains($fullSync, 'full_sync_finalize')
        && !str_contains($fullSync, "execute('sync_reimport'")
        && !str_contains($fullSync, 'foreach ($files'),
    'Full Sync may plan/wait/finalize but must not run the multi-hour file loop inline.'
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

$affected = $read('src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php');
$check(
    'affected_dependencies_are_per_file_units',
    str_contains($affected, "'affected_file_id' => \$affectedFileId")
        && str_contains($affected, "'affected:' . \$affectedFileId")
        && str_contains($affected, 'handleFileUnit(')
        && str_contains($affected, 'aggregateFileUnits(')
        && str_contains($affected, 'handleLegacyBatch('),
    'New affected-dependency work must retry one affected file; old queued 50-file batches may only remain as cursor-preserving compatibility.'
);

$projection = $read('src/Infrastructure/Jobs/CatalogProjectionReconciliationJobHandler.php');
$check(
    'projection_reconciliation_is_per_file_workflow',
    str_contains($projection, 'JobType::RECONCILE_CATALOG_PROJECTION_FILE')
        && str_contains($projection, "'affected:' . \$affectedFileId")
        && str_contains($projection, 'reconcileFileUnit(')
        && str_contains($projection, 'aggregateUnitResults('),
    'Projection reconciliation must isolate each dependency-owner failure instead of collecting failures in one parent loop.'
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

$crossGame = $read('src/Infrastructure/Jobs/CatalogCrossGameCopyBatchJobHandler.php');
$check(
    'cross_game_copy_preparation_is_per_source_file',
    str_contains($crossGame, "'source_file_id' => \$sourceFileId")
        && str_contains($crossGame, "'source:' . \$sourceFileId")
        && str_contains($crossGame, 'prepareOne(')
        && str_contains($crossGame, 'workflow_parent_job_id'),
    'Cross-game copy preparation must make an unexpected revalidation error restartable for one selected source file only.'
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

$backupExport = $read('src/Infrastructure/Jobs/GameBackupExportJobHandler.php');
$check(
    'backup_export_uses_durable_plan_and_journal',
    str_contains($backupExport, 'completion journal')
        || (str_contains($backupExport, 'plan') && str_contains($backupExport, 'journal')),
    'Backup export must adopt/verify completed copies and continue from its durable plan instead of deleting an incomplete backup and starting over.'
);
$check(
    'backup_export_does_not_delete_incomplete_backup_on_restart',
    !str_contains($backupExport, '$store->delete($backupKey);'),
    'Restarting an incomplete backup must not erase already verified copied files.'
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

$pak = $read('src/Infrastructure/Jobs/CatalogPakImportJobHandler.php');
$pakWorkspace = $read('src/Infrastructure/Jobs/CatalogPakImportWorkspace.php');
$pakStore = $read('src/Infrastructure/Storage/CatalogPakArchiveStore.php');
$jobType = $read('src/Domain/Jobs/JobType.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$check(
    'pak_import_has_durable_entry_workflow',
    str_contains($jobType, 'IMPORT_STAGED_PAK_ENTRY')
        && str_contains($pak, 'JobType::IMPORT_STAGED_PAK_ENTRY')
        && str_contains($pak, "'pak-entry:' . \$entryIndex")
        && str_contains($pak, 'importEntry(')
        && str_contains($pak, 'pak_dependency_wait')
        && str_contains($pakWorkspace, "DIRECTORY_SEPARATOR . 'pak-import'")
        && str_contains($pakStore, 'ensureEntry(')
        && str_contains($factory, 'JobType::IMPORT_STAGED_PAK_ENTRY => $pakImport'),
    'PAK extraction/index state must be durable, entry imports independently restartable, and the worker factory must execute those child units.'
);

$duplicates = $read('src/Infrastructure/Jobs/UnverifiedDuplicateCleanupJobHandler.php');
$inventory = $read('src/Infrastructure/Unverified/LegacyUnverifiedQueueInventory.php');
$check(
    'unverified_duplicate_cleanup_has_hash_delete_units',
    str_contains($jobType, 'HASH_UNVERIFIED_DUPLICATE')
        && str_contains($jobType, 'DELETE_UNVERIFIED_DUPLICATE')
        && str_contains($duplicates, 'JobType::HASH_UNVERIFIED_DUPLICATE')
        && str_contains($duplicates, 'JobType::DELETE_UNVERIFIED_DUPLICATE')
        && str_contains($duplicates, "'hash:' . \$key")
        && str_contains($duplicates, "'delete:' . \$queueKey")
        && !str_contains($duplicates, 'deleteDuplicates(')
        && str_contains($inventory, 'public function paths('),
    'Duplicate hashing/deletion must be independently recoverable and delete retries must finish metadata cleanup even after bytes were already unlinked.'
);

$storageMaintenance = $read('src/Infrastructure/Jobs/CatalogStorageMaintenanceJobHandler.php');
$check(
    'unverified_storage_reconciliation_is_per_file',
    str_contains($storageMaintenance, "'reconcile_queue_name' =>")
        && str_contains($storageMaintenance, "'reconcile:' .")
        && str_contains($storageMaintenance, 'reconcileOne('),
    'Unverified storage reconciliation must not turn per-file failures into one parent error summary.'
);
$check(
    'artifact_cleanup_is_split_by_category',
    str_contains($storageMaintenance, "$storageOnly ? ['job_storage'] : ['generated', 'job_storage']")
        && str_contains($storageMaintenance, "'prune:' . \$unit")
        && str_contains($storageMaintenance, 'pruneOne(')
        && str_contains($storageMaintenance, "'chunked_uploads' => \$chunkedUploads"),
    'Generated artifacts and complete job storage are independently restartable; chunked uploads are reclaimed inside the ownership-aware job-storage unit.'
);

// -------------------------------------------------------------------------
// Upload recovery boundary
// -------------------------------------------------------------------------
$profiledUpload = $read('profiled-upload.php');
$profiledChunk = $read('api/v1/profiled-upload-chunk.php');
$incomingStore = $read('src/Infrastructure/Import/CatalogIncomingFileStore.php');
$profiledQueue = $read('src/Infrastructure/Import/CatalogProfiledUploadQueue.php');
$bucketChunk = $read('api/v1/upload-bucket-chunk.php');
$bucketBatch = $read('api/v1/upload-bucket-batch.php');
$bucketHandler = $read('src/Infrastructure/Jobs/CatalogBucketUploadJobHandler.php');
$preparedStore = $read('src/Infrastructure/Jobs/CatalogPreparedJobFileStore.php');
$nonBlockingImport = $read('src/Infrastructure/Jobs/CatalogNonBlockingImportJobHandler.php');
$stagedImport = $read('src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php');
$storageCleanup = $read('src/Infrastructure/Jobs/CatalogJobStorageCleanup.php');

$directStagePosition = strpos($profiledUpload, '$store->stageUploadedFile(');
$directQueuePosition = strpos($profiledUpload, '$queue->enqueueStaged(');
$profiledCompletePosition = strpos($profiledChunk, '$store->complete(');
$profiledChunkQueuePosition = strpos($profiledChunk, '$queue->enqueueStaged(');
$profiledPakQueuePosition = strpos($profiledChunk, '$queue->enqueueChunkedPak(');
$bucketCompletePosition = strpos($bucketChunk, '$store->complete(');
$bucketFinalizePosition = strpos($bucketBatch, 'CatalogBucketBatchFinalizer');

$check(
    'direct_game_upload_stages_complete_file_before_job',
    $directStagePosition !== false
        && $directQueuePosition !== false
        && $directStagePosition < $directQueuePosition
        && str_contains($incomingStore, "DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'incoming'")
        && str_contains($profiledQueue, 'JobType::IMPORT_STAGED_PACKAGE'),
    'Direct-to-game upload recovery begins only after the complete file has moved into controlled server staging.'
);

$check(
    'chunked_game_upload_completes_before_job',
    $profiledCompletePosition !== false
        && (($profiledChunkQueuePosition !== false && $profiledCompletePosition < $profiledChunkQueuePosition)
            || ($profiledPakQueuePosition !== false && $profiledCompletePosition < $profiledPakQueuePosition))
        && str_contains($profiledUpload, 'recovery begins after the complete file is durably staged'),
    'Chunk transport is not the recovery contract; the completed server file must exist before a game import job is created.'
);

$check(
    'upload_bucket_completes_transfer_before_processing_job',
    $bucketCompletePosition !== false
        && $bucketFinalizePosition !== false
        && str_contains($bucketBatch, 'completed Upload Bucket source identifier')
        && str_contains($bucketHandler, 'resolveCompletedFile($uploadId, $userId)'),
    'Upload Bucket processing must consume a completed durable source; an incomplete browser transfer is not resumable processing work.'
);

$check(
    'upload_ui_does_not_claim_browser_session_recovery',
    !str_contains($profiledUpload, 'use resumable chunks')
        && !str_contains($read('upload-bucket-v2.php'), 'upload it in resumable chunks')
        && str_contains($profiledUpload, 'Chunking does not make an interrupted browser upload session recoverable')
        && str_contains($read('upload-bucket-v2.php'), 'An incomplete browser transfer is not a resumable background job'),
    'Only post-complete server-side processing may be described as resumable/recoverable.'
);

$check(
    'direct_game_redirect_preparation_is_reusable',
    str_contains($preparedStore, "DIRECTORY_SEPARATOR . 'prepared'")
        && str_contains($nonBlockingImport, "new CatalogPreparedJobFileStore(\$this->config, \$job->id, 'redirect')")
        && str_contains($nonBlockingImport, '$preparedStore->load()')
        && str_contains($nonBlockingImport, '$preparedStore->publish(')
        && str_contains($nonBlockingImport, '$job->resumeProgress')
        && str_contains($stagedImport, 'prepared_source_persistent')
        && str_contains($stagedImport, '$this->workingSource($sourcePath, $workingName, $context, 46, 48)'),
    'A completed direct-to-game redirect decompression must survive infrastructure retry/cancellation and feed the importer through a disposable working path.'
);

$check(
    'upload_bucket_preparation_and_staging_are_phase_resumable',
    str_contains($bucketHandler, "new CatalogPreparedJobFileStore(\$this->config, \$job->id, 'bucket-package')")
        && str_contains($bucketHandler, "'stage' => 'package_prepared'")
        && str_contains($bucketHandler, "'stage' => 'bucket_staged'")
        && str_contains($bucketHandler, 'finalizeStagedCheckpoint(')
        && str_contains($bucketHandler, 'workingFromPrepared(')
        && str_contains($bucketHandler, 'progress_json must remain the recovery checkpoint'),
    'A Bucket retry must reuse completed copy/hash/decompression and a post-staging retry must perform cleanup only.'
);

$check(
    'recovery_artifacts_follow_restartable_job_lifecycle',
    str_contains($storageCleanup, "['queued', 'running', 'failed', 'dead_letter', 'cancelled']")
        && str_contains($storageCleanup, "DIRECTORY_SEPARATOR . 'prepared'")
        && str_contains($storageCleanup, "DIRECTORY_SEPARATOR . 'pak-import'")
        && str_contains($storageCleanup, 'status IN ("queued","running","failed","dead_letter","cancelled")'),
    'Incoming/prepared/archive recovery files must survive restartable states but completed historical rows must not pin disk storage forever.'
);

// -------------------------------------------------------------------------
// Logging/operator view/resource integration
// -------------------------------------------------------------------------
$logging = $read('src/Infrastructure/Jobs/CatalogJobLoggingSettingsStore.php');
$eventLog = $read('src/Infrastructure/Jobs/CatalogJobEventLog.php');
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
$hydrator = $read('src/Infrastructure/Jobs/CatalogBackgroundJobResultHydrator.php');
$check(
    'routine_workflow_children_are_hidden_by_default',
    str_contains($browserScope, 'j.parent_job_id IS NULL')
        && str_contains($browserScope, '"failed","dead_letter","cancelled"'),
    'The normal operator queue must show parent workflows and child units needing attention, not thousands of successful units.'
);
$check(
    'failed_child_rows_preserve_real_unit_identity',
    str_contains($hydrator, 'affected_file_id')
        && str_contains($hydrator, 'source_file_id')
        && str_contains($hydrator, 'entry_index')
        && str_contains($hydrator, 'reconcile_queue_name')
        && str_contains($hydrator, 'prune_unit'),
    'A surfaced failed child must identify the affected file/archive entry/source/maintenance unit instead of looking like a duplicate parent job.'
);

$resourcePolicy = $read('src/Domain/Jobs/JobResourcePolicy.php');
$check(
    'resource_policy_covers_new_child_classes',
    str_contains($resourcePolicy, 'UNVERIFIED_FILE_MAINTENANCE')
        && str_contains($resourcePolicy, 'RECONCILE_CATALOG_PROJECTION_FILE')
        && str_contains($resourcePolicy, 'IMPORT_STAGED_PAK_ENTRY')
        && str_contains($resourcePolicy, 'HASH_UNVERIFIED_DUPLICATE')
        && str_contains($resourcePolicy, 'DELETE_UNVERIFIED_DUPLICATE')
        && str_contains($resourcePolicy, "'affected_file_id'"),
    'Per-unit jobs require explicit bounded resource classes/concurrency keys rather than falling through to generic scheduling.'
);
$check(
    'worker_factory_registers_every_new_child_type',
    str_contains($factory, 'JobType::RECONCILE_CATALOG_PROJECTION_FILE => $projectionReconciliation')
        && str_contains($factory, 'JobType::IMPORT_STAGED_PAK_ENTRY => $pakImport')
        && str_contains($factory, 'JobType::HASH_UNVERIFIED_DUPLICATE => $duplicateCleanup')
        && str_contains($factory, 'JobType::DELETE_UNVERIFIED_DUPLICATE => $duplicateCleanup'),
    'Creating a durable child type without a worker registration would leave the workflow permanently queued.'
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
        $matchCache = (int)$db->query(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_unverified_game_match_cache"'
        )->fetchColumn();
        $check('database_unverified_game_match_cache', $matchCache === 1, 'Apply migration 202608110001.');
    } catch (Throwable $error) {
        $check('database_checks', false, get_class($error) . ': ' . $error->getMessage());
    }
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
