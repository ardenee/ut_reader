<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical federation base-game policy helper API for existing callers.
 * Why: Schema readiness, cached parent-policy persistence and effective policy resolution now live under Infrastructure.
 * Role: Thin compatibility facade; do not add federation policy persistence or resolution implementation here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationBaseGamePolicyService;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationParentPolicyStore;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationPolicySchemaGuard;

function federation_policy_bool(mixed $value, bool $default = true): bool
{
    return CatalogFederationBaseGamePolicyService::boolValue($value, $default);
}

function federation_base_game_policy_ensure_schema(PDO $db): void
{
    (new CatalogFederationPolicySchemaGuard($db))->ensure();
}

/** @return array<string,mixed> */
function federation_parent_base_game_policy(PDO $db): array
{
    return (new CatalogFederationBaseGamePolicyService($db))->parentPolicy();
}

/** @return array<string,mixed> */
function federation_peer_permissions(array $peer): array
{
    return CatalogFederationParentPolicyStore::decodePermissions($peer);
}

/** @param array<string,mixed> $policy */
function federation_cache_parent_base_game_policy(PDO $db, int $peerId, array $policy): void
{
    (new CatalogFederationParentPolicyStore($db))->cache($peerId, $policy);
}

/** @param array<string,mixed>|null $parentPeer */
function federation_ignore_base_game_files(PDO $db, ?array $parentPeer = null): bool
{
    return (new CatalogFederationBaseGamePolicyService($db))->ignoreBaseGameFiles($parentPeer);
}

/** @param array<string,mixed>|null $parentPeer */
function federation_base_game_allowed(PDO $db, ?array $parentPeer = null): bool
{
    return (new CatalogFederationBaseGamePolicyService($db))->baseGameAllowed($parentPeer);
}

/** @param array<string,mixed> $row @param array<string,mixed>|null $parentPeer */
function federation_base_game_row_visible(PDO $db, array $row, ?array $parentPeer = null): bool
{
    return (new CatalogFederationBaseGamePolicyService($db))->rowVisible($row, $parentPeer);
}

/** @param list<array<string,mixed>> $rows @param array<string,mixed>|null $parentPeer @return list<array<string,mixed>> */
function federation_filter_base_game_rows(PDO $db, array $rows, ?array $parentPeer = null): array
{
    return (new CatalogFederationBaseGamePolicyService($db))->filterRows($rows, $parentPeer);
}
