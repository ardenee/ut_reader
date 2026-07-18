<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

final class FederationSecretStore
{
    private const PREFIX = 'enc1:';
    private const CIPHER = 'aes-256-gcm';
    private const AAD = 'unrealdb:federation-peer:v1';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private const MAX_STORED_BYTES = 128;

    public function __construct(private readonly ?string $masterKey)
    {
        if ($masterKey !== null && strlen($masterKey) !== 32) {
            throw new \InvalidArgumentException('Federation master key must contain exactly 32 bytes.');
        }
    }

    public static function fromEnvironment(): self
    {
        $value = trim((string)(getenv('UNREALDB_FEDERATION_MASTER_KEY') ?: ''));
        if ($value === '') {
            return new self(null);
        }

        $key = self::decodeConfiguredKey($value);
        if ($key === null || strlen($key) !== 32) {
            throw new \RuntimeException('UNREALDB_FEDERATION_MASTER_KEY must be a base64 or hexadecimal 32-byte key.');
        }

        return new self($key);
    }

    public function hasMasterKey(): bool
    {
        return $this->masterKey !== null;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public function encrypt(string $plaintext): string
    {
        if ($this->masterKey === null) {
            throw new \RuntimeException('Federation secret encryption requires UNREALDB_FEDERATION_MASTER_KEY.');
        }
        if ($plaintext === '' || strlen($plaintext) > 64) {
            throw new \InvalidArgumentException('Federation shared secrets must contain between 1 and 64 bytes.');
        }
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('Federation secret encryption requires the OpenSSL PHP extension.');
        }

        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->masterKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::AAD,
            self::TAG_BYTES
        );
        if (!is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
            throw new \RuntimeException('Could not encrypt the federation shared secret.');
        }

        $stored = self::PREFIX . self::base64UrlEncode($nonce . $tag . $ciphertext);
        if (strlen($stored) > self::MAX_STORED_BYTES) {
            throw new \RuntimeException('Encrypted federation shared secret exceeds the storage limit.');
        }

        return $stored;
    }

    public function decrypt(string $stored): string
    {
        if (!$this->isEncrypted($stored)) {
            return $stored;
        }
        if ($this->masterKey === null) {
            throw new \RuntimeException('Encrypted federation secrets cannot be read without UNREALDB_FEDERATION_MASTER_KEY.');
        }
        if (!function_exists('openssl_decrypt')) {
            throw new \RuntimeException('Federation secret decryption requires the OpenSSL PHP extension.');
        }

        $payload = self::base64UrlDecode(substr($stored, strlen(self::PREFIX)));
        if ($payload === null || strlen($payload) <= self::NONCE_BYTES + self::TAG_BYTES) {
            throw new \RuntimeException('Encrypted federation shared secret is malformed.');
        }

        $nonce = substr($payload, 0, self::NONCE_BYTES);
        $tag = substr($payload, self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($payload, self::NONCE_BYTES + self::TAG_BYTES);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->masterKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::AAD
        );
        if (!is_string($plaintext) || $plaintext === '') {
            throw new \RuntimeException('Federation shared secret authentication failed.');
        }

        return $plaintext;
    }

    private static function decodeConfiguredKey(string $value): ?string
    {
        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);
            return is_string($decoded) ? $decoded : null;
        }
        if (str_starts_with($value, 'hex:')) {
            $decoded = hex2bin(substr($value, 4));
            return is_string($decoded) ? $decoded : null;
        }
        if (preg_match('/^[A-Fa-f0-9]{64}$/', $value) === 1) {
            $decoded = hex2bin($value);
            return is_string($decoded) ? $decoded : null;
        }

        $decoded = base64_decode($value, true);
        return is_string($decoded) ? $decoded : null;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }
}
