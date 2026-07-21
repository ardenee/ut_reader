<?php
declare(strict_types=1);

function game_backup_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require_once __DIR__ . '/../src/Infrastructure/Storage/GameBackupStore.php';

use UnrealDb\Catalog\Infrastructure\Storage\GameBackupStore;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-game-backup-' . bin2hex(random_bytes(5));
$store = new GameBackupStore([
    'storage_path' => $root . DIRECTORY_SEPARATOR . 'storage',
    'game_backups' => ['path' => $root . DIRECTORY_SEPARATOR . 'backups'],
]);
$created = $store->create('test-game-20260721');
game_backup_expect(is_dir($created['files_path']), 'Game backup files directory was not created.');
$relative = 'System/Test.u';
$destination = $created['files_path'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
game_backup_expect(mkdir(dirname($destination), 0777, true), 'Could not create test backup subdirectory.');
game_backup_expect(file_put_contents($destination, 'backup-data') !== false, 'Could not create test backup file.');
$store->publishManifest('test-game-20260721', [
    'format' => 'unrealdb-game-backup',
    'format_version' => 1,
    'created_at' => gmdate('c'),
    'completed_at' => gmdate('c'),
    'source_game' => ['id' => 1, 'name' => 'Test Game', 'slug' => 'test-game'],
    'summary' => ['entries' => 1, 'physical_files' => 1, 'bytes' => 11, 'conflicts' => 0],
    'files' => [['exported_relative_path' => $relative]],
]);
$listed = $store->listBackups();
game_backup_expect(count($listed) === 1 && !empty($listed[0]['complete']), 'Completed backup was not listed.');
game_backup_expect($store->resolveBackupFile('test-game-20260721', $relative) === realpath($destination), 'Backup file resolution failed.');
$store->delete('test-game-20260721');
game_backup_expect(!is_dir($created['path']), 'Backup deletion did not remove the directory.');
@rmdir($store->root());
@rmdir($root);

$importHandler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/GameBackupJobHandler.php');
game_backup_expect(is_string($importHandler), 'Could not read game backup import handler.');
game_backup_expect(str_contains($importHandler, 'tempnam($tempDirectory'), 'Backup import does not create an independent working copy.');
game_backup_expect(str_contains($importHandler, "'defer_dependency_rebuild' => true"), 'Backup import does not defer per-file dependency rebuilds.');
game_backup_expect(str_contains($importHandler, 'scanner_rebuild_game('), 'Backup import does not rebuild dependencies once after restore.');

$exportHandler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/GameBackupExportJobHandler.php');
game_backup_expect(is_string($exportHandler), 'Could not read game backup export handler.');
game_backup_expect(str_contains($exportHandler, 'copy($source, $destination)'), 'Game backup export is not using a normal file copy.');
game_backup_expect(!preg_match('/(?<![A-Za-z])link\s*\(/', $exportHandler), 'Game backup export must not create hard links or symbolic links.');
game_backup_expect(str_contains($exportHandler, 'FROM ue_file_locations'), 'Game backup export ignores recorded source locations.');
game_backup_expect(str_contains($exportHandler, 'selectRecordedPath'), 'Game backup export does not select the best recorded path.');
game_backup_expect(str_contains($exportHandler, 'legacyFolderForExtension'), 'Game backup export has no legacy game-folder fallback.');
game_backup_expect(str_contains($exportHandler, "'unr', 'ut2', 'un2' => 'Maps'"), 'Map packages are not routed to Maps.');
game_backup_expect(str_contains($exportHandler, "'u' => 'System'"), 'System packages are not routed to System.');
game_backup_expect(str_contains($exportHandler, "'utx' => 'Textures'"), 'Texture packages are not routed to Textures.');
game_backup_expect(str_contains($exportHandler, "'uax', 'est_uax', 'frt_uax', 'itt_uax' => 'Sounds'"), 'Sound packages are not routed to Sounds.');
game_backup_expect(str_contains($exportHandler, "'umx' => 'Music'"), 'Music packages are not routed to Music.');
game_backup_expect(str_contains($exportHandler, 'allocateUniqueRelativePath'), 'Same-name backup variations are not assigned unique filenames.');
game_backup_expect(str_contains($exportHandler, "' (' . \$number . ')'"), 'Same-name backup variations do not use a numeric suffix before the extension.');
game_backup_expect(str_contains($exportHandler, "'same_name_policy' => 'numeric-suffix-before-extension'"), 'Backup manifest does not record the variation naming policy.');
game_backup_expect(!str_contains($exportHandler, '_Conflicts/'), 'Game backup export still creates a _Conflicts directory.');
game_backup_expect(!str_contains($exportHandler, 'conflictRelativePath'), 'Game backup export still routes variations to a conflict path.');

$page = file_get_contents(__DIR__ . '/../game-backups.php');
game_backup_expect(is_string($page), 'Could not read game backups page.');
foreach (['Build server backup', 'Exports currently on this server', 'Import backup', 'Delete export'] as $fragment) {
    game_backup_expect(str_contains($page, $fragment), 'Game backups page is missing: ' . $fragment);
}
foreach ([
    '$hasActiveBackupJobs = catalog_count(',
    'status IN ("queued","running")',
    'refreshDelayMs = 5000',
    'window.location.reload()',
    "document.visibilityState !== 'visible'",
    'unrealdb-game-backups-scroll-y',
    'refreshes automatically every 5 seconds',
] as $refreshContract) {
    game_backup_expect(
        str_contains($page, $refreshContract),
        'Game Backups active-job auto-refresh is missing: ' . $refreshContract
    );
}

$types = file_get_contents(__DIR__ . '/../src/Domain/Jobs/JobType.php');
game_backup_expect(is_string($types)
    && str_contains($types, 'EXPORT_GAME_BACKUP')
    && str_contains($types, 'IMPORT_GAME_BACKUP'), 'Game backup job types are not registered.');

$factory = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
game_backup_expect(is_string($factory), 'Could not read the worker factory.');
$exportPosition = strpos($factory, 'new GameBackupExportJobHandler(');
$backupPosition = strpos($factory, 'new GameBackupJobHandler(');
$maintenancePosition = strpos($factory, 'new CatalogMaintenanceJobHandler(');
game_backup_expect(
    $exportPosition !== false
    && $backupPosition !== false
    && $maintenancePosition !== false
    && $exportPosition < $backupPosition
    && $backupPosition < $maintenancePosition,
    'Game backup export/import handlers are registered in the wrong order.'
);

foreach ([
    'new CatalogStorageMaintenanceJobHandler(',
    'new UnverifiedDuplicateCleanupJobHandler(',
    'new GeneratedPackageJobHandler(',
    'new GameBackupExportJobHandler(',
    'new GameBackupJobHandler(',
] as $specializedHandler) {
    $position = strpos($factory, $specializedHandler);
    game_backup_expect(
        $position !== false && $maintenancePosition !== false && $position < $maintenancePosition,
        'The catch-all maintenance handler intercepts specialized worker routing: ' . $specializedHandler
    );
}

echo "Game backup contract tests passed.\n";
