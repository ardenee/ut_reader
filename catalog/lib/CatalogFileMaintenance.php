<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogImport.php';

function catalog_file_maintenance_storage_path(array $config, array $file): ?string
{
    $storageRoot = realpath(rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
    if ($storageRoot === false || !is_dir($storageRoot)) {
        throw new RuntimeException('Catalog storage folder is unavailable.');
    }

    $relativePath = ltrim(str_replace('\\', '/', (string)($file['relative_path'] ?? '')), '/');
    if ($relativePath === '') {
        return null;
    }

    $catalogRoot = realpath(__DIR__ . '/..');
    if ($catalogRoot === false) {
        throw new RuntimeException('Catalog application folder is unavailable.');
    }

    $candidate = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!file_exists($candidate)) {
        return null;
    }

    $resolved = realpath($candidate);
    $rootPrefix = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($resolved === false || !str_starts_with($resolved, $rootPrefix)) {
        throw new RuntimeException('Refusing to remove a file outside catalog storage.');
    }

    return $resolved;
}

function catalog_file_maintenance_rebuild_game(PDO $db, array $config, int $gameId): int
{
    $count = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_files WHERE game_id=? AND scan_status="verified"', [$gameId])['c'] ?? 0);

    $db->beginTransaction();
    try {
        catalog_import_rebuild_game($db, $config, $gameId);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return $count;
}

function catalog_file_maintenance_delete(PDO $db, array $config, int $fileId): array
{
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) {
        throw new RuntimeException('File no longer exists in the catalog.');
    }

    $storedPath = catalog_file_maintenance_storage_path($config, $file);
    $stagedPath = null;
    if ($storedPath !== null && is_file($storedPath)) {
        $stagedPath = $storedPath . '.deleting-' . bin2hex(random_bytes(8));
        if (!@rename($storedPath, $stagedPath)) {
            throw new RuntimeException('Could not stage the stored package for deletion.');
        }
    }

    try {
        $db->beginTransaction();
        $db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$fileId]);
        catalog_import_rebuild_game($db, $config, (int)$file['game_id']);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($stagedPath !== null && is_file($stagedPath) && $storedPath !== null) {
            @rename($stagedPath, $storedPath);
        }
        throw $e;
    }

    $warning = '';
    if ($stagedPath !== null && is_file($stagedPath) && !@unlink($stagedPath)) {
        $warning = ' The database record was removed, but the staged storage file could not be deleted.';
    }

    return [
        'game_id' => (int)$file['game_id'],
        'package_name' => (string)$file['package_name'],
        'original_name' => (string)$file['original_name'],
        'storage_found' => $storedPath !== null,
        'warning' => $warning,
    ];
}
