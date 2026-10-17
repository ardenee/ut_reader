<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use InvalidArgumentException;

/**
 * Epic UE5.8.3 FPackageObjectIndex::GenerateImportHashFromObjectPath:
 * normalize '.' and ':' to '/', ASCII-only TChar::ToLower, and CityHash64
 * of little-endian UTF-16 TCHAR bytes; clear the two high bits.
 *
 * FromVersePath hashes exact original UTF-8 bytes, without normalization.
 * Valid UTF-8 is required for conversion to TCHAR. Unpaired UTF-16 surrogate
 * inputs cannot be represented by this PHP UTF-8 API.
 */
final class Uedb5Ue5ScriptImportPathHasher
{
    public static function fromObjectPath(string $path): string
    {
        if (preg_match('//u', $path) !== 1) {
            throw new InvalidArgumentException('Script path must be valid UTF-8 for lossless UTF-16LE TCHAR encoding.');
        }
        $normalized = strtolower(str_replace(['.', ':'], '/', $path));
        $utf16 = iconv('UTF-8', 'UTF-16LE', $normalized);
        if ($utf16 === false) {
            throw new InvalidArgumentException('Cannot encode script path as UTF-16LE TCHAR bytes.');
        }
        return self::mask62(Uedb5Ue5CityHash64::hashHex($utf16));
    }

    /** Hash unchanged FUtf8StringView bytes, as in GenerateImportHashFromVersePath. */
    public static function fromVersePath(string $utf8Bytes): string
    {
        return self::mask62(Uedb5Ue5CityHash64::hashHex($utf8Bytes));
    }

    private static function mask62(string $raw): string
    {
        return strtoupper(dechex(hexdec($raw[0]) & 3)).substr($raw, 1);
    }
}
