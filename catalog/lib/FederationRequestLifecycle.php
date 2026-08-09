<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical federation request lifecycle helper API for existing callers.
 * Why: Legacy-denial repair, request-item relinking and aggregate request status now live in
 *      CatalogFederationRequestLifecycleService.
 * Role: Thin compatibility facade; do not add federation lifecycle persistence or matching implementation here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/BaseGameProtection.php';
require_once __DIR__ . '/FederationPackageAvailability.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationRequestLifecycleService;

function federation_request_legacy_unavailable_denial(string $message): bool
{
    return CatalogFederationRequestLifecycleService::legacyUnavailableDenial($message);
}

function federation_request_legacy_base_game_denial(string $message): bool
{
    return CatalogFederationRequestLifecycleService::legacyBaseGameDenial($message);
}

function federation_request_waiting_message(bool $isBaseGameDependency = false): string
{
    return CatalogFederationRequestLifecycleService::waitingMessage($isBaseGameDependency);
}

function federation_request_recalculate_header(PDO $db, int $requestId): string
{
    return (new CatalogFederationRequestLifecycleService($db))->recalculateHeader($requestId);
}

/** @return array<string,int|string> */
function federation_refresh_request_matches(PDO $db, int $requestId): array
{
    return (new CatalogFederationRequestLifecycleService($db))->refreshMatches($requestId);
}
