<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog parser.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Infrastructure/Readers/CatalogReaderResolver.php';

function catalog_load_reader_class(array $config, string $engineKey): string
{
    return \UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver::resolve(
        $config,
        $engineKey,
        'Reader not found for',
        'Reader file loaded for ',
        ['UE4'],
        false
    );
}

function catalog_try_read_package_header(array $config, string $engineKey, string $path): array
{
    $class = catalog_load_reader_class($config, $engineKey);
    $reader = new $class($path);
    // The canonical UE1 reader captures decoding exceptions in its issue
    // list and substitutes an empty header. Source scans must not mistake
    // that fallback for a successfully loaded Epic package summary.
    if (strtoupper($engineKey) === 'UE1' && method_exists($reader, 'validatePackage')) {
        $issues = $reader->validatePackage();
        if ($issues !== []) {
            // A rejected original file must retain its real Epic-format
            // diagnostic, not appear to be a corrupt modern package tag.
            require_once __DIR__ . '/GameProfiles.php';
            $identity = gp_read_legacy_summary($path);
            if (in_array(($identity['format'] ?? ''), ['early_unreal_resource', 'early_unrealfile'], true)) {
                throw new RuntimeException('UE1 original format: ' . (string)($identity['reason'] ?? 'reader unsupported'));
            }
            throw new RuntimeException('UE1 package header validation failed: ' . implode('; ', $issues));
        }
    }
    if (!method_exists($reader, 'getHeader')) {
        throw new RuntimeException('Reader missing getHeader()');
    }
    $header = $reader->getHeader();
    if (!is_array($header)) {
        throw new RuntimeException('Reader returned invalid header');
    }
    return $header;
}

function catalog_header_guid(array $header): string
{
    return trim((string)($header['guid'] ?? $header['GUID'] ?? $header['packageGuid'] ?? ''));
}
