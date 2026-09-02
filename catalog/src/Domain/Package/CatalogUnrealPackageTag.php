<?php
/**
 * Unreal package tag policy shared by import, redirect, archive and inspection paths.
 *
 * Standard Unreal packages use 0x9E2A83C1. Killing Floor uses Epic's known
 * game-specific legacy tag 0x9E2A83C2 while retaining the ordinary UE2 package
 * summary layout after the tag.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Domain\Package;

final class CatalogUnrealPackageTag
{
    public const STANDARD = 0x9E2A83C1;
    public const KILLING_FLOOR = 0x9E2A83C2;

    public static function isSupportedLittleEndianValue(int $tag): bool
    {
        return $tag === self::STANDARD || $tag === self::KILLING_FLOOR;
    }

    public static function isSupportedBytes(string $bytes): bool
    {
        if (strlen($bytes) < 4) {
            return false;
        }

        $tag = substr($bytes, 0, 4);
        return $tag === "\xC1\x83\x2A\x9E"
            || $tag === "\x9E\x2A\x83\xC1"
            || $tag === "\xC2\x83\x2A\x9E";
    }

    public static function variant(int $tag): string
    {
        return match ($tag) {
            self::STANDARD => 'standard',
            self::KILLING_FLOOR => 'killing_floor',
            default => 'unknown',
        };
    }

    public static function expectedMagicHex(): string
    {
        return 'C1832A9E|9E2A83C1|C2832A9E';
    }
}
