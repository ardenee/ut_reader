<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves historical federation pairing function names for existing callers.
 * Why: Approved-parent persistence and automatic claim orchestration now live in CatalogFederationPairingService.
 * Role: Thin compatibility facade; do not add pairing implementation logic here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationPairingService;

/** @param array<string,mixed> $parent */
function federation_store_parent_peer(PDO $db, array $parent, string $source = 'automatic_join'): int
{
    return (new CatalogFederationPairingService($db))->storeParentPeer($parent, $source);
}

/** @return array<string,mixed> */
function federation_auto_claim_parent(PDO $db, string $parentUrl, int $requestId, string $requestToken): array
{
    return (new CatalogFederationPairingService($db))->autoClaimParent(
        $parentUrl,
        $requestId,
        $requestToken
    );
}
