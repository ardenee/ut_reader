<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

final class ApplicationSecretStore
{
    private const PREFIX = 'sec1:';
    private const CIPHER = 'aes-256-gcm';
    private const AAD = 'unrealdb:application-secret:v1';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    public function __construct(private readonly string $masterKey)
    {
        if (strlen($masterKey) !== 32) {
            throw new \InvalidArgumentException('Application security master key must contain exactly 32 bytes.');
        }
    }

    public static function fromEnvironment(): self
    {
        $value = trim((string)(getenv('UNREALDB_SECURITY_MASTER_KEY') ?: ''));
        if ($value === '') {
            throw new \RuntimeException('UNREALDB_SECURITY_MASTER_KEY is required for administrator MFA.');
        }
        $key = self::decodeKey($value);
        if ($key === null || strlen($key) !== 32) {
            throw new \RuntimeException('UNREALDB_SECURITY_MASTER_KEY must be a base64 or hexadecimal 32-byte key.');
        }
        return new self($key);
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '' || strlen($plaintext) > 512) {
            throw new \InvalidArgumentException('Application secret length is invalid.');
        }
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->masterKey, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD, self::TAG_BYTES);
        if (!is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
            throw new \RuntimeException('Could not encrypt application secret.');
        }
        return self::PREFIX . self::base64UrlEncode($nonce . $tag . $ciphertext);
    }

    public function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new \RuntimeException('Application secret is not encrypted.');
        }
        $payload = self::base64UrlDecode(substr($stored, strlen(self::PREFIX)));
        if ($payload === null || strlen($payload) <= self::NONCE_BYTES + self::TAG_BYTES) {
            throw new \RuntimeException('Encrypted application secret is malformed.');
        }
        $nonce = substr($payload, 0, self::NONCE_BYTES);
        $tag = substr($payload, self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($payload, self::NONCE_BYTES + self::TAG_BYTES);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->masterKey, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD);
        if (!is_string($plaintext) || $plaintext === '') {
            throw new \RuntimeException('Application secret authentication failed.');
        }
        return $plaintext;
    }

    private static function decodeKey(string $value): ?string
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
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }
}
