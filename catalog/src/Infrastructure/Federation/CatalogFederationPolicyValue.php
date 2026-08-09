<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Normalizes scalar values used by federation policy payloads and settings.
 * Why: Parent-policy persistence and effective-policy resolution both need identical boolean interpretation without depending on each other.
 * Role: Small policy value utility shared by federation policy infrastructure.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

final class CatalogFederationPolicyValue
{
    public static function bool(mixed $value, bool $default = true): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
