<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds and signs the canonical federation streaming-transfer signature payload.
 * Why: Transfer payload canonicalization and HMAC/Ed25519 cryptography are protocol concerns independent of HTTP parsing and replay persistence.
 * Role: Infrastructure federation cryptography service preserving the existing streaming transfer wire format.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Security\CatalogFederationKeyMaterial;
use UnrealDb\Catalog\Infrastructure\Security\CatalogFederationPeerSecretService;

final class CatalogFederationTransferSignatureService
{
    public static function payload(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $sha256,
        int $bytes,
        int $remoteId,
        string $name
    ): string {
        return strtoupper($method) . "\n"
            . $path . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . strtolower($sha256) . "\n"
            . $bytes . "\n"
            . $remoteId . "\n"
            . $name;
    }

    public static function hmac(
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $sha256,
        int $bytes,
        int $remoteId,
        string $name
    ): string {
        return hash_hmac(
            'sha256',
            self::payload($method, $path, $timestamp, $nonce, $sha256, $bytes, $remoteId, $name),
            CatalogFederationPeerSecretService::forCrypto($secret)
        );
    }

    public static function ed25519(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $sha256,
        int $bytes,
        int $remoteId,
        string $name
    ): string {
        $secret = CatalogFederationKeyMaterial::ed25519SecretKey();
        if ($secret === '') {
            throw new RuntimeException('Ed25519 federation transfer signing is not configured.');
        }

        return CatalogFederationKeyMaterial::base64UrlEncode(
            sodium_crypto_sign_detached(
                self::payload($method, $path, $timestamp, $nonce, $sha256, $bytes, $remoteId, $name),
                $secret
            )
        );
    }

    public static function verifyEd25519(
        string $publicKey,
        string $signature,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $sha256,
        int $bytes,
        int $remoteId,
        string $name
    ): bool {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        try {
            $key = CatalogFederationKeyMaterial::base64UrlDecode($publicKey);
            $sig = CatalogFederationKeyMaterial::base64UrlDecode($signature);
        } catch (Throwable) {
            return false;
        }

        if (strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(
            $sig,
            self::payload($method, $path, $timestamp, $nonce, $sha256, $bytes, $remoteId, $name),
            $key
        );
    }
}
