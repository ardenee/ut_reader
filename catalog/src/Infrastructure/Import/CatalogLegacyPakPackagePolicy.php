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
    /**
     * Enforce the content-derived engine family after bytes arrive, before
     * writing a batch manifest or creating an import job. This cannot prove
     * game ownership for a UE4/UE5 UnrealPak; that still requires game-specific
     * package evidence during normal import.
     */
    public static function assertTarget(string $sourcePath, string $originalName, string $engineKey): void
    {
        if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pak') {
            return;
        }
        $engineKey = strtoupper(trim($engineKey));
        if ($engineKey === 'UE3') {
            self::assertPackageHeader($sourcePath);
            return;
        }
        if (!in_array($engineKey, ['UE4', 'UE5'], true)) {
            throw new \RuntimeException('This game does not accept .pak file uploads.');
        }
        if (!is_file($sourcePath) || !is_readable($sourcePath) || is_link($sourcePath)) {
            throw new \RuntimeException('PAK upload source is unavailable.');
        }
        $head = @file_get_contents($sourcePath, false, null, 0, 8);
        if (is_string($head) && strlen($head) === 8 && substr($head, 0, 4) === "\xC1\x83\x2A\x9E") {
            $version = unpack('v', substr($head, 4, 2))[1];
            throw new \RuntimeException(
                'Wrong game for .pak: this is a standalone Unreal package, version ' . $version
                . ($version === 512 ? ' (compatible with the UT3 profile, game ID 6).' : '.')
                . ' It is not a UE4/UE5 UnrealPak container.'
            );
        }
        require_once __DIR__ . '/../../../lib/CatalogPakArchive.php';
        if (\catalog_pak_footer_candidates($sourcePath) === []) {
            throw new \RuntimeException(
                'No recognised UnrealPak footer was found. File does not match the selected UE4/UE5 container format.'
            );
        }
    }

}
