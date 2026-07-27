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

/** @return array{file:array<string,mixed>,names:list<array<string,mixed>>,imports:list<array<string,mixed>>,exports:list<array<string,mixed>>,dependencies:list<array<string,mixed>>,locations:list<array<string,mixed>>} */
function catalog_file_maintenance_snapshot(PDO $db, int $fileId): array
{
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) {
        throw new RuntimeException('File no longer exists in the catalog.');
    }
    return [
        'file' => $file,
        'names' => catalog_all($db, 'SELECT * FROM ue_names WHERE file_id=? ORDER BY id', [$fileId]),
        'imports' => catalog_all($db, 'SELECT * FROM ue_imports WHERE file_id=? ORDER BY id', [$fileId]),
        'exports' => catalog_all($db, 'SELECT * FROM ue_exports WHERE file_id=? ORDER BY id', [$fileId]),
        'dependencies' => catalog_all($db, 'SELECT * FROM ue_dependencies WHERE file_id=? ORDER BY id', [$fileId]),
        'locations' => catalog_all($db, 'SELECT * FROM ue_file_locations WHERE file_id=? ORDER BY id', [$fileId]),
    ];
}

function catalog_file_maintenance_restore_row(PDO $db, string $table, array $row): void
{
    $allowedTables = ['ue_files', 'ue_names', 'ue_imports', 'ue_exports', 'ue_dependencies', 'ue_file_locations'];
    if (!in_array($table, $allowedTables, true) || $row === []) {
        throw new RuntimeException('Invalid catalog snapshot restore row.');
    }
    $columns = array_keys($row);
    $quotedColumns = implode(',', array_map(static fn(string $column): string => '`' . str_replace('`', '', $column) . '`', $columns));
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $db->prepare('INSERT INTO `' . $table . '` (' . $quotedColumns . ') VALUES (' . $placeholders . ')')
        ->execute(array_values($row));
}

function catalog_file_maintenance_restore_snapshot(PDO $db, array $snapshot): void
{
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        catalog_file_maintenance_restore_row($db, 'ue_files', $snapshot['file']);
        foreach (['names' => 'ue_names', 'imports' => 'ue_imports', 'exports' => 'ue_exports', 'dependencies' => 'ue_dependencies', 'locations' => 'ue_file_locations'] as $key => $table) {
            foreach ($snapshot[$key] as $row) {
                catalog_file_maintenance_restore_row($db, $table, $row);
            }
        }
        if ($ownsTransaction) {
            $db->commit();
        }
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function catalog_file_maintenance_source_relative_path(array $snapshot): string
{
    $filePath = scanner_normalize_source_relative_path((string)($snapshot['file']['source_relative_path'] ?? ''));
    if ($filePath !== '') {
        return $filePath;
    }
    foreach ($snapshot['locations'] as $location) {
        $path = scanner_normalize_source_relative_path((string)($location['source_relative_path'] ?? ''));
        if ($path !== '') {
            return $path;
        }
    }
    return '';
}

/** @return list<int> */
function catalog_file_maintenance_affected_ids(PDO $db, int $gameId, int $removedFileId, string $packageName): array
{
    if ((string)($_POST['operation'] ?? '') === 'sync_reimport') {
        return [];
    }
    return array_map(
        static fn(array $row): int => (int)$row['file_id'],
        catalog_all(
            $db,
            'SELECT DISTINCT d.file_id FROM ue_dependencies d JOIN ue_files owner ON owner.id=d.file_id '
            . 'WHERE owner.game_id=? AND d.file_id<>? AND (d.resolved_file_id=? OR d.required_package=?)',
            [$gameId, $removedFileId, $removedFileId, $packageName]
        )
    );
}

function catalog_file_maintenance_refresh_ids(PDO $db, array $config, array $fileIds, ?callable $progress, int $startPercent, int $endPercent, string $prefix): void
{
    $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds), static fn(int $id): bool => $id > 0)));
    $total = count($fileIds);
    if ($total === 0) {
        catalog_file_maintenance_emit($progress, 'dependencies', $endPercent, $prefix . ': no affected packages');
        return;
    }
    foreach ($fileIds as $index => $fileId) {
        scanner_rebuild_dependencies(
            $db,
            $config,
            $fileId,
            $progress,
            scanner_range_percent($startPercent, $endPercent, $index, $total),
            scanner_range_percent($startPercent, $endPercent, $index + 1, $total),
            $prefix . ' ' . ($index + 1) . '/' . $total
        );
    }
}

