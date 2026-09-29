<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader;

$failures = [];
$checks = [];
$check = static function (bool $condition, string $name) use (&$failures, &$checks): void {
    $checks[$name] = $condition;
    if (!$condition) { $failures[] = $name; }
};
$u64le = static function (string $hex): string {
    $raw = hex2bin(str_pad(strtoupper($hex), 16, '0', STR_PAD_LEFT));
    if (!is_string($raw) || strlen($raw) !== 8) {
        throw new RuntimeException('Invalid uint64 fixture.');
    }
    return strrev($raw);
};
$i64le = static function (int $value) use ($u64le): string {
    if ($value === -1) { return str_repeat("\xFF", 8); }
    if ($value < 0) { throw new RuntimeException('Fixture only supports -1 or non-negative int64 values.'); }
    return $u64le(str_pad(strtoupper(dechex($value)), 16, '0', STR_PAD_LEFT));
};
$nameBatch = static function (array $names): string {
    if ($names === []) { return pack('V', 0); }
    $headers = '';
    $strings = '';
    foreach ($names as $name) {
        $length = strlen($name);
        if ($length > 0x7fff) { throw new RuntimeException('Fixture name too long.'); }
        $headers .= chr(($length >> 8) & 0x7f) . chr($length & 0xff);
        $strings .= $name;
    }
    return pack('V2', count($names), strlen($strings))
        . str_repeat("\0", 8)
        . str_repeat("\0", count($names) * 8)
        . $headers
        . $strings;
};
$mapped = static fn(int $index, int $number = 0): string => pack('V2', $index, $number);
$obj = static fn(string $hex): string => $u64le($hex);
$exportObject = static fn(int $index): string => pack('V2', $index, 0);
$scriptObject = static fn(int $low): string => pack('V2', $low, 0x40000000);
$packageObject = static fn(int $packageIndex, int $hashIndex): string =>
    pack('V2', $hashIndex, 0x80000000 | $packageIndex);
$nullObject = str_repeat("\xFF", 8);

$packageId = '0123456789ABCDEF';
$providerId = 'FEDCBA9876543210';
$importedHash = '1122334455667788';

$versioning = pack('V', 3)
    . pack('V2', 522, 1018)
    . pack('V', 0)
    . pack('V', 1)
    . pack('V4', 1, 2, 3, 4)
    . pack('V', 7);
$names = $nameBatch(['/Game/TestPackage', 'ObjectA', 'CppClass']);

$bulkEntry = $i64le(10)
    . $i64le(-1)
    . $i64le(20)
    . pack('V', 1)
    . chr(2)
    . "\0\0\0";

$prefixBeforePad = Uedb5ZenPackageReader::SUMMARY_SIZE
    + strlen($versioning)
    + Uedb5ZenPackageReader::CELL_OFFSETS_SIZE
    + strlen($names)
    + 8;
$bulkPad = (8 - ($prefixBeforePad % 8)) % 8;
$bulkSection = $u64le(str_pad(strtoupper(dechex($bulkPad)), 16, '0', STR_PAD_LEFT))
    . str_repeat("\0", $bulkPad)
    . $i64le(strlen($bulkEntry))
    . $bulkEntry;

$prefixLength = Uedb5ZenPackageReader::SUMMARY_SIZE
    + strlen($versioning)
    + Uedb5ZenPackageReader::CELL_OFFSETS_SIZE
    + strlen($names)
    + strlen($bulkSection);
$alignBeforeHashes = (8 - ($prefixLength % 8)) % 8;
$prefixLength += $alignBeforeHashes;

$hashOffset = $prefixLength;
$importOffset = $hashOffset + 8;
$exportOffset = $importOffset + 8;
$cellImportOffset = $exportOffset + Uedb5ZenPackageReader::EXPORT_MAP_ENTRY_SIZE;
$cellExportOffset = $cellImportOffset + 8;
$bundleOffset = $cellExportOffset + Uedb5ZenPackageReader::CELL_EXPORT_MAP_ENTRY_SIZE;
$dependencyHeadersOffset = $bundleOffset + (4 * Uedb5ZenPackageReader::EXPORT_BUNDLE_ENTRY_SIZE);
$dependencyEntriesOffset = $dependencyHeadersOffset + (2 * Uedb5ZenPackageReader::DEPENDENCY_BUNDLE_HEADER_SIZE);
$importedNamesOffset = $dependencyEntriesOffset + Uedb5ZenPackageReader::DEPENDENCY_BUNDLE_ENTRY_SIZE;
$importedNames = $nameBatch(['/Game/Provider']) . pack('V', 0);
$headerSize = $importedNamesOffset + strlen($importedNames);

$cellOffsets = pack('V2', $cellImportOffset, $cellExportOffset);

$exportMap = $u64le('0000000000000000')
    . $u64le('0000000000000004')
    . $mapped(1)
    . $nullObject
    . $scriptObject(0x1234)
    . $nullObject
    . $nullObject
    . $u64le('AABBCCDDEEFF0011')
    . pack('V', 1)
    . chr(0)
    . "\0\0\0";
if (strlen($exportMap) !== Uedb5ZenPackageReader::EXPORT_MAP_ENTRY_SIZE) {
    throw new RuntimeException('ExportMap fixture size mismatch.');
}

$cellExport = $u64le('0000000000000004')
    . $u64le('0000000000000004')
    . $u64le('0000000000000004')
    . $mapped(2)
    . $u64le('8877665544332211');
if (strlen($cellExport) !== Uedb5ZenPackageReader::CELL_EXPORT_MAP_ENTRY_SIZE) {
    throw new RuntimeException('CellExportMap fixture size mismatch.');
}

