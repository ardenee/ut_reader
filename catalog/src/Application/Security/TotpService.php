<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `TotpService` for TOTP service.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Security;

final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes(max(10, min($bytes, 32))));
    }

    public static function provisioningUri(string $issuer, string $account, string $secret): string
    {
        $issuer = trim($issuer) !== '' ? trim($issuer) : 'UnrealDB';
        $account = trim($account) !== '' ? trim($account) : 'administrator';
        $label = rawurlencode($issuer . ':' . $account);
        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode(strtoupper($secret))
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function verify(string $secret, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }
        $timestamp ??= time();
        $counter = intdiv($timestamp, 30);
        for ($offset = -max(0, min($window, 5)); $offset <= max(0, min($window, 5)); $offset++) {
            if (hash_equals(self::code($secret, $counter + $offset), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function code(string $secret, int $counter): string
    {
        $key = self::base32Decode(strtoupper(preg_replace('/\s+/', '', $secret) ?? ''));
        if ($key === '') {
            throw new \InvalidArgumentException('TOTP secret is invalid.');
        }
        $high = intdiv($counter, 0x100000000);
        $low = $counter % 0x100000000;
        $binaryCounter = pack('N2', $high, $low);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string)($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= self::ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    private static function base32Decode(string $encoded): string
    {
        if ($encoded === '' || preg_match('/^[A-Z2-7]+$/', $encoded) !== 1) {
            return '';
        }
        $bits = '';
        foreach (str_split($encoded) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                return '';
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $out .= chr(bindec($chunk));
        }
        return $out;
    }
}
