<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Resolves the configured algorithm used for outgoing federation signatures.
 * Why: Signature-algorithm selection is protocol policy and should not be embedded in the legacy FederationAuth facade or transport callers.
 * Role: Small federation protocol policy preserving the existing Ed25519-or-HMAC fallback behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

final class CatalogFederationOutgoingSignaturePolicy
{
    public static function resolve(?string $configured = null): string
    {
        $configured ??= (string)(getenv('UNREALDB_FEDERATION_SIGNATURE_ALGORITHM') ?: 'hmac-sha256');
        return strtolower(trim($configured)) === 'ed25519' ? 'ed25519' : 'hmac-sha256';
    }
}
