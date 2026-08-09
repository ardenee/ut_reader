<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical federation package availability helper API for existing callers.
 * Why: Package matching and base-game availability policy now live in CatalogFederationPackageAvailabilityService.
 * Role: Thin compatibility facade; do not add federation availability persistence or matching implementation here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/BaseGameProtection.php';
require_once __DIR__ . '/FederationBaseGamePolicy.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationPackageAvailabilityService;

/** @return array<string,mixed>|null */
function federation_package_match_canonical(PDO $db, string $package, string $gameName = '', string $engineKey = ''): ?array
{
    return (new CatalogFederationPackageAvailabilityService($db))->matchCanonical($package, $gameName, $engineKey);
}

/** @return array<string,mixed>|null */
function federation_package_match_alias(PDO $db, string $package, string $gameName = '', string $engineKey = ''): ?array
{
    return (new CatalogFederationPackageAvailabilityService($db))->matchAlias($package, $gameName, $engineKey);
}

/** @return array<string,mixed>|null */
function federation_package_match(
    PDO $db,
    string $package,
    string $gameName = '',
    string $engineKey = '',
    string $wantedGuid = '',
    string $wantedMd5 = ''
): ?array {
    return (new CatalogFederationPackageAvailabilityService($db))->match(
        $package,
        $gameName,
        $engineKey,
        $wantedGuid,
        $wantedMd5
    );
}

/** @return array<string,mixed>|null */
function federation_base_game_package_match(PDO $db, string $package, string $gameName = '', string $engineKey = ''): ?array
{
    return (new CatalogFederationPackageAvailabilityService($db))->baseGamePackageMatch($package, $gameName, $engineKey);
}

/** @return array<string,mixed> */
function federation_package_unavailable_result(bool $isBaseGame = false, bool $policyExcluded = false, ?array $match = null): array
{
    return CatalogFederationPackageAvailabilityService::unavailableResult($isBaseGame, $policyExcluded, $match);
}

/** @return array<string,mixed> */
function federation_package_availability(PDO $db, array $item): array
{
    return (new CatalogFederationPackageAvailabilityService($db))->availability($item);
}
