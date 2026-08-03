<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogFileMaintenanceCompactCore.php';

/** @return array<string,mixed> */
function catalog_file_maintenance_reimport(PDO $db, array $config, int $fileId, ?int $userId, ?callable $progress = null): array
{
    $snapshot = catalog_file_maintenance_snapshot($db, $fileId, $config);
    $file = (array)$snapshot['file'];
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
    $oldMetadataPath = catalog_file_maintenance_metadata_path(
        $config,
        (int)$file['game_id'],
        $fileId
    );
    $replacementFileId = 0;
    $replacementGameId = 0;
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
        catalog_file_maintenance_emit($progress, 'database', 22, 'Removing the old catalog record and its compact projections');
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
        $replacementGameId = $gameId;
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
        if (is_file($oldMetadataPath)) {
            @unlink($oldMetadataPath);
        }
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
            if ($replacementGameId > 0) {
                $replacementMetadataPath = catalog_file_maintenance_metadata_path(
                    $config,
                    $replacementGameId,
                    $replacementFileId
                );
                if (is_file($replacementMetadataPath)) {
                    @unlink($replacementMetadataPath);
                }
            }
        }
        if (!catalog_one($db, 'SELECT id FROM ue_files WHERE id=?', [$fileId])) {
            catalog_file_maintenance_restore_snapshot($db, $snapshot, $config);
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
