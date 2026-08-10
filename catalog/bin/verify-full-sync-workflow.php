#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies the durable compact-only Full Sync workflow before a game-wide rescan.
 * Role: Read-only source and optional database regression gate for background Full Sync maintenance.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

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
    $content = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($content) ? $content : '';
};

$phpFiles = [
    'full-sync.php',
    'api/v1/full-sync-job.php',
    'src/Application/Jobs/JobExecutionContext.php',
    'src/Domain/Jobs/JobType.php',
    'src/Domain/Jobs/JobResourcePolicy.php',
    'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'src/Infrastructure/Jobs/CatalogFullSyncJobHandler.php',
    'src/Infrastructure/Maintenance/CatalogFullSyncDependencyBatchService.php',
    'src/Infrastructure/Maintenance/CatalogFullSyncProjectionService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceActionService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceReimportService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceRemovalService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceSupport.php',
    'src/Infrastructure/Import/PdoCatalogPackageImporter.php',
    'src/Infrastructure/Persistence/PdoCatalogDependencyRebuilder.php',
    'src/Infrastructure/Persistence/PdoCatalogVerifiedPackagePersistence.php',
    'src/Infrastructure/Persistence/PdoPackageProviderRepository.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php',
    'src/Infrastructure/Storage/CatalogVerifiedPackageStorage.php',
];

if (!function_exists('proc_open')) {
    $record('php_syntax', false, 'proc_open unavailable; run php -l on the Full Sync PHP files manually.');
} else {
    $syntaxFailures = [];
    foreach ($phpFiles as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            $syntaxFailures[] = $relative . ' is missing';
            continue;
        }
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-l', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
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
    $record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));
}

$page = $read('full-sync.php');
$client = $read('full-sync.js');
$api = $read('api/v1/full-sync-job.php');
$jobType = $read('src/Domain/Jobs/JobType.php');
$resourcePolicy = $read('src/Domain/Jobs/JobResourcePolicy.php');
$workerFactory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$executionContext = $read('src/Application/Jobs/JobExecutionContext.php');
$handler = $read('src/Infrastructure/Jobs/CatalogFullSyncJobHandler.php');

$record(
    'full_sync_is_durable_background_job',
    str_contains($page, 'api/v1/full-sync-job.php')
        && str_contains($page, 'Background Jobs')
        && str_contains($page, 'System Errors')
        && !str_contains($page, 'full-sync-files')
        && str_contains($client, 'Queueing durable Full Sync job')
        && !str_contains($client, "'sync_reimport'")
        && !str_contains($client, 'DEPENDENCY_BATCH_SIZE'),
    'The browser must enqueue one durable job and must not own the multi-hour package/dependency loop.'
);
$record(
    'full_sync_job_type_registered',
    str_contains($jobType, "FULL_SYNC_GAME = 'catalog.full_sync_game'")
        && str_contains($jobType, 'self::FULL_SYNC_GAME')
        && str_contains($workerFactory, 'new CatalogFullSyncJobHandler')
        && str_contains($workerFactory, 'JobType::FULL_SYNC_GAME => $fullSync'),
    'The durable Full Sync type must be part of the domain contract and explicit worker dispatch map.'
);
$record(
    'full_sync_enqueue_is_deduplicated',
    str_contains($api, 'JobType::FULL_SYNC_GAME')
        && str_contains($api, "'full-sync-game:' . \$gameId")
        && str_contains($api, "'initial_verified_files'")
        && str_contains($api, "'requested_by'")
        && preg_match('/full-sync-game:[\s\S]*?\$userId,\s*1\s*\)/m', $api) === 1,
    'Only one active Full Sync per game should be queued and automatic retries must not silently repeat an eight-hour run.'
);
$record(
    'full_sync_job_resource_policy',
    str_contains($resourcePolicy, 'JobType::FULL_SYNC_GAME')
        && str_contains($resourcePolicy, 'self::DEPENDENCY_HEAVY')
        && str_contains($resourcePolicy, 'self::PROJECTION_CONCURRENCY_KEY')
        && str_contains($executionContext, 'JobType::FULL_SYNC_GAME'),
    'Full Sync must consume one dependency-heavy worker slot and retain a long renewable lease while parsing a package.'
);

$reimportPosition = strpos($handler, "execute('sync_reimport'");
$preparePosition = strpos($handler, 'prepareDependencies($gameId)');
$dependencyPosition = strpos($handler, 'CatalogFullSyncDependencyBatchService');
$finalizePosition = strpos($handler, 'finalize($gameId)');
$record(
    'full_sync_four_phase_order',
    $reimportPosition !== false
        && $preparePosition !== false
        && $dependencyPosition !== false
        && $finalizePosition !== false
        && $reimportPosition < $preparePosition
        && $preparePosition < $dependencyPosition
        && $dependencyPosition < $finalizePosition,
    'The worker must refresh package identities before providers, bounded dependency batches and final projections.'
);
$record(
    'full_sync_worker_verified_scope',
    str_contains($handler, 'WHERE game_id=? AND scan_status="verified"')
        && str_contains($handler, 'CatalogFileMaintenanceActionService')
        && str_contains($handler, 'CatalogFullSyncDependencyBatchService::MAX_BATCH_SIZE'),
    'The worker must snapshot verified package identities and refresh current verified files only.'
);
$record(
    'full_sync_worker_cancellable_and_observable',
    str_contains($handler, 'JobCancellationRequested')
        && str_contains($handler, '$context->checkpoint(')
        && str_contains($handler, '$context->heartbeatIfDue(')
        && str_contains($handler, 'CatalogSystemErrorRecorder::record')
        && str_contains($handler, "'source_kind' => 'full-sync-job'"),
    'Long-running Full Sync work must heartbeat, honor cancellation checkpoints and preserve per-package failures in System Errors.'
);
$record(
    'full_sync_worker_does_not_hold_global_lock',
    !str_contains($handler, 'unrealdb_catalog_maintenance_write_v1')
        && !str_contains($handler, 'GET_LOCK('),
    'The coordinator must not monopolize the global identity-write lock across the whole game.'
);

