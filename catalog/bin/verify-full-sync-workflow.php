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
    'parsers/EpicUE3PackageReader.php',
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
    'src/Infrastructure/Persistence/PdoCatalogVerifiedPackagePersistence.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php',
    'src/Infrastructure/Metadata/CatalogParsedPackageMetadataSnapshotBuilder.php',
    'src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php',
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
        && str_contains($workerFactory, 'new CatalogFullSyncJobHandler')
        && str_contains($workerFactory, 'JobType::FULL_SYNC_GAME => $fullSync'),
    'The Full Sync type must be in the domain contract and explicit worker dispatch map.'
);
$record(
    'full_sync_enqueue_is_deduplicated',
    str_contains($api, 'JobType::FULL_SYNC_GAME')
        && str_contains($api, "'full-sync-game:' . \$gameId")
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
    'Full Sync must consume a dependency-heavy slot and use the long renewable package-reader lease.'
);

$reimportPosition = strpos($handler, "execute('sync_reimport'");
$preparePosition = strpos($handler, 'prepareDependencies($gameId)');
$dependencyPosition = $preparePosition === false
    ? false
    : strpos($handler, 'new CatalogFullSyncDependencyBatchService', $preparePosition);
$finalizePosition = $dependencyPosition === false
    ? false
    : strpos($handler, 'finalize($gameId)', $dependencyPosition);
$record(
    'full_sync_four_phase_order',
    $reimportPosition !== false
        && $preparePosition !== false
        && $dependencyPosition !== false
        && $finalizePosition !== false
        && $reimportPosition < $preparePosition
        && $preparePosition < $dependencyPosition
        && $dependencyPosition < $finalizePosition,
    'The worker must reimport identities, rebuild providers, refresh bounded dependency batches, then finalize projections.'
);
$record(
    'full_sync_worker_verified_scope',
    str_contains($handler, 'WHERE game_id=? AND scan_status="verified"')
        && str_contains($handler, 'CatalogFullSyncDependencyBatchService::MAX_BATCH_SIZE'),
    'The durable worker must operate only on verified packages and bounded dependency batches.'
);
$record(
    'full_sync_worker_cancellable_and_observable',
    str_contains($handler, 'JobCancellationRequested')
        && str_contains($handler, '$context->checkpoint(')
        && str_contains($handler, '$context->heartbeatIfDue(')
        && str_contains($handler, 'CatalogSystemErrorRecorder::record')
        && str_contains($handler, "'source_kind' => 'full-sync-job'"),
    'Full Sync must heartbeat, honor cancellation checkpoints and persist individual failures in System Errors.'
);
$record(
    'full_sync_worker_does_not_hold_global_lock',
    !str_contains($handler, 'unrealdb_catalog_maintenance_write_v1')
        && !str_contains($handler, 'GET_LOCK('),
    'The coordinator must release identity-write serialization between packages rather than monopolizing the site for hours.'
);

$batchService = $read('src/Infrastructure/Maintenance/CatalogFullSyncDependencyBatchService.php');
$record(
    'full_sync_dependency_batches_bounded',
    str_contains($batchService, 'public const MAX_BATCH_SIZE = 100')
        && str_contains($batchService, 'PdoCatalogDependencyRebuilder')
        && str_contains($batchService, "'summary_refresh_deferred' => true")
        && str_contains($batchService, 'catch (Throwable $error)'),
    'Dependency resolution must stay bounded to 100 owners with isolated per-file failures.'
);

$reimport = $read('src/Infrastructure/Maintenance/CatalogFileMaintenanceReimportService.php');
$maintenanceSupport = $read('src/Infrastructure/Maintenance/CatalogFileMaintenanceSupport.php');
$record(
    'full_sync_reimport_preserves_file_identity',
    str_contains($reimport, "'maintenance_replace_file_id' => \$fileId")
        && str_contains($reimport, 'stable file ID preserved=')
        && str_contains($reimport, 'restoreExistingSnapshot($snapshot)')
        && !str_contains($reimport, 'DELETE FROM ue_files'),
    'Maintenance reparse must retain the existing ue_files ID and restore the compact snapshot on failure.'
);
$record(
    'full_sync_reimport_repairs_unreadable_compact_metadata',
    str_contains($reimport, '$support->reimportState($fileId)')
        && str_contains($reimport, '$support->snapshot($fileId)')
        && str_contains($reimport, 'Existing compact metadata is unreadable; rebuilding it from the authoritative stored package')
        && str_contains($reimport, '$support->restoreReimportFileRow($reimportState)')
        && str_contains($reimport, 'repaired unreadable compact metadata from authoritative package')
        && str_contains($maintenanceSupport, 'public function reimportState(int $fileId)')
        && str_contains($maintenanceSupport, 'public function restoreReimportFileRow(array $state)'),
    'A missing/truncated/corrupt .uedb2 must not be a prerequisite for reparsing the authoritative stored package.'
);
$record(
    'full_sync_reimport_scopes_validated_metadata_baseline',
    str_contains($reimport, 'VerifiedFileCompactMetadataFinalizer::setMaintenanceBaseline($fileId, $snapshot)')
        && str_contains($reimport, 'VerifiedFileCompactMetadataFinalizer::clearMaintenanceBaseline($fileId)')
        && str_contains($reimport, 'finally {'),
    'A valid pre-reimport snapshot must be available only for the synchronous parser comparison and always cleared afterward.'
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
        && str_contains($storage, 'bool $discardDuplicateSource = true'),
    'Stable-ID maintenance must leave the scanner copy for outer cleanup after reader use on Windows.'
);

