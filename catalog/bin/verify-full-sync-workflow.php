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

$files = [
    'full-sync.php',
    'file-maintenance.php',
    'lib/CatalogFileMaintenance.php',
    'src/Infrastructure/Import/PdoCatalogPackageImporter.php',
    'src/Infrastructure/Maintenance/CatalogFullSyncProjectionService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceActionService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceReimportService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceRemovalService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceSupport.php',
    'src/Infrastructure/Persistence/PdoCatalogDependencyRebuilder.php',
    'src/Infrastructure/Persistence/PdoCatalogVerifiedPackagePersistence.php',
    'src/Infrastructure/Persistence/PdoPackageProviderRepository.php',
];

if (!function_exists('proc_open')) {
    $record('php_syntax', false, 'proc_open unavailable; run php -l on the Full Sync files manually.');
} else {
    $syntaxFailures = [];
    foreach ($files as $relative) {
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
$record(
    'full_sync_four_phase_order',
    $page !== ''
        && strpos($page, "'sync_reimport'") !== false
        && strpos($page, "'sync_prepare_dependencies'") !== false
        && strpos($page, "'sync_refresh_dependencies'") !== false
        && strpos($page, "'sync_finalize_game'") !== false
        && strpos($page, "'sync_reimport'") < strpos($page, "'sync_prepare_dependencies'")
        && strpos($page, "'sync_prepare_dependencies'") < strpos($page, "'sync_refresh_dependencies'")
        && strpos($page, "'sync_refresh_dependencies'") < strpos($page, "'sync_finalize_game'"),
    'Full Sync must refresh all package identities before providers, dependencies and final projections.'
);
$record(
    'full_sync_verified_scope',
    str_contains($page, 'WHERE game_id=? AND scan_status="verified"'),
    'Full Sync must not feed unverified/failed rows into verified compact reimport maintenance.'
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
$record(
    'maintenance_importer_stable_id_contract',
    str_contains($importer, 'maintenance_replace_file_id')
        && str_contains($importer, 'AND id<>?')
        && str_contains($importer, '$maintenanceReplaceFileId')
        && str_contains($persistence, 'int $replaceFileId = 0')
        && str_contains($persistence, 'UPDATE ue_files SET '),
    'Maintenance refresh must exclude its own identity from duplicate detection and update that row in place.'
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

$rebuilder = $read('src/Infrastructure/Persistence/PdoCatalogDependencyRebuilder.php');
$record(
    'dependency_rebuild_refreshes_summary',
    str_contains($rebuilder, 'PdoDependencyPackageSummary($this->db)')
        && str_contains($rebuilder, '->rebuildFile($fileId)'),
    'Each explicit dependency rebuild must keep package summaries synchronized.'
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
