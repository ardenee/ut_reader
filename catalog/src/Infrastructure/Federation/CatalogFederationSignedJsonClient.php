<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Encodes, signs and sends outgoing federation JSON POST requests.
 * Why: JSON serialization, federation auth headers and HTTP dispatch form one outbound transport boundary and should not live in the legacy auth facade.
 * Role: Infrastructure federation transport client preserving the existing signed POST headers, signing rules, response limit and timeout contracts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Security\CatalogFederationKeyMaterial;

final class CatalogFederationSignedJsonClient
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function post(
        string $url,
        string $siteId,
        string $secret,
        array $payload,
        int $maxResponseBytes,
        int $timeout = 60
    ): array {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('Could not encode federation payload.');
        }

        $timestamp = date('c');
        $nonce = CatalogFederationKeyMaterial::randomSecret();
        $headers = self::headers($url, $siteId, $secret, $body, $timestamp, $nonce);

        return CatalogFederationHttpClient::fromRuntime()->postJson(
            $url,
            $headers,
            $body,
            $maxResponseBytes,
            $timeout
        );
    }

    /** @return list<string> */
    public static function headers(
        string $url,
        string $siteId,
        string $secret,
        string $body,
        string $timestamp,
        string $nonce
    ): array {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $algorithm = CatalogFederationOutgoingSignaturePolicy::resolve();
        $headers = [
            'Content-Type: application/json',
            'User-Agent: UnrealFileCatalogFederation/2.0',
            'X-Site-Id: ' . $siteId,
            'X-Timestamp: ' . $timestamp,
            'X-Nonce: ' . $nonce,
            'X-Signature-Algorithm: ' . $algorithm,
        ];

        if ($algorithm === 'ed25519') {
            $publicKey = CatalogFederationKeyMaterial::ed25519PublicKey();
            if ($publicKey === '') {
                throw new RuntimeException(
                    'Ed25519 outgoing federation signing is selected but no private key is configured.'
                );
            }
            $signature = CatalogFederationRequestSignatureService::ed25519(
                'POST',
                $path,
                $timestamp,
                $nonce,
                $body
            );
            $headers[] = 'X-Key-Id: ' . CatalogFederationKeyMaterial::ed25519KeyId($publicKey);
        } else {
            $signature = CatalogFederationRequestSignatureService::hmac(
                $secret,
                'POST',
                $path,
                $timestamp,
                $nonce,
                $body
            );
        }

        $headers[] = 'X-Signature: ' . $signature;
        return $headers;
    }
}
