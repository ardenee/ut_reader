<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns federation nonce generation, key encoding and local Ed25519 key material loading.
 * Why: Key parsing and local signing material are security concerns independent of the legacy federation helper facade.
 * Role: Infrastructure security service preserving the existing base64url, environment and key-id contracts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

use InvalidArgumentException;
use RuntimeException;

final class CatalogFederationKeyMaterial
{
    public static function randomSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[A-Za-z0-9_+\/-]+={0,2}$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid federation key encoding.');
        }
        $standard = strtr($value, '-_', '+/');
        $standard .= str_repeat('=', (4 - strlen($standard) % 4) % 4);
        $decoded = base64_decode($standard, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid federation key encoding.');
        }
        return $decoded;
    }

    public static function ed25519SecretKey(): string
    {
        static $loaded = false;
        static $secret = '';
        if ($loaded) {
            return $secret;
        }
        $loaded = true;

        $configured = trim((string)(getenv('UNREALDB_FEDERATION_ED25519_PRIVATE_KEY') ?: ''));
        if ($configured === '') {
            return '';
        }
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException('Ed25519 federation signing requires the PHP sodium extension.');
        }

        $decoded = self::base64UrlDecode($configured);
        if (strlen($decoded) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $pair = sodium_crypto_sign_seed_keypair($decoded);
            $secret = sodium_crypto_sign_secretkey($pair);
        } elseif (strlen($decoded) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $secret = $decoded;
        } else {
            throw new RuntimeException(
                'UNREALDB_FEDERATION_ED25519_PRIVATE_KEY must encode a 32-byte seed or 64-byte secret key.'
            );
        }
        return $secret;
    }

    public static function ed25519PublicKey(): string
    {
        $secret = self::ed25519SecretKey();
        return $secret === '' ? '' : sodium_crypto_sign_publickey_from_secretkey($secret);
    }

    public static function ed25519KeyId(string $publicKey): string
    {
        return $publicKey === '' ? '' : strtoupper(substr(hash('sha256', $publicKey), 0, 24));
    }
}