$exportBundles = pack('V2', 0, 0)
    . pack('V2', 0, 1)
    . pack('V2', 1, 0)
    . pack('V2', 1, 1);

$dependencyHeaders = pack('V5', 0, 1, 0, 0, 0)
    . pack('V5', 0xFFFFFFFF, 0, 0, 0, 0);
$dependencyEntries = pack('V', 1);

$summary = pack('V2', 1, $headerSize)
    . $mapped(0)
    . pack('V2', 0, 0)
    . pack(
        'V7',
        $hashOffset,
        $importOffset,
        $exportOffset,
        $bundleOffset,
        $dependencyHeadersOffset,
        $dependencyEntriesOffset,
        $importedNamesOffset
    );
if (strlen($summary) !== Uedb5ZenPackageReader::SUMMARY_SIZE) {
    throw new RuntimeException('FZenPackageSummary fixture size mismatch.');
}

$header = $summary
    . $versioning
    . $cellOffsets
    . $names
    . $bulkSection
    . str_repeat("\0", $alignBeforeHashes)
    . $u64le($importedHash)
    . $packageObject(0, 0)
    . $exportMap
    . $scriptObject(0x5678)
    . $cellExport
    . $exportBundles
    . $dependencyHeaders
    . $dependencyEntries
    . $importedNames;
if (strlen($header) !== $headerSize) {
    throw new RuntimeException('Zen fixture HeaderSize mismatch: ' . strlen($header) . ' != ' . $headerSize);
}
$packageBytes = $header . 'DATA';

$storeEntry = [
    'entry_index' => 0,
    'package_id' => $packageId,
    'optional_segment' => false,
    'imported_package_ids' => [$providerId],
    'shader_map_hashes' => [],
];

$parsed = Uedb5ZenPackageReader::parse($packageBytes, $packageId, $storeEntry);
$check($parsed['package_family'] === 'zen-iostore', 'zen_package_family_isolated');
$check($parsed['package_id'] === $packageId, 'zen_package_id_lossless');
$check($parsed['package_name']['text'] === '/Game/TestPackage', 'zen_package_name_resolved_from_package_name_map');
$check($parsed['versioning_info']['zen_version'] === 3, 'zen_versioning_info_parsed');
$check($parsed['versioning_info']['package_version']['ue5'] === 1018, 'zen_ue5_package_version_parsed');
$check(count($parsed['versioning_info']['custom_versions']) === 1, 'zen_custom_versions_parsed');
$check(count($parsed['bulk_data_map']) === 1 && $parsed['bulk_data_map'][0]['cooked_index'] === 2, 'zen_bulk_data_map_parsed');
$check($parsed['imported_public_export_hashes'] === [$importedHash], 'zen_imported_public_export_hash_lossless');
$check(
    $parsed['import_map'][0]['type'] === 'PackageImport'
    && $parsed['import_map'][0]['provider_package_id'] === $providerId
    && $parsed['import_map'][0]['provider_public_export_hash'] === $importedHash,
    'zen_package_import_pairs_store_id_and_public_hash'
);
$check(
    $parsed['exports'][0]['public_export_hash'] === 'AABBCCDDEEFF0011'
    && $parsed['exports'][0]['class_index']['type'] === 'ScriptImport',
    'zen_export_map_public_hash_and_script_class_parsed'
);
$check($parsed['cell_import_map'][0]['type'] === 'ScriptImport', 'zen_cell_import_map_parsed');
$check($parsed['cell_exports'][0]['public_export_hash'] === '8877665544332211', 'zen_cell_export_map_parsed');
$check(count($parsed['export_bundle_entries']) === 4, 'zen_export_bundle_cardinality_verified');
$check(
    $parsed['dependency_bundle_headers'][0]['first_entry_index'] === 0
    && $parsed['dependency_bundle_headers'][0]['total_entry_count'] === 1,
    'zen_dependency_bundle_header_parsed'
);
$check(
    $parsed['dependency_bundle_entries'][0]['kind'] === 'Export'
    && $parsed['dependency_bundle_entries'][0]['local_export_index'] === 0,
    'zen_dependency_bundle_entry_parsed'
);
$check(
    $parsed['imported_package_names'][0]['display_text'] === '/Game/Provider',
    'zen_imported_package_names_parsed'
);
$check($parsed['exports_data_bytes'] === 4, 'zen_header_boundary_preserves_exports_payload');

$badStoreRejected = false;
try {
    $badStore = $storeEntry;
    $badStore['imported_package_ids'] = [];
    Uedb5ZenPackageReader::parse($packageBytes, $packageId, $badStore);
} catch (RuntimeException $exception) {
    $badStoreRejected = str_contains($exception->getMessage(), 'ImportedPackageIndex');
}
$check($badStoreRejected, 'zen_package_import_requires_authoritative_store_entry');

$badBundleRejected = false;
try {
    $bad = substr_replace($packageBytes, pack('V', $dependencyHeadersOffset - 8), 40, 4);
    Uedb5ZenPackageReader::parse($bad, $packageId, $storeEntry);
} catch (RuntimeException $exception) {
    $badBundleRejected = str_contains($exception->getMessage(), 'ExportBundleEntries count');
}
$check($badBundleRejected, 'zen_export_bundle_count_mismatch_rejected');

$badDependencyRejected = false;
try {
    $bad = substr_replace($packageBytes, pack('V', 2), $dependencyHeadersOffset + 4, 4);
    Uedb5ZenPackageReader::parse($bad, $packageId, $storeEntry);
} catch (RuntimeException $exception) {
    $badDependencyRejected = str_contains($exception->getMessage(), 'dependency bundle range');
}
$check($badDependencyRejected, 'zen_dependency_bundle_out_of_range_rejected');

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
