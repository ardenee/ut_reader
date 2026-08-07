<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog file maintenance compact delete.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogFileMaintenanceCompactCore.php';

/** @return array<string,mixed> */
function catalog_file_maintenance_delete(PDO $db, array $config, int $fileId, ?callable $progress = null): array
{
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) {
        throw new RuntimeException('File no longer exists in the catalog.');
    }

    $gameId = (int)$file['game_id'];
    $packageName = (string)$file['package_name'];
    $metadataPath = catalog_file_maintenance_metadata_path($config, $gameId, $fileId);
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
        catalog_file_maintenance_emit($progress, 'delete', 20, 'Removing catalog records and compact projections');
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

    $warnings = [];
    catalog_file_maintenance_emit($progress, 'delete', 98, 'Removing staged storage and metadata files');
    if ($stagedPath !== null && is_file($stagedPath) && !@unlink($stagedPath)) {
        $warnings[] = 'the staged package file could not be deleted';
    }
    if (is_file($metadataPath) && !@unlink($metadataPath)) {
        $warnings[] = 'the compact metadata file could not be deleted';
    }
    catalog_file_maintenance_emit($progress, 'done', 100, 'Package removal complete');

    return [
        'game_id' => $gameId,
        'package_name' => $packageName,
        'original_name' => (string)$file['original_name'],
        'storage_found' => $storedPath !== null,
        'warning' => $warnings !== []
            ? ' The database record was removed, but ' . implode(' and ', $warnings) . '.'
            : '',
        'reconciliation_job_id' => $reconciliationJobId,
    ];
}

function catalog_file_maintenance_remove(PDO $db, array $config, int $fileId, ?callable $progress = null): array
{
    return catalog_file_maintenance_delete($db, $config, $fileId, $progress);
}
