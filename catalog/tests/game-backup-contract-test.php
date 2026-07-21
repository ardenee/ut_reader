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

$handler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/GameBackupJobHandler.php');
game_backup_expect(is_string($handler), 'Could not read game backup job handler.');
game_backup_expect(str_contains($handler, 'copy($source, $destination)'), 'Game backup export is not using a normal file copy.');
game_backup_expect(!preg_match('/(?<![A-Za-z])link\s*\(/', $handler), 'Game backup export must not create hard links or symbolic links.');
game_backup_expect(str_contains($handler, 'tempnam($tempDirectory'), 'Backup import does not create an independent working copy.');
game_backup_expect(str_contains($handler, "'defer_dependency_rebuild' => true"), 'Backup import does not defer per-file dependency rebuilds.');
game_backup_expect(str_contains($handler, 'scanner_rebuild_game('), 'Backup import does not rebuild dependencies once after restore.');

$page = file_get_contents(__DIR__ . '/../game-backups.php');
game_backup_expect(is_string($page), 'Could not read game backups page.');
foreach (['Build server backup', 'Exports currently on this server', 'Import backup', 'Delete export'] as $fragment) {
    game_backup_expect(str_contains($page, $fragment), 'Game backups page is missing: ' . $fragment);
}

$types = file_get_contents(__DIR__ . '/../src/Domain/Jobs/JobType.php');
game_backup_expect(is_string($types)
    && str_contains($types, 'EXPORT_GAME_BACKUP')
    && str_contains($types, 'IMPORT_GAME_BACKUP'), 'Game backup job types are not registered.');

$factory = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
game_backup_expect(is_string($factory) && str_contains($factory, 'new GameBackupJobHandler('), 'Worker factory does not register the game backup handler.');
$backupHandlerPosition = strpos($factory, 'new GameBackupJobHandler(');
$maintenanceHandlerPosition = strpos($factory, 'new CatalogMaintenanceJobHandler(');
game_backup_expect(
    $backupHandlerPosition !== false
    && $maintenanceHandlerPosition !== false
    && $backupHandlerPosition < $maintenanceHandlerPosition,
    'The catch-all maintenance handler intercepts game backup jobs before GameBackupJobHandler.'
);

foreach ([
    'new CatalogStorageMaintenanceJobHandler(',
    'new UnverifiedDuplicateCleanupJobHandler(',
    'new GeneratedPackageJobHandler(',
] as $specializedHandler) {
    $position = strpos($factory, $specializedHandler);
    game_backup_expect(
        $position !== false && $maintenanceHandlerPosition !== false && $position < $maintenanceHandlerPosition,
        'The catch-all maintenance handler intercepts specialized worker routing: ' . $specializedHandler
    );
}

echo "Game backup contract tests passed.\n";
