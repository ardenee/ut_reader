<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical federation inventory-refresh helper for compatibility callers.
 * Why: Active signed refresh transport now lives in CatalogFederationInventoryRefreshService under catalog/src.
 * Role: Thin compatibility facade; do not add federation refresh logic here.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationInventoryRefreshService;

/** @return array<string,mixed> */
function federation_request_child_refresh_parent_inventory(PDO $db, int $peerId): array
{
    return (new CatalogFederationInventoryRefreshService($db))
        ->requestChildRefreshParentInventory($peerId);
}
