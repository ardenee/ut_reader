<?php
/**
 * Stable import outcome markers that distinguish operator-actionable failures
 * from valid packages that simply do not belong to the selected game profile.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

final class CatalogImportOutcome
{
    public const UNVERIFIED_PROFILE_MISMATCH = 'unverified_profile_mismatch';

    public static function isProfileMismatchMessage(string $message): bool
    {
        return str_starts_with(trim($message), 'Game/profile mismatch.');
    }
}
