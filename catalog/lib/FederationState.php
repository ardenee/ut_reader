<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves historical federation state helper functions for compatibility callers.
 * Why: Active state transitions now live in CatalogFederationStateService under catalog/src.
 * Role: Thin compatibility facade; do not add federation mutation logic here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationStateService;

function federation_site_role(PDO $db): string
{
    return (new CatalogFederationStateService($db))->siteRole();
}

function federation_parent_peer(PDO $db, bool $activeOnly = true): ?array
{
    return (new CatalogFederationStateService($db))->parentPeer($activeOnly);
}

/** @return list<array<string,mixed>> */
function federation_child_peers(PDO $db, bool $activeOnly = false): array
{
    return (new CatalogFederationStateService($db))->childPeers($activeOnly);
}

function federation_parent_join_status(PDO $db): string
{
    return (new CatalogFederationStateService($db))->parentJoinStatus();
}

function federation_has_pending_parent_join(PDO $db): bool
{
    return (new CatalogFederationStateService($db))->hasPendingParentJoin();
}

function federation_display_role(PDO $db): string
{
    return (new CatalogFederationStateService($db))->displayRole();
}

function federation_set_site_role(PDO $db, string $role): void
{
    (new CatalogFederationStateService($db))->setSiteRole($role);
}

function federation_clear_parent_join_state(PDO $db): void
{
    (new CatalogFederationStateService($db))->clearParentJoinState();
}

function federation_can_join_parent(PDO $db): bool
{
    return (new CatalogFederationStateService($db))->canJoinParent();
}

function federation_can_accept_children(PDO $db): bool
{
    return (new CatalogFederationStateService($db))->canAcceptChildren();
}

function federation_cancel_active_peer_jobs(PDO $db, int $peerId, string $reason): int
{
    return (new CatalogFederationStateService($db))->cancelActivePeerJobs($peerId, $reason);
}

function federation_remove_peer(PDO $db, array $peer): void
{
    (new CatalogFederationStateService($db))->removePeer($peer);
}

function federation_reconcile_site_role(PDO $db): string
{
    return (new CatalogFederationStateService($db))->reconcileSiteRole();
}

/** @return array<string,string> */
function federation_main_links(): array
{
    return CatalogFederationStateService::mainLinks();
}
