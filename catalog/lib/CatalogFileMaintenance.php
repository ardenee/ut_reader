<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogScanner.php';

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
        throw new RuntimeException('Refusing to use a file outside catalog storage.');
    }

    return $resolved;
}

function catalog_file_maintenance_emit(?callable $progress, string $stage, int $percent, string $message): void
{
    scanner_emit_percent($progress, $stage, $percent, $message);
}

function catalog_file_maintenance_affected_ids(PDO $db, int $gameId, int $removedFileId, string $packageName): array
{
    $rows = catalog_all(
        $db,
        'SELECT DISTINCT d.file_id'
        . ' FROM ue_dependencies d'
        . ' JOIN ue_files owner ON owner.id=d.file_id'
        . ' WHERE owner.game_id=? AND d.file_id<>?'
        . ' AND (d.resolved_file_id=? OR d.required_package=?)',
        [$gameId, $removedFileId, $removedFileId, $packageName]
    );

    return array_map(static fn(array $row): int => (int)$row['file_id'], $rows);
}

function catalog_file_maintenance_refresh_ids(PDO $db, array $config, array $fileIds, ?callable $progress, int $startPercent, int $endPercent, string $prefix): void
{
    $total = count($fileIds);
    if ($total === 0) {
        catalog_file_maintenance_emit($progress, 'dependencies', $endPercent, $prefix . ': no affected packages');
        return;
    }

    foreach ($fileIds as $index => $fileId) {
        $fileStart = scanner_range_percent($startPercent, $endPercent, $index, $total);
        $fileEnd = scanner_range_percent($startPercent, $endPercent, $index + 1, $total);
        scanner_rebuild_dependencies(
            $db,
            $config,
            $fileId,
            $progress,
            $fileStart,
            $fileEnd,
            $prefix . ' ' . ($index + 1) . '/' . $total
        );
    }
}

function catalog_file_maintenance_reimport(PDO $db, array $config, int $fileId, ?int $userId, ?callable $progress = null): array
{
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) {
        throw new RuntimeException('File no longer exists in the catalog.');
    }

    $storedPath = catalog_file_maintenance_storage_path($config, $file);
    if ($storedPath === null || !is_file($storedPath)) {
        throw new RuntimeException('The stored package file is missing, so it cannot be re-imported.');
    }

    $suffix = '.reimport-' . bin2hex(random_bytes(8));
    $backupPath = $storedPath . $suffix . '.backup';
    $inputPath = $storedPath . $suffix . '.input';
    catalog_file_maintenance_emit($progress, 'reimport', 0, 'Verifying stored package ' . $file['original_name']);

    if (!@rename($storedPath, $backupPath)) {
        throw new RuntimeException('Could not stage the stored package for re-import.');
    }
    if (!@copy($backupPath, $inputPath)) {
        @rename($backupPath, $storedPath);
        throw new RuntimeException('Could not prepare a scanner copy of the stored package.');
    }

    try {
        $affectedFileIds = catalog_file_maintenance_affected_ids($db, (int)$file['game_id'], $fileId, (string)$file['package_name']);
        catalog_file_maintenance_emit($progress, 'database', 22, 'Removing the old catalog record and its references');
        $db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$fileId]);

        /* Use the exact scanner/import path used by the main Upload Files page. */
        $result = scanner_scan_uploaded_file(
            $db,
            $config,
            (int)$file['game_id'],
            $inputPath,
            (string)$file['original_name'],
            $userId,
            true,
            $progress
        );

        if (($result[0] ?? '') !== 'verified') {
            throw new RuntimeException((string)($result[2] ?? 'Stored package was not re-imported.'));
        }

        $newFileId = (int)$result[1];
        catalog_file_maintenance_emit($progress, 'dependencies', 99, 'Refreshing references to the re-imported package');
        catalog_file_maintenance_refresh_ids($db, $config, $affectedFileIds, $progress, 99, 100, 'Refreshing affected dependency links');

        @unlink($backupPath);
        return [
            'game_id' => (int)$file['game_id'],
            'file_id' => $newFileId,
            'original_name' => (string)$file['original_name'],
            'message' => (string)$result[2],
        ];
    } catch (Throwable $e) {
        @unlink($inputPath);
        if (is_file($backupPath)) {
            @rename($backupPath, $storedPath);
        }
        throw $e;
    }
}

function catalog_file_maintenance_delete(PDO $db, array $config, int $fileId, ?callable $progress = null): array
{
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) {
        throw new RuntimeException('File no longer exists in the catalog.');
    }

    $storedPath = catalog_file_maintenance_storage_path($config, $file);
    $stagedPath = null;
    if ($storedPath !== null && is_file($storedPath)) {
        $stagedPath = $storedPath . '.deleting-' . bin2hex(random_bytes(8));
        catalog_file_maintenance_emit($progress, 'delete', 5, 'Staging stored package for removal');
        if (!@rename($storedPath, $stagedPath)) {
            throw new RuntimeException('Could not stage the stored package for deletion.');
        }
    }

    try {
        $affectedFileIds = catalog_file_maintenance_affected_ids($db, (int)$file['game_id'], $fileId, (string)$file['package_name']);
        catalog_file_maintenance_emit($progress, 'delete', 20, 'Removing catalog records and references');
        $db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$fileId]);
        catalog_file_maintenance_refresh_ids($db, $config, $affectedFileIds, $progress, 25, 95, 'Refreshing affected dependency links');
    } catch (Throwable $e) {
        if ($stagedPath !== null && is_file($stagedPath) && $storedPath !== null) {
            @rename($stagedPath, $storedPath);
        }
        throw $e;
    }

    $warning = '';
    catalog_file_maintenance_emit($progress, 'delete', 98, 'Removing staged storage file');
    if ($stagedPath !== null && is_file($stagedPath) && !@unlink($stagedPath)) {
        $warning = ' The database record was removed, but the staged storage file could not be deleted.';
    }
    catalog_file_maintenance_emit($progress, 'done', 100, 'Package removal complete');

    return [
        'game_id' => (int)$file['game_id'],
        'package_name' => (string)$file['package_name'],
        'original_name' => (string)$file['original_name'],
        'storage_found' => $storedPath !== null,
        'warning' => $warning,
    ];
}

function catalog_file_maintenance_remove(PDO $db, array $config, int $fileId, ?callable $progress = null): array
{
    return catalog_file_maintenance_delete($db, $config, $fileId, $progress);
}