/** @return array<string,mixed> */
function catalog_file_maintenance_reimport(PDO $db, array $config, int $fileId, ?int $userId, ?callable $progress = null): array
{
    $snapshot = catalog_file_maintenance_snapshot($db, $fileId);
    $file = $snapshot['file'];
    $storedPath = catalog_file_maintenance_storage_path($config, $file);
    if ($storedPath === null || !is_file($storedPath)) {
        throw new RuntimeException('The stored package file is missing, so it cannot be re-imported.');
    }

    $sourceRelativePath = catalog_file_maintenance_source_relative_path($snapshot);
    $scannerOriginalName = scanner_original_name_from_source_relative($sourceRelativePath);
    if ($scannerOriginalName === '') {
        $scannerOriginalName = (string)$file['original_name'];
    }

    $suffix = '.reimport-' . bin2hex(random_bytes(8));
    $backupPath = $storedPath . $suffix . '.backup';
    $inputPath = $storedPath . $suffix . '.input';
    $replacementFileId = 0;
    catalog_file_maintenance_emit($progress, 'reimport', 0, 'Verifying stored package ' . $file['original_name']);

    if (!@rename($storedPath, $backupPath)) {
        throw new RuntimeException('Could not stage the stored package for re-import.');
    }
    if (!@copy($backupPath, $inputPath)) {
        @rename($backupPath, $storedPath);
        throw new RuntimeException('Could not prepare a scanner copy of the stored package.');
    }

    try {
        $gameId = (int)$file['game_id'];
        $oldPackageName = (string)$file['package_name'];
        $affectedFileIds = catalog_file_maintenance_affected_ids($db, $gameId, $fileId, $oldPackageName);
        catalog_file_maintenance_emit($progress, 'database', 22, 'Removing the old catalog record and its references');
        $db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$fileId]);

        $result = scanner_scan_uploaded_file(
            $db,
            $config,
            $gameId,
            $inputPath,
            $scannerOriginalName,
            $userId,
            true,
            $progress,
            false,
            ['source_relative_path' => $sourceRelativePath]
        );
        if (($result[0] ?? '') !== 'verified') {
            throw new RuntimeException((string)($result[2] ?? 'Stored package was not re-imported.'));
        }

        $replacementFileId = (int)$result[1];
        $replacement = catalog_one($db, 'SELECT package_name FROM ue_files WHERE id=?', [$replacementFileId]);
        $newPackageName = (string)($replacement['package_name'] ?? $oldPackageName);
        catalog_file_maintenance_emit($progress, 'dependencies', 99, 'Refreshing references to the re-imported package');
        catalog_file_maintenance_refresh_ids($db, $config, $affectedFileIds, $progress, 99, 100, 'Refreshing affected dependency links');

        $reconciliationJobId = \UnrealDb\Catalog\Application\Maintenance\CatalogProjectionReconciliationQueue::enqueue(
            $db,
            $replacementFileId,
            [$gameId],
            [$oldPackageName, $newPackageName],
            $config,
            $userId
        );

        @unlink($backupPath);
        return [
            'game_id' => $gameId,
            'file_id' => $replacementFileId,
            'old_file_id' => $fileId,
            'old_package_name' => $oldPackageName,
            'new_package_name' => $newPackageName,
            'original_name' => (string)($result[4]['source_relative_path'] ?? $scannerOriginalName),
            'reconciliation_job_id' => $reconciliationJobId,
            'message' => (string)$result[2]
                . ($sourceRelativePath !== '' ? '; reimport source=' . $sourceRelativePath : '; reimport source unavailable, used stored filename metadata'),
        ];
    } catch (Throwable $error) {
        @unlink($inputPath);
        if ($replacementFileId > 0) {
            $db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$replacementFileId]);
        }
        if (!catalog_one($db, 'SELECT id FROM ue_files WHERE id=?', [$fileId])) {
            catalog_file_maintenance_restore_snapshot($db, $snapshot);
        }
        if (is_file($storedPath)) {
            @unlink($storedPath);
        }
        if (is_file($backupPath)) {
            @rename($backupPath, $storedPath);
        }
        try {
            scanner_rebuild_game($db, $config, (int)$file['game_id']);
        } catch (Throwable $refreshError) {
            error_log('[UnrealDB reimport rollback] file_id=' . $fileId . ' dependency refresh failed: ' . $refreshError->getMessage());
        }
        throw $error;
    }
}

