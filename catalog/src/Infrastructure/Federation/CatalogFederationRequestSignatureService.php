<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds and verifies canonical signatures for ordinary federation JSON requests.
 * Why: Request payload canonicalization and HMAC/Ed25519 cryptography are protocol concerns independent of HTTP parsing and peer/replay persistence.
 * Role: Infrastructure federation cryptography service preserving the existing signed JSON request wire format.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use RuntimeException;
use Throwable;

final class CatalogFederationRequestSignatureService
{
    public static function bodyHash(string $body): string
    {
        return hash('sha256', $body);
    }

    public static function payload(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $bodyHash
    ): string {
        return strtoupper($method) . "\n"
            . $path . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . $bodyHash;
    }

    public static function hmac(
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        return hash_hmac(
            'sha256',
            self::payload($method, $path, $timestamp, $nonce, self::bodyHash($body)),
            \fed_secret_for_crypto($secret)
        );
    }

    public static function verifyHmac(
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
        string $signature
    ): bool {
        return hash_equals(
            self::hmac($secret, $method, $path, $timestamp, $nonce, $body),
            $signature
        );
    }

    public static function ed25519(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        $secret = \fed_ed25519_secret_key();
        if ($secret === '') {
            throw new RuntimeException('Ed25519 federation signing is not configured.');
        }

        $payload = self::payload($method, $path, $timestamp, $nonce, self::bodyHash($body));
        return \fed_base64url_encode(sodium_crypto_sign_detached($payload, $secret));
    }

    public static function verifyEd25519(
        string $publicKey,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
        string $signature
    ): bool {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        try {
            $keyBytes = \fed_base64url_decode($publicKey);
            $signatureBytes = \fed_base64url_decode($signature);
        } catch (Throwable) {
            return false;
        }

        if (strlen($keyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        $payload = self::payload($method, $path, $timestamp, $nonce, self::bodyHash($body));
        return sodium_crypto_sign_verify_detached($signatureBytes, $payload, $keyBytes);
    }
}
