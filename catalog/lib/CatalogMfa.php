<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves historical MFA and administrator reauthentication helper functions.
 * Why: Credential handling and recent-auth session policy now have focused namespaced security owners.
 * Role: Thin compatibility facade; do not add MFA persistence or reauthentication policy here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

use UnrealDb\Catalog\Application\Security\CatalogAdminReauthenticationService;
use UnrealDb\Catalog\Infrastructure\Security\CatalogMfaService;

/** @param array<string,mixed> $user */
function catalog_mfa_enabled(array $user): bool
{
    return CatalogMfaService::enabled($user);
}

/** @param array<string,mixed> $user */
function catalog_mfa_secret(array $user): string
{
    return CatalogMfaService::secret($user);
}

/** @return list<string> */
function catalog_mfa_recovery_codes(int $count = 10): array
{
    return CatalogMfaService::recoveryCodes($count);
}

/** @param list<string> $codes */
function catalog_mfa_recovery_hashes(array $codes): string
{
    return CatalogMfaService::recoveryHashes($codes);
}

/** @param array<string,mixed> $user */
function catalog_mfa_verify(PDO $db, array $user, string $code): bool
{
    return (new CatalogMfaService($db))->verify($user, $code);
}

/** @return list<string> */
function catalog_mfa_enable(PDO $db, int $userId, string $secret, string $code): array
{
    return (new CatalogMfaService($db))->enable($userId, $secret, $code);
}

function catalog_mfa_disable(PDO $db, int $userId): void
{
    (new CatalogMfaService($db))->disable($userId);
}

function catalog_mark_recent_admin_auth(): void
{
    CatalogAdminReauthenticationService::markRecent();
}

function catalog_recent_admin_auth_seconds(): int
{
    return CatalogAdminReauthenticationService::seconds();
}

function catalog_has_recent_admin_auth(): bool
{
    return CatalogAdminReauthenticationService::hasRecent();
}

function catalog_require_recent_admin_auth(): void
{
    CatalogAdminReauthenticationService::requireRecent();
}
