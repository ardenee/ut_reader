<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns the short-lived administrator reauthentication session window.
 * Why: Sensitive-action reauthentication state is separate from persistent MFA credential handling.
 * Role: Application security service preserving the existing session timestamp and timeout contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Security;

use RuntimeException;

final class CatalogAdminReauthenticationService
{
    public static function markRecent(): void
    {
        \catalog_start_session();
        $_SESSION['catalog_auth_verified_at'] = time();
    }

    public static function seconds(): int
    {
        return \catalog_security_env_seconds('UNREALDB_ADMIN_REAUTH_SECONDS', 600, 60, 86400);
    }

    public static function hasRecent(): bool
    {
        return \catalog_support_is_admin()
            && (time() - (int)($_SESSION['catalog_auth_verified_at'] ?? 0)) <= self::seconds();
    }

    public static function requireRecent(): void
    {
        if (!self::hasRecent()) {
            throw new RuntimeException(
                'Administrator reauthentication is required. Open admin-security.php and confirm your password and MFA code.'
            );
        }
    }
}