/** @return array{game_id:int,game_name:string,total:int,synced:int,failed:int,failures:list<string>} */
function catalog_file_maintenance_sync_game(PDO $db, array $config, int $gameId, ?int $userId, ?callable $progress = null): array
{
    $game = catalog_one($db, 'SELECT id,name FROM ue_games WHERE id=?', [$gameId]);
    if (!$game) {
        throw new RuntimeException('Game not found.');
    }
    $files = catalog_all(
        $db,
        'SELECT id,original_name FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY package_name,original_name,id',
        [$gameId]
    );
    $total = count($files);
    $synced = 0;
    $failures = [];
    if ($total === 0) {
        catalog_file_maintenance_emit($progress, 'full_sync', 100, 'No verified files to sync for ' . $game['name']);
        return ['game_id' => (int)$game['id'], 'game_name' => (string)$game['name'], 'total' => 0, 'synced' => 0, 'failed' => 0, 'failures' => []];
    }

    foreach ($files as $index => $file) {
        $fileStart = scanner_range_percent(0, 90, $index, $total);
        $fileEnd = scanner_range_percent(0, 90, $index + 1, $total);
        $fileNumber = $index + 1;
        $originalName = (string)$file['original_name'];
        $fileProgress = static function (array $state) use ($progress, $fileStart, $fileEnd, $fileNumber, $total, $originalName): void {
            if ($progress === null) {
                return;
            }
            $innerPercent = max(0, min(100, (int)($state['percent'] ?? 0)));
            $progress([
                'stage' => 'full_sync',
                'done' => $fileNumber - 1,
                'total' => $total,
                'percent' => $fileStart + (int)floor((($fileEnd - $fileStart) * $innerPercent) / 100),
                'message' => 'Syncing file ' . $fileNumber . '/' . $total . ' (' . $originalName . '): ' . (string)($state['message'] ?? 'working'),
            ]);
        };
        try {
            catalog_file_maintenance_reimport($db, $config, (int)$file['id'], $userId, $fileProgress);
            $synced++;
            if ($progress !== null) {
                $progress(['stage' => 'full_sync', 'done' => $fileNumber, 'total' => $total, 'percent' => $fileEnd, 'message' => 'Synced file ' . $fileNumber . '/' . $total . ': ' . $originalName]);
            }
        } catch (Throwable $error) {
            $failures[] = $originalName . ': ' . $error->getMessage();
            if ($progress !== null) {
                $progress(['stage' => 'full_sync', 'done' => $fileNumber, 'total' => $total, 'percent' => $fileEnd, 'message' => 'Skipped file ' . $fileNumber . '/' . $total . ': ' . $originalName . ' (' . $error->getMessage() . ')']);
            }
        }
    }

    $finalProgress = static function (array $state) use ($progress, $total): void {
        if ($progress === null) {
            return;
        }
        $innerPercent = max(0, min(100, (int)($state['percent'] ?? 0)));
        $progress(['stage' => 'final_dependencies', 'done' => $total, 'total' => $total, 'percent' => 90 + (int)floor($innerPercent / 10), 'message' => 'Final dependency refresh: ' . (string)($state['message'] ?? 'working')]);
    };
    scanner_rebuild_game($db, $config, $gameId, $finalProgress, 0, 100);
    if ($progress !== null) {
        $progress(['stage' => 'full_sync_complete', 'done' => $total, 'total' => $total, 'percent' => 100, 'message' => 'Full sync complete for ' . $game['name'] . ': ' . $synced . '/' . $total . ' files re-imported']);
    }
    return ['game_id' => (int)$game['id'], 'game_name' => (string)$game['name'], 'total' => $total, 'synced' => $synced, 'failed' => count($failures), 'failures' => $failures];
}

/** @return array<string,mixed> */
function catalog_file_maintenance_delete(PDO $db, array $config, int $fileId, ?callable $progress = null): array
{
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) {
        throw new RuntimeException('File no longer exists in the catalog.');
    }
    $gameId = (int)$file['game_id'];
    $packageName = (string)$file['package_name'];
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
        $affectedFileIds = catalog_file_maintenance_affected_ids($db, $gameId, $fileId, $packageName);
        catalog_file_maintenance_emit($progress, 'delete', 20, 'Removing catalog records and references');
        $db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$fileId]);
        catalog_file_maintenance_refresh_ids($db, $config, $affectedFileIds, $progress, 25, 95, 'Refreshing affected dependency links');
    } catch (Throwable $error) {
        if ($stagedPath !== null && is_file($stagedPath) && $storedPath !== null) {
            @rename($stagedPath, $storedPath);
        }
        throw $error;
    }

    $reconciliationJobId = \UnrealDb\Catalog\Application\Maintenance\CatalogProjectionReconciliationQueue::enqueue(
        $db,
        $fileId,
        [$gameId],
        [$packageName],
        $config
    );

    $warning = '';
    catalog_file_maintenance_emit($progress, 'delete', 98, 'Removing staged storage file');
    if ($stagedPath !== null && is_file($stagedPath) && !@unlink($stagedPath)) {
        $warning = ' The database record was removed, but the staged storage file could not be deleted.';
    }
    catalog_file_maintenance_emit($progress, 'done', 100, 'Package removal complete');

    return [
        'game_id' => $gameId,
        'package_name' => $packageName,
        'original_name' => (string)$file['original_name'],
        'storage_found' => $storedPath !== null,
        'warning' => $warning,
        'reconciliation_job_id' => $reconciliationJobId,
    ];
}

function catalog_file_maintenance_remove(PDO $db, array $config, int $fileId, ?callable $progress = null): array
{
    return catalog_file_maintenance_delete($db, $config, $fileId, $progress);
}