$batchService = $read('src/Infrastructure/Maintenance/CatalogFullSyncDependencyBatchService.php');
$record(
    'full_sync_dependency_batches_bounded',
    str_contains($batchService, 'public const MAX_BATCH_SIZE = 100')
        && str_contains($batchService, 'PdoCatalogDependencyRebuilder')
        && str_contains($batchService, "'summary_refresh_deferred' => true")
        && str_contains($batchService, 'catch (Throwable $error)'),
    'Dependency resolution must remain bounded to 100 owners with isolated per-file failures and deferred bulk summaries.'
);

$reimport = $read('src/Infrastructure/Maintenance/CatalogFileMaintenanceReimportService.php');
$record(
    'full_sync_reimport_preserves_file_identity',
    str_contains($reimport, "'maintenance_replace_file_id' => \$fileId")
        && str_contains($reimport, 'stable file ID preserved=')
        && str_contains($reimport, 'restoreExistingSnapshot($snapshot)')
        && !str_contains($reimport, 'DELETE FROM ue_files'),
    'Successful and failed maintenance reparses must retain the ue_files ID and unrelated relationships.'
);
$record(
    'full_sync_reimport_defers_async_reconciliation',
    str_contains($reimport, 'if (!$deferDependencyRefresh)')
        && str_contains($reimport, 'CatalogProjectionReconciliationQueue::enqueue'),
    'Full Sync reparses must not flood the queue with per-package reconciliation jobs.'
);

$importer = $read('src/Infrastructure/Import/PdoCatalogPackageImporter.php');
$persistence = $read('src/Infrastructure/Persistence/PdoCatalogVerifiedPackagePersistence.php');
$storage = $read('src/Infrastructure/Storage/CatalogVerifiedPackageStorage.php');
$record(
    'maintenance_importer_stable_id_contract',
    str_contains($importer, 'maintenance_replace_file_id')
        && str_contains($importer, 'AND id<>?')
        && str_contains($persistence, 'int $replaceFileId = 0')
        && str_contains($persistence, 'UPDATE ue_files SET '),
    'Maintenance refresh must exclude its own identity from duplicate detection and update that row in place.'
);
$record(
    'maintenance_scanner_copy_cleanup_is_windows_safe',
    str_contains($importer, '$maintenanceReplaceFileId === 0')
        && str_contains($storage, 'bool $discardDuplicateSource = true')
        && str_contains($storage, 'if ($discardDuplicateSource'),
    'Stable-ID maintenance must leave its scanner copy for outer cleanup after reader use on Windows.'
);

$metadataWriter = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php');
$statClearPosition = strrpos($metadataWriter, 'clearstatcache(true, $path)');
$verifyPosition = strpos($metadataWriter, 'new BlockedCompressedMetadataReader', $statClearPosition === false ? 0 : $statClearPosition);
$record(
    'blocked_metadata_publish_clears_stat_cache',
    $statClearPosition !== false
        && $verifyPosition !== false
        && $statClearPosition < $verifyPosition,
    'Replacing a stable .uedb2 path must invalidate PHP stat caching before comparing the new file size with ue_file_metadata.'
);

$removal = $read('src/Infrastructure/Maintenance/CatalogFileMaintenanceRemovalService.php');
$record(
    'full_sync_missing_file_removal_is_explicit',
    str_contains($removal, 'bool $deferDependencyRefresh = false')
        && str_contains($removal, 'deleteFileProjections($fileId)')
        && str_contains($removal, 'DELETE FROM ue_files WHERE id=?'),
    'Only genuinely missing stored packages should use destructive catalog removal during Full Sync.'
);

$providers = $read('src/Infrastructure/Persistence/PdoPackageProviderRepository.php');
$projectionService = $read('src/Infrastructure/Maintenance/CatalogFullSyncProjectionService.php');
$record(
    'full_sync_projection_finalization',
    str_contains($providers, 'function reconcileGame(int $gameId)')
        && str_contains($projectionService, 'prepareDependencies')
        && str_contains($projectionService, 'reconcileGame($gameId)')
        && str_contains($projectionService, 'rebuildFiles($fileIds)')
        && str_contains($projectionService, 'rebuildGame($gameId)'),
    'Finalization must leave providers, dependency summaries and cached game statistics current.'
);

if ($withDatabase) {
    try {
        require_once $root . '/bootstrap.php';
        $application = catalog_bootstrap();
        $schema = new SchemaInspector($application->db);
        foreach ([
            'ue_background_jobs',
            'ue_files',
            'ue_file_metadata',
            'ue_export_lookup',
            'ue_dependency_links',
            'ue_package_providers',
            'ue_dependency_package_summaries',
            'ue_game_catalog_stats',
        ] as $table) {
            $record('db_table:' . $table, $schema->tableExists($table));
        }

        $missingFormat2 = (int)$application->db->query(
            'SELECT COUNT(*) FROM ue_files f LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'WHERE f.scan_status="verified" AND (m.file_id IS NULL OR m.format_version<>2)'
        )->fetchColumn();
        $record('db_verified_format2_coverage', $missingFormat2 === 0, 'verified_without_format2=' . $missingFormat2);
    } catch (Throwable $error) {
        $record('database_checks', false, get_class($error) . ': ' . $error->getMessage());
    }
}

$result = [
    'ok' => $failures === [],
    'database_checked' => $withDatabase,
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
