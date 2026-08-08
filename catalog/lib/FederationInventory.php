<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves historical federation inventory helper functions for compatibility callers.
 * Why: Active inventory build/push and peer synchronization now live under catalog/src/Infrastructure/Federation.
 * Role: Thin compatibility facade; do not add inventory implementation logic here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';
require_once __DIR__ . '/FederationPeerSecret.php';
require_once __DIR__ . '/BaseGameProtection.php';
require_once __DIR__ . '/FederationBaseGamePolicy.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationLocalInventoryService;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationPeerInventorySyncService;

/** @return array<string,mixed> */
function federation_build_inventory_payload(PDO $db): array
{
    return (new CatalogFederationLocalInventoryService($db))->buildPayload();
}

/** @return array<string,mixed> */
function federation_push_inventory_to_parent(PDO $db, int $peerId): array
{
    return (new CatalogFederationLocalInventoryService($db))->pushToParent($peerId);
}

/** @return array<string,mixed> */
function federation_auto_push_inventory_to_parent(PDO $db): array
{
    return (new CatalogFederationLocalInventoryService($db))->autoPushToParent();
}

/** @return array<string,mixed> */
function federation_pull_inventory_from_peer(PDO $db, int $peerId): array
{
    return (new CatalogFederationPeerInventorySyncService($db))->pullFromPeer($peerId);
}

/** @return array<string,mixed> */
function federation_pull_inventory_from_child(PDO $db, int $peerId): array
{
    return (new CatalogFederationPeerInventorySyncService($db))->pullFromChild($peerId);
}

/** @return array<string,mixed> */
function federation_pull_inventory_from_parent(PDO $db, int $peerId): array
{
    return (new CatalogFederationPeerInventorySyncService($db))->pullFromParent($peerId);
}

function federation_inventory_sync_interval_hours(PDO $db): int
{
    return (new CatalogFederationPeerInventorySyncService($db))->syncIntervalHours();
}

function federation_inventory_last_sync_at(PDO $db, int $peerId): ?string
{
    return (new CatalogFederationPeerInventorySyncService($db))->lastSyncAt($peerId);
}

function federation_inventory_sync_is_due(PDO $db, int $peerId, ?int $now = null): bool
{
    return (new CatalogFederationPeerInventorySyncService($db))->syncIsDue($peerId, $now);
}

/** @return array<string,mixed> */
function federation_sync_due_inventories(PDO $db, bool $force = false): array
{
    return (new CatalogFederationPeerInventorySyncService($db))->syncDueInventories($force);
}
