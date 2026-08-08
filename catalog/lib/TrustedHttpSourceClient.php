<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the two remaining federation compatibility calls while large legacy callers are migrated separately.
 * Why: HTTP source scanning, pairing and file transfer already use namespaced HTTP clients directly; only FederationAuth
 *      and CatalogFederationConnectionActions still require this static API.
 * Role: Minimal compatibility shim; delete once the final two federation callers are migrated.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationHttpClient;

final class TrustedHttpSourceClient
{
    public static function configureFederationTesting(bool $enabled): void
    {
        CatalogFederationHttpClient::configureTesting($enabled);
    }

    /** @param list<string> $headers @return array<string,mixed> */
    public static function postJson(
        string $url,
        array $headers,
        string $body,
        int $maxResponseBytes = 8388608,
        int $timeout = 60
    ): array {
        return CatalogFederationHttpClient::fromRuntime()->postJson(
            $url,
            $headers,
            $body,
            $maxResponseBytes,
            $timeout
        );
    }
}
