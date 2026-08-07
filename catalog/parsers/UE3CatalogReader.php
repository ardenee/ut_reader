<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Adapts the standalone UE3 reader for catalog use by loading its source, renaming the reader class, and substituting the catalog LZO decoder before evaluation.
 * Why: The standalone UE3 reader cannot be loaded unchanged alongside catalog reader classes, so this adapter creates an isolated catalog-specific reader.
 * Role: UE3 parser compatibility bridge used by the catalog reader-resolution path.
 * Audit: Specialized adapter, not a UI page; consolidate only when the standalone UE3 reader can be consumed directly without runtime source rewriting.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/LzoDecoder.php';

if (class_exists('CatalogUE3PackageReader', false)) {
    return;
}

$sourcePath = __DIR__ . '/../../UE3/UnrealPackageReader.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('Could not read UE3 parser source: ' . $sourcePath);
}

$source = preg_replace('/^<\?php\s*/', '', $source, 1);
$source = preg_replace('/declare\(strict_types=1\);\s*/', '', $source, 1);
$source = str_replace("error_reporting(E_ALL);\nini_set('display_errors', '1');\nini_set('display_startup_errors', '1');\n", '', $source);
$source = str_replace('final class UnrealPackageReader', 'final class CatalogUE3PackageReader', $source);
$source = str_replace(
    "if ((\$flags & self::COMPRESS_LZO) !== 0) throw new RuntimeException('LZO package requires PHP FFI + liblzo2. Enable ffi.enable=true and install liblzo2 on Synology.');",
    "if ((\$flags & self::COMPRESS_LZO) !== 0) return CatalogLzoDecoder::decompressLzo1x(\$src, \$expected);",
    $source
);

if (class_exists('UEFolderBinaryReader', false)) {
    throw new RuntimeException('UEFolderBinaryReader already exists; catalog UE3 parser must be loaded in a clean request.');
}

eval($source);

if (!class_exists('CatalogUE3PackageReader', false)) {
    throw new RuntimeException('Catalog UE3 parser failed to define CatalogUE3PackageReader.');
}
