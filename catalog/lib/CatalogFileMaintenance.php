<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the stable compatibility include point for catalog file maintenance.
 * Why: Active implementations live in focused compact helpers or namespaced maintenance services while legacy callers retain one include/function surface.
 * Role: Thin compatibility facade; do not add maintenance implementation logic here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogFileMaintenanceCompactCore.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceReimportService;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceRemovalService;

/**
 * @param array<string,mixed> $config
 * @param null|callable(array<string,mixed>):void $progress
 * @return array<string,mixed>
 */
function catalog_file_maintenance_reimport(
    PDO $db,
    array $config,
    int $fileId,
    ?int $userId,
    ?callable $progress = null,
    bool $deferDependencyRefresh = false
): array {
    return (new CatalogFileMaintenanceReimportService($db, $config))
        ->reimport($fileId, $userId, $progress, $deferDependencyRefresh);
}

/**
 * @param array<string,mixed> $config
 * @param null|callable(array<string,mixed>):void $progress
 * @return array<string,mixed>
 */
function catalog_file_maintenance_remove(
    PDO $db,
    array $config,
    int $fileId,
    ?callable $progress = null,
    bool $deferDependencyRefresh = false
): array {
    // Full Sync owns a complete second dependency pass after every package has
    // been validated/re-imported. Keep missing-file removals in that same mode.
    if ((string)($_POST['operation'] ?? '') === 'sync_reimport') {
        $deferDependencyRefresh = true;
    }

    return (new CatalogFileMaintenanceRemovalService($db, $config))
        ->remove($fileId, $progress, $deferDependencyRefresh);
}
