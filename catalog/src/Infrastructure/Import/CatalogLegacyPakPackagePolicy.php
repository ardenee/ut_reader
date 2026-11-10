<?php
/**
 * Narrow transport exception for legacy UE3 Unreal packages distributed
 * with a .pak extension. This is NOT UE3 UnrealPak support; the bytes are
 * still validated by the normal UE3 package reader and selected game profile.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

final class CatalogLegacyPakPackagePolicy
{
    public static function isCandidate(string $engineKey, string $originalName): bool
    {
        return strtoupper(trim($engineKey)) === 'UE3'
            && strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) === 'pak';
    }

    public static function assertPackageHeader(string $sourcePath): void
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath) || is_link($sourcePath)) {
            throw new \RuntimeException('Legacy .pak package source is unavailable.');
        }
        $prefix = @file_get_contents($sourcePath, false, null, 0, 4);
        // Epic's UE3 PACKAGE_FILE_TAG in its stored little-endian form.
        if ($prefix !== "\xC1\x83\x2A\x9E") {
            throw new \RuntimeException(
                'This .pak is not a UE3 standalone Unreal package (missing UE3 package header). '
                . 'UE4/UE5 UnrealPak archives cannot be imported under a UE3 game.'
            );
        }
    }
}