$metadataWriter = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php');
$statClearPosition = strrpos($metadataWriter, 'clearstatcache(true, $path)');
$verifyPosition = $statClearPosition === false
    ? false
    : strpos($metadataWriter, 'new BlockedCompressedMetadataReader', $statClearPosition);
$record(
    'blocked_metadata_publish_clears_stat_cache',
    $statClearPosition !== false && $verifyPosition !== false && $statClearPosition < $verifyPosition,
    'Replacing the same .uedb2 path must invalidate PHP stat caching before size/hash verification.'
);

$metadataFinalizer = $read('src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php');
$parsedBuilder = $read('src/Infrastructure/Metadata/CatalogParsedPackageMetadataSnapshotBuilder.php');
$record(
    'parsed_reimport_compares_before_dependency_rebuild',
    str_contains($metadataFinalizer, 'buildParsedSections(')
        && str_contains($metadataFinalizer, 'parsedContentFingerprint($parsed)')
        && str_contains($metadataFinalizer, 'parsedContentFingerprint($baselineMetadata)')
        && str_contains($metadataFinalizer, "'reused_unchanged' => true")
        && str_contains($metadataFinalizer, '$builder->withDependencies($parsed)')
        && str_contains($metadataFinalizer, 'BlockedCompressedMetadataSnapshotWriter')
        && str_contains($parsedBuilder, 'public function buildParsedSections(')
        && str_contains($parsedBuilder, 'public function withDependencies(array $snapshot)')
        && str_contains($parsedBuilder, 'public static function parsedContentFingerprint(array $snapshot)'),
    'Full Sync must reuse validated unchanged metadata and only resolve dependencies/write .uedb2 when parser-owned content changed or the old container was unreadable.'
);

try {
    require_once $root . '/src/Infrastructure/Metadata/CatalogParsedPackageMetadataSnapshotBuilder.php';
    $base = [
        'file' => [
            'id' => 7,
            'game_id' => 6,
            'package_name' => 'Example',
            'original_name' => 'Example.u',
            'name_count' => 1,
            'import_count' => 1,
            'export_count' => 1,
            'scan_status' => 'verified',
        ],
        'names' => [[
            'id' => 30064771073,
            'file_id' => 7,
            'name_index' => 0,
            'name_text' => 'Example',
            'flags' => 1,
        ]],
        'imports' => [[
            'id' => 30064771073,
            'file_id' => 7,
            'import_index' => 0,
            'class_package' => 'Core',
            'class_name' => 'Class',
            'object_name' => 'Thing',
            'outer_index' => 0,
            'full_path' => 'Core.Thing',
            'root_package' => 'Core',
            'relative_object_path' => 'Thing',
            'is_common' => 1,
        ]],
        'exports' => [[
            'id' => 30064771073,
            'file_id' => 7,
            'export_index' => 0,
            'class_name' => 'Core.Class',
            'object_name' => 'Thing',
            'outer_index' => 0,
            'local_path' => 'Thing',
            'full_path' => 'Example.Thing',
            'object_flags' => 2,
            'serial_size' => 10,
            'serial_offset' => 20,
        ]],
    ];
    $loadedEquivalent = $base;
    $loadedEquivalent['names'][0]['id'] = 1;
    $loadedEquivalent['names'][0]['flags'] = '1';
    $loadedEquivalent['imports'][0]['id'] = 1;
    $loadedEquivalent['exports'][0]['id'] = 1;
    $loadedEquivalent['exports'][0]['object_flags'] = '2';
    $loadedEquivalent['exports'][0]['serial_size'] = '10';
    $loadedEquivalent['exports'][0]['serial_offset'] = '20';

    $fingerprintClass = UnrealDb\Catalog\Infrastructure\Metadata\CatalogParsedPackageMetadataSnapshotBuilder::class;
    $baseFingerprint = $fingerprintClass::parsedContentFingerprint($base);
    $loadedFingerprint = $fingerprintClass::parsedContentFingerprint($loadedEquivalent);
    $changed = $loadedEquivalent;
    $changed['names'][0]['name_text'] = 'Changed';
    $changedFingerprint = $fingerprintClass::parsedContentFingerprint($changed);
    $record(
        'parsed_metadata_fingerprint_semantics',
        $baseFingerprint === $loadedFingerprint && $baseFingerprint !== $changedFingerprint,
        'Storage-only row IDs/numeric JSON types must compare equal while parser-owned content changes must compare different.'
    );
} catch (Throwable $error) {
    $record('parsed_metadata_fingerprint_semantics', false, get_class($error) . ': ' . $error->getMessage());
}

try {
    require_once $root . '/parsers/EpicUE3PackageReader.php';
    $reader = new CatalogEpicUE3BinaryReader(pack('V', 2) . "\xE9\0");
    $record(
        'epic_ue3_ansi_fstring_to_utf8',
        $reader->fstring('fixture') === "\xC3\xA9",
        'Epic positive-length FString loading uses FromAnsi byte-to-TCHAR semantics; high ANSI bytes must become valid UTF-8 without byte loss.'
    );
} catch (Throwable $error) {
    $record('epic_ue3_ansi_fstring_to_utf8', false, get_class($error) . ': ' . $error->getMessage());
}

$projectionService = $read('src/Infrastructure/Maintenance/CatalogFullSyncProjectionService.php');
$providers = $read('src/Infrastructure/Persistence/PdoPackageProviderRepository.php');
$record(
    'full_sync_projection_finalization',
    str_contains($providers, 'function reconcileGame(int $gameId)')
        && str_contains($projectionService, 'prepareDependencies')
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
