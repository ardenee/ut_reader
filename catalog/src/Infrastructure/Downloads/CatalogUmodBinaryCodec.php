<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Encodes and decodes the byte primitives used by legacy Unreal UMOD-family archives.
 * Why: Compact indices, UE1 strings, little-endian integers and Unreal appMemCrc are binary-format concerns,
 *      not package planning or descriptor policy.
 * Role: Downloads infrastructure codec shared by current UMOD generation and compatibility validators.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use RuntimeException;

final class CatalogUmodBinaryCodec
{
    /** @var array<int,int>|null */
    private static ?array $crcTable = null;

    public static function compactIndex(int $value): string
    {
        $negative = $value < 0;
        $magnitude = abs($value);
        $first = $magnitude & 0x3F;
        $magnitude >>= 6;
        if ($negative) {
            $first |= 0x80;
        }
        if ($magnitude > 0) {
            $first |= 0x40;
        }
        $out = chr($first);
        while ($magnitude > 0) {
            $next = $magnitude & 0x7F;
            $magnitude >>= 7;
            if ($magnitude > 0) {
                $next |= 0x80;
            }
            $out .= chr($next);
        }
        return $out;
    }

    public static function readCompactIndex(string $data, int &$offset): int
    {
        if ($offset >= strlen($data)) {
            throw new RuntimeException('Unexpected end of compact index.');
        }
        $first = ord($data[$offset++]);
        $negative = ($first & 0x80) !== 0;
        $continuation = ($first & 0x40) !== 0;
        $value = $first & 0x3F;
        $shift = 6;
        $count = 1;
        while ($continuation) {
            if ($offset >= strlen($data) || $count >= 5) {
                throw new RuntimeException('Invalid compact index.');
            }
            $byte = ord($data[$offset++]);
            $continuation = ($byte & 0x80) !== 0;
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;
            $count++;
        }
        return $negative ? -$value : $value;
    }

    public static function ue1String(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new RuntimeException('Archive filenames may not contain NUL bytes.');
        }
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            throw new RuntimeException('UMOD archive paths must use ASCII characters: ' . $value);
        }
        $bytes = $value . "\0";
        return self::compactIndex(strlen($bytes)) . $bytes;
    }

    public static function readUe1String(string $data, int &$offset): string
    {
        $length = self::readCompactIndex($data, $offset);
        if ($length < 0) {
            $bytes = -$length * 2;
            if ($offset + $bytes > strlen($data)) {
                throw new RuntimeException('Unexpected end of Unicode archive string.');
            }
            $raw = substr($data, $offset, $bytes);
            $offset += $bytes;
            $decoded = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE')
                : $raw;
            return rtrim($decoded, "\0");
        }
        if ($offset + $length > strlen($data)) {
            throw new RuntimeException('Unexpected end of archive string.');
        }
        $raw = substr($data, $offset, $length);
        $offset += $length;
        $raw = rtrim($raw, "\0");
        if ($raw === '' || preg_match('//u', $raw) === 1) {
            return $raw;
        }

        // UE1 Setup archives written on Windows serialize positive-length
        // FStrings as the process ANSI code page. Historic community UMODs
        // therefore commonly contain Windows-1252 filename bytes rather than
        // UTF-8. Normalize them here before paths reach JSON/job persistence.
        if (function_exists('mb_convert_encoding')) {
            $decoded = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
            if (is_string($decoded) && preg_match('//u', $decoded) === 1) {
                return $decoded;
            }
        }
        if (function_exists('iconv')) {
            $decoded = @iconv('Windows-1252', 'UTF-8//TRANSLIT', $raw);
            if (is_string($decoded) && preg_match('//u', $decoded) === 1) {
                return $decoded;
            }
        }

        throw new RuntimeException('Legacy UMOD archive string could not be converted from Windows-1252 to UTF-8.');
    }

    public static function packU32(int $value): string
    {
        return pack('V', $value & 0xFFFFFFFF);
    }

    /** @return array<int,int> */
    public static function unrealMemCrcTable(): array
    {
        if (self::$crcTable !== null) {
            return self::$crcTable;
        }

        $table = [];
        for ($index = 0; $index < 256; $index++) {
            $crc = ($index << 24) & 0xFFFFFFFF;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = (($crc & 0x80000000) !== 0)
                    ? ((($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF)
                    : (($crc << 1) & 0xFFFFFFFF);
            }
            $table[$index] = $crc;
        }
        return self::$crcTable = $table;
    }

    public static function unrealMemCrc(string $data, int $seed = 0): int
    {
        $table = self::unrealMemCrcTable();
        $crc = (~$seed) & 0xFFFFFFFF;
        $length = strlen($data);
        for ($offset = 0; $offset < $length; $offset++) {
            $lookup = (($crc >> 24) ^ ord($data[$offset])) & 0xFF;
            $crc = ((($crc << 8) & 0xFFFFFFFF) ^ $table[$lookup]) & 0xFFFFFFFF;
        }
        return (~$crc) & 0xFFFFFFFF;
    }

    /** @param resource $handle */
    public static function unrealMemCrcStream($handle, int $length, int $seed = 0): int
    {
        if ($length < 0 || fseek($handle, 0, SEEK_SET) !== 0) {
            throw new RuntimeException('Could not seek the UMOD payload for checksum validation.');
        }

        $table = self::unrealMemCrcTable();
        $crc = (~$seed) & 0xFFFFFFFF;
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = fread($handle, min(1024 * 1024, $remaining));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Could not read the UMOD payload for checksum validation.');
            }
            $chunkLength = strlen($chunk);
            for ($offset = 0; $offset < $chunkLength; $offset++) {
                $lookup = (($crc >> 24) ^ ord($chunk[$offset])) & 0xFF;
                $crc = ((($crc << 8) & 0xFFFFFFFF) ^ $table[$lookup]) & 0xFFFFFFFF;
            }
            $remaining -= $chunkLength;
        }
        return (~$crc) & 0xFFFFFFFF;
    }
}
