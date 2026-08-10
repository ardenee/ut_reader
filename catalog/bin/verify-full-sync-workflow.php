#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies the deterministic compact-only Full Sync workflow before a game-wide rescan.
 * Role: Read-only source and optional database regression gate for Full Sync maintenance.
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
    'file-maintenance.php',
    'lib/CatalogFileMaintenance.php',
    'src/Infrastructure/Import/PdoCatalogPackageImporter.php',
    'src/Infrastructure/Jobs/CatalogProjectionReconciliationJobHandler.php',
    'src/Infrastructure/Maintenance/CatalogFullSyncDependencyBatchService.php',
    'src/Infrastructure/Maintenance/CatalogFullSyncProjectionService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceActionService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceReimportService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceRemovalService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceSupport.php',
    'src/Infrastructure/Persistence/PdoCatalogDependencyRebuilder.php',
    'src/Infrastructure/Persistence/PdoCatalogVerifiedPackagePersistence.php',
    'src/Infrastructure/Persistence/PdoPackageProviderRepository.php',
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
$record(
    'full_sync_four_phase_order',
    $client !== ''
        && strpos($client, "'sync_reimport'") !== false
        && strpos($client, "'sync_prepare_dependencies'") !== false
        && strpos($client, "'sync_refresh_dependencies_batch'") !== false
        && strpos($client, "'sync_finalize_game'") !== false
        && strpos($client, "'sync_reimport'") < strpos($client, "'sync_prepare_dependencies'")
        && strpos($client, "'sync_prepare_dependencies'") < strpos($client, "'sync_refresh_dependencies_batch'")
        && strpos($client, "'sync_refresh_dependencies_batch'") < strpos($client, "'sync_finalize_game'"),
    'Full Sync must refresh all package identities before providers, bounded dependency batches and final projections.'
);
$record(
    'full_sync_verified_scope',
    str_contains($page, 'WHERE game_id=? AND scan_status="verified"'),
    'Full Sync must not feed unverified/failed rows into verified compact reimport maintenance.'
);
$record(
    'full_sync_client_is_externalized',
    str_contains($page, 'full-sync.js?v=')
        && !str_contains($page, 'async function runFullSync'),
    'The Full Sync page should keep browser orchestration in the dedicated client file.'
);
$record(
    'full_sync_dependency_client_batches',
    str_contains($client, 'DEPENDENCY_BATCH_SIZE = 100')
        && str_contains($client, "data.set('operation', 'sync_refresh_dependencies_batch')")
        && str_contains($client, "data.set('file_ids_json'")
        && str_contains($client, 'batchStart += DEPENDENCY_BATCH_SIZE'),
    'The dependency phase must send bounded groups of 100 IDs instead of one HTTP request per package.'
);
$record(
    'full_sync_dependency_batch_fallback_splits',
    str_contains($client, 'async function processDependencyBatch')
        && str_contains($client, 'Math.ceil(batch.length / 2)')
        && str_contains($client, 'await processDependencyBatch(overlay, first')
        && str_contains($client, 'await processDependencyBatch('),
    'A request-level batch failure must split into smaller idempotent ranges instead of dropping the whole batch.'
);

$reimport = $read('src/Infrastructure/Maintenance/CatalogFileMaintenanceReimportService.php');
$record(
    'full_sync_reimport_preserves_file_identity',
    str_contains($reimport, "'maintenance_replace_file_id' => \$fileId")
        && str_contains($reimport, 'stable file ID preserved=')
        && str_contains($reimport, 'restoreExistingSnapshot($snapshot)')
        && !str_contains($reimport, 'DELETE FROM ue_files'),
    'A successful/failed maintenance reparse must retain the existing ue_files ID and unrelated relationships.'
);
$record(
    'full_sync_reimport_defers_async_reconciliation',
    str_contains($reimport, 'if (!$deferDependencyRefresh)')
        && str_contains($reimport, 'CatalogProjectionReconciliationQueue::enqueue'),
    'Deferred Full Sync reimports must not queue per-package projection reconciliation.'
);

$importer = $read('src/Infrastructure/Import/PdoCatalogPackageImporter.php');
$persistence = $read('src/Infrastructure/Persistence/PdoCatalogVerifiedPackagePersistence.php');
$storage = $read('src/Infrastructure/Storage/CatalogVerifiedPackageStorage.php');
$record(
    'maintenance_importer_stable_id_contract',
    str_contains($importer, 'maintenance_replace_file_id')
        && str_contains($importer, 'AND id<>?')
        && str_contains($importer, '$maintenanceReplaceFileId')
        && str_contains($persistence, 'int $replaceFileId = 0')
        && str_contains($persistence, 'UPDATE ue_files SET '),
    'Maintenance refresh must exclude its own identity from duplicate detection and update that row in place.'
);
$record(
    'maintenance_scanner_copy_cleanup_is_windows_safe',
    str_contains($importer, '$maintenanceReplaceFileId === 0')
        && str_contains($storage, 'bool $discardDuplicateSource = true')
        && str_contains($storage, 'if ($discardDuplicateSource'),
    'Normal uploads discard duplicate temporary files immediately; stable-ID maintenance leaves its scanner copy for outer cleanup after reader use.'
);

$removal = $read('src/Infrastructure/Maintenance/CatalogFileMaintenanceRemovalService.php');
$record(
    'full_sync_missing_file_removal_is_explicit',
    str_contains($removal, 'bool $deferDependencyRefresh = false')
        && str_contains($removal, 'if (!$deferDependencyRefresh)')
        && str_contains($removal, 'deleteFileProjections($fileId)')
        && str_contains($removal, 'DELETE FROM ue_files WHERE id=?'),
    'Only genuinely missing stored packages should use destructive catalog removal during Full Sync.'
);

