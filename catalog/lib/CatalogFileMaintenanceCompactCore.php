<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves historical compact file-maintenance helper functions for existing callers.
 * Why: Storage containment, compact snapshot/restore and dependency refresh now live in CatalogFileMaintenanceSupport.
 * Role: Thin compatibility facade; do not add maintenance implementation logic here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogScanner.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceSupport;

function catalog_file_maintenance_storage_path(array $config, array $file): ?string
{
    return CatalogFileMaintenanceSupport::storagePath($config, $file);
}

function catalog_file_maintenance_emit(?callable $progress, string $stage, int $percent, string $message): void
{
    CatalogFileMaintenanceSupport::emit($progress, $stage, $percent, $message);
}

function catalog_file_maintenance_storage_root(array $config): string
{
    return CatalogFileMaintenanceSupport::storageRoot($config);
}

function catalog_file_maintenance_metadata_path(array $config, int $gameId, int $fileId): string
{
    return CatalogFileMaintenanceSupport::metadataPath($config, $gameId, $fileId);
}

/** @return array<string,mixed> */
function catalog_file_maintenance_snapshot(PDO $db, int $fileId, ?array $config = null): array
{
    $config ??= catalog_config();
    return (new CatalogFileMaintenanceSupport($db, $config))->snapshot($fileId);
}

function catalog_file_maintenance_restore_snapshot(PDO $db, array $snapshot, ?array $config = null): void
{
    $config ??= catalog_config();
    (new CatalogFileMaintenanceSupport($db, $config))->restoreSnapshot($snapshot);
}

function catalog_file_maintenance_source_relative_path(array $snapshot): string
{
    return CatalogFileMaintenanceSupport::sourceRelativePath($snapshot);
}

/** @return list<int> */
function catalog_file_maintenance_affected_ids(
    PDO $db,
    int $gameId,
    int $removedFileId,
    string $packageName,
    bool $deferDependencyRefresh = false
): array {
    return (new CatalogFileMaintenanceSupport($db, []))->affectedIds(
        $gameId,
        $removedFileId,
        $packageName,
        $deferDependencyRefresh
    );
}

function catalog_file_maintenance_refresh_ids(
    PDO $db,
    array $config,
    array $fileIds,
    ?callable $progress,
    int $startPercent,
    int $endPercent,
    string $prefix
): void {
    (new CatalogFileMaintenanceSupport($db, $config))->refreshIds(
        $fileIds,
        $progress,
        $startPercent,
        $endPercent,
        $prefix
    );
}
