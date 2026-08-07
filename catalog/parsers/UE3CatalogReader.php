<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for UE3 catalog reader.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
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