$transport = $read('file-maintenance.php');
$batchService = $read('src/Infrastructure/Maintenance/CatalogFullSyncDependencyBatchService.php');
$record(
    'full_sync_dependency_batch_transport',
    str_contains($transport, "\$operation === 'sync_refresh_dependencies_batch'")
        && str_contains($transport, 'CatalogFullSyncDependencyBatchService')
        && str_contains($transport, 'catalog_maintenance_file_ids($_POST)')
        && str_contains($batchService, 'public const MAX_BATCH_SIZE = 100'),
    'The HTTP adapter and service must enforce the same bounded Full Sync dependency batch contract.'
);
$record(
    'full_sync_dependency_batch_isolated_failures',
    str_contains($batchService, 'foreach ($fileIds as $index => $fileId)')
        && str_contains($batchService, "'failures' => \$failures")
        && str_contains($batchService, 'catch (Throwable $error)')
        && str_contains($batchService, "'summary_refresh_deferred' => true"),
    'A bad package must be reported within its batch without aborting successful dependency owners or publishing summaries early.'
);
$record(
    'full_sync_dependency_batch_avoids_global_identity_lock',
    !str_contains($batchService, 'unrealdb_catalog_maintenance_write_v1')
        && !str_contains($batchService, 'withWriteLock')
        && str_contains($batchService, 'PdoCatalogDependencyRebuilder')
        && str_contains($batchService, "false\n                            );"),
    'Dependency batches must rely on the per-file compact dependency lock rather than the global identity-write lock.'
);

$action = $read('src/Infrastructure/Maintenance/CatalogFileMaintenanceActionService.php');
$syncDependencyStart = strpos($action, "if (\$operation === 'sync_refresh_dependencies')");
$nextOperationStart = $syncDependencyStart === false
    ? false
    : strpos($action, "if (\$operation === 'reimport' || \$operation === 'rebuild')", $syncDependencyStart);
$syncDependencyBlock = ($syncDependencyStart !== false && $nextOperationStart !== false)
    ? substr($action, $syncDependencyStart, $nextOperationStart - $syncDependencyStart)
    : '';
$record(
    'single_dependency_endpoint_remains_nonblocking',
    $syncDependencyBlock !== ''
        && !str_contains($syncDependencyBlock, 'withWriteLock')
        && str_contains($syncDependencyBlock, 'PdoCatalogDependencyRebuilder')
        && str_contains($syncDependencyBlock, "'Final dependency refresh for '")
        && str_contains($syncDependencyBlock, "false\n                );"),
    'The compatibility single-file dependency endpoint must retain the same narrow locking/summary policy.'
);
$record(
    'full_sync_reimport_skips_unused_dependency_discovery',
    str_contains($action, "if (\$operation !== 'sync_reimport')")
        && !str_contains($action, 'restoreIdentityRows('),
    'Stable-ID Full Sync reimports must not query referring files or rebuild alias/location identities that remain attached to the same file ID.'
);

$rebuilder = $read('src/Infrastructure/Persistence/PdoCatalogDependencyRebuilder.php');
$record(
    'dependency_rebuild_uses_per_file_lock',
    str_contains($rebuilder, "FILE_LOCK_PREFIX = 'unrealdb_dependency_file_v1_'")
        && str_contains($rebuilder, 'withFileLock($fileId')
        && str_contains($rebuilder, 'SELECT GET_LOCK(?, ?)'),
    'Dependency writers for different files must not serialize behind a global maintenance lock; same-file writers remain serialized.'
);
$record(
    'dependency_rebuild_summary_policy',
    str_contains($rebuilder, 'bool $refreshSummary = true')
        && str_contains($rebuilder, 'if ($refreshSummary)')
        && str_contains($rebuilder, 'summary refresh deferred'),
    'Normal dependency maintenance keeps summaries current while Full Sync may defer them to its final bulk summary rebuild.'
);

$projectionJob = $read('src/Infrastructure/Jobs/CatalogProjectionReconciliationJobHandler.php');
$record(
    'projection_reconciliation_does_not_monopolize_identity_lock',
    !str_contains($projectionJob, 'unrealdb_catalog_maintenance_write_v1')
        && !str_contains($projectionJob, 'MAINTENANCE_LOCK')
        && str_contains($projectionJob, 'PdoCatalogDependencyRebuilder')
        && str_contains($projectionJob, 'rebuildForPackages('),
    'Projection jobs must use per-file dependency locking rather than holding the global identity-write lock across affected-file loops.'
);

$providers = $read('src/Infrastructure/Persistence/PdoPackageProviderRepository.php');
$record(
    'provider_game_reconciliation',
    str_contains($providers, 'function reconcileGame(int $gameId)')
        && str_contains($providers, 'DELETE FROM ue_package_providers WHERE game_id=?')
        && str_contains($providers, 'WHERE f.game_id=? AND f.scan_status="verified"')
        && str_contains($providers, 'WHERE a.game_id=? AND f.scan_status="verified"'),
    'Full Sync must rebuild primary and alias providers set-wise for the selected game.'
);

$projectionService = $read('src/Infrastructure/Maintenance/CatalogFullSyncProjectionService.php');
$record(
    'full_sync_projection_finalization',
    str_contains($projectionService, 'prepareDependencies')
        && str_contains($projectionService, 'reconcileGame($gameId)')
        && str_contains($projectionService, 'rebuildFiles($fileIds)')
        && str_contains($projectionService, 'rebuildGame($gameId)'),
    'Finalization must leave providers, dependency summaries and cached game statistics current.'
);

if ($withDatabase) {
    try {
        require_once $root . '/bootstrap.php';
        $application = catalog_bootstrap(false);
        $schema = new SchemaInspector($application->db);
        foreach ([
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
