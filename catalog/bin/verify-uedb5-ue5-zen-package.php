<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreContainerHeaderReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenIoStoreSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataStagingReader;

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

$u40be = static function (int $value): string {
    $bytes = '';
    for ($shift = 32; $shift >= 0; $shift -= 8) { $bytes .= chr(($value >> $shift) & 0xff); }
    return $bytes;
};
$u40le = static function (int $value): string {
    $bytes = '';
    for ($shift = 0; $shift <= 32; $shift += 8) { $bytes .= chr(($value >> $shift) & 0xff); }
    return $bytes;
};
$u24le = static fn(int $value): string =>
    chr($value & 0xff) . chr(($value >> 8) & 0xff) . chr(($value >> 16) & 0xff);
$arrayU64 = static function (array $ids) use ($u64le): string {
    $out = pack('V', count($ids));
    foreach ($ids as $id) { $out .= $u64le((string)$id); }
    return $out;
};
$arrayBytes = static fn(string $bytes): string => pack('V', strlen($bytes)) . $bytes;
$containerId = '1111222233334444';
$storeEntries = pack('V2', 1, 16) . pack('V2', 0, 0) . $u64le($providerId);
$containerHeader = pack('V2', Uedb5IoStoreContainerHeaderReader::SIGNATURE, 5)
    . $u64le($containerId)
    . $arrayU64([$packageId])
    . $arrayBytes($storeEntries)
    . pack('V', 0)
    . $arrayBytes('')
    . pack('V', 0)
    . pack('V', 0)
    . pack('V', 0)
    . pack('V4', 0, 0, 0, 0);
$chunkId = static function (string $id, int $type) use ($u64le): string {
    return $u64le($id) . pack('n', 0) . "\0" . chr($type);
};
$logical = $containerHeader . $packageBytes;
$blockSize = 65536;
$chunks = [
    ['id' => $chunkId($containerId, Uedb5IoStoreTocReader::CHUNK_TYPE_CONTAINER_HEADER), 'offset' => 0, 'length' => strlen($containerHeader)],
    ['id' => $chunkId($packageId, Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA), 'offset' => strlen($containerHeader), 'length' => strlen($packageBytes)],
];
$tocHeader = Uedb5IoStoreTocReader::TOC_MAGIC
    . chr(8) . "\0" . pack('v', 0)
    . pack('V', Uedb5IoStoreTocReader::TOC_HEADER_SIZE)
    . pack('V', count($chunks))
    . pack('V', 1)
    . pack('V', 12)
    . pack('V', 0)
    . pack('V', 32)
    . pack('V', $blockSize)
    . pack('V', 0)
    . pack('V', 1)
    . $u64le($containerId)
    . str_repeat("\0", 16)
    . chr(0) . "\0" . pack('v', 0)
    . pack('V', 0)
    . $u64le('FFFFFFFFFFFFFFFF')
    . pack('V2', 0, 0)
    . str_repeat("\0", 40);
if (strlen($tocHeader) !== Uedb5IoStoreTocReader::TOC_HEADER_SIZE) {
    throw new RuntimeException('Zen snapshot fixture TOC header size mismatch.');
}
$utoc = $tocHeader;
foreach ($chunks as $chunk) { $utoc .= $chunk['id']; }
foreach ($chunks as $chunk) { $utoc .= $u40be($chunk['offset']) . $u40be($chunk['length']); }
$utoc .= $u40le(0)
    . $u24le(strlen($logical))
    . $u24le(strlen($logical))
    . chr(0);
foreach ($chunks as $_) { $utoc .= str_repeat("\0", 20) . chr(0) . str_repeat("\0", 3); }

$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb_zen_snapshot_' . bin2hex(random_bytes(6));
if (!mkdir($temp, 0775, true) && !is_dir($temp)) {
    throw new RuntimeException('Could not create Zen snapshot verification directory.');
}
$base = $temp . DIRECTORY_SEPARATOR . 'fixture';
file_put_contents($base . '.utoc', $utoc);
file_put_contents($base . '.ucas', $logical);
$uedb5Path = $temp . DIRECTORY_SEPARATOR . '7.uedb5';
try {
    $toc = new Uedb5IoStoreTocReader($base . '.utoc');
    $snapshot = Uedb5Ue5ZenIoStoreSnapshotBuilder::build($toc, $packageId, [
        'id' => 7,
        'game_id' => 7,
        'package_name' => '',
        'original_name' => 'fixture.utoc',
    ]);
    $check($snapshot['package_family'] === 'zen-iostore', 'zen_snapshot_package_family');
    $check($snapshot['file']['package_name'] === '/Game/TestPackage', 'zen_snapshot_package_name');
    $check($snapshot['sections']['container_provenance'][0]['utoc_sha256'] === hash('sha256', $utoc), 'zen_snapshot_utoc_provenance_hash');
    $check($snapshot['sections']['package_store'][0]['imported_package_ids'] === [$providerId], 'zen_snapshot_store_entry_preserved');
    $check($snapshot['sections']['imports'][0]['dependency_class'] === 'hard', 'zen_snapshot_hard_import_classified');
    $check($snapshot['sections']['cell_imports'][0]['dependency_class'] === 'script', 'zen_snapshot_script_cell_import_classified');
    $check($snapshot['sections']['dependency_bundle_entries'][0]['dependency_class'] === 'load_order', 'zen_snapshot_load_order_classified');

    Uedb5MetadataContainer::buildToFile($snapshot, $uedb5Path, 2);
    $staged = new Uedb5MetadataStagingReader($uedb5Path, 7);
    $check($staged->manifest()['package_family'] === 'zen-iostore', 'zen_snapshot_uedb5_manifest_family');
    $check($staged->count('imports') === 1, 'zen_snapshot_uedb5_import_count');
    $roundTripImports = $staged->page('imports', 0, 10);
    $check(
        $roundTripImports[0]['provider_package_id'] === $providerId
        && $roundTripImports[0]['provider_public_export_hash'] === $importedHash,
        'zen_snapshot_uedb5_package_import_round_trip'
    );
    $roundTripProvenance = $staged->page('container_provenance', 0, 1);
    $check(
        $roundTripProvenance[0]['utoc_sha256'] === hash('sha256', $utoc),
        'zen_snapshot_uedb5_provenance_round_trip'
    );
} finally {
    @unlink($uedb5Path);
    @unlink($base . '.utoc');
    @unlink($base . '.ucas');
    @rmdir($temp);
}

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
