<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/GameProfiles.php';
require_once dirname($root) . '/UE5/UnrealPackageReader.php';

$u16 = static fn(int $v): string => pack('v', $v & 0xffff);
$u32 = static fn(int $v): string => pack('V', $v & 0xffffffff);
$i32 = $u32;
$i64 = static function (int $v): string {
    if ($v < 0) return pack('V2', $v & 0xffffffff, 0xffffffff);
    return pack('V2', $v & 0xffffffff, ($v >> 32) & 0xffffffff);
};
$fstring = static function (string $v) use ($i32): string {
    return $v === '' ? $i32(0) : $i32(strlen($v) + 1) . $v . "\0";
};
$fname = static fn(int $index, int $number = 0): string => pack('V2', $index, $number);
$nameEntry = static function (string $name) use ($i32, $u16): string {
    return $i32(strlen($name) + 1) . $name . "\0" . $u16(0) . $u16(0);
};
$names = ['CoreUObject', 'Class', 'MyObject', 'MyExport', '/Game/SoftPkg'];
$nameBytes = '';
foreach ($names as $name) $nameBytes .= $nameEntry($name);

$importBytes = $fname(0) . $fname(1) . $i32(0) . $fname(2) . $fname(2) . $i32(1);
$exportBytes = $i32(0) . $i32(0) . $i32(0) . $i32(0) . $fname(3)
    . $u32(1) . $i64(0) . $i64(0)
    . $i32(0) . $i32(0) . $i32(0)
    . $i32(1) . $u32(0) . $i32(0) . $i32(1) . $i32(1)
    . $i32(0) . $i32(0) . $i32(0) . $i32(0) . $i32(0)
    . $i64(11) . $i64(22);
$softReferenceBytes = $fname(4);

$engineVersion = static function () use ($u16, $u32, $fstring): string {
    return $u16(5) . $u16(8) . $u16(3) . $u32(0) . $fstring('UE5');
};

$buildSummary = static function (
    int $totalHeaderSize, int $nameOffset, int $importOffset, int $exportOffset, int $softOffset
) use ($u32, $i32, $i64, $fstring, $engineVersion): string {
    $out = $u32(0x9E2A83C1) . $i32(-9) . $i32(864) . $i32(522) . $i32(1018) . $i32(0);
    $out .= str_repeat("\0", 20) . $i32($totalHeaderSize) . $i32(0);
    $out .= $fstring('/Game/TestPkg') . $u32(0x80000000);
    $out .= $i32(5) . $i32($nameOffset);
    $out .= $i32(0) . $i32(0); // SoftObjectPaths
    // LocalizationId omitted because PKG_FilterEditorOnly is set.
    $out .= $i32(0) . $i32(0); // Gatherable text
    $out .= $i32(1) . $i32($exportOffset) . $i32(1) . $i32($importOffset);
    $out .= $i32(0) . $i32(0) . $i32(0) . $i32(0); // Verse cells
    $out .= $i32(0); // MetaDataOffset
    $out .= $i32(0); // DependsOffset
    $out .= $i32(1) . $i32($softOffset); // SoftPackageReferences
    $out .= $i32(0) . $i32(0); // SearchableNamesOffset, ThumbnailTableOffset
    $out .= $i32(0) . $i32(0); // ImportTypeHierarchies
    $out .= $i32(0); // GenerationCount
    $out .= $engineVersion() . $engineVersion();
    $out .= $u32(0) . $i32(0); // CompressionFlags + compressed chunk count
    $out .= $u32(0) . $i32(0); // PackageSource + AdditionalPackagesToCook count
    $out .= $i32(0) . $i64(0) . $i32(0); // AssetRegistry, BulkDataStart, WorldTileInfo
    $out .= $i32(0); // ChunkIDs count
    $out .= $i32(0) . $i32(0); // Preload deps
    $out .= $i32(5) . $i64(-1) . $i32(-1); // NamesReferenced, PayloadToc, DataResource
    return $out;
};

$buildPreHashFixture = static function () use ($u16, $u32, $i32, $i64, $fstring, $engineVersion): string {
    $name = 'PreHashName';
    $nameBytes = $i32(strlen($name) + 1) . $name . "\0";
    $build = static function (int $headerSize, int $nameOffset) use ($u32, $i32, $i64, $fstring, $engineVersion): string {
        $out = $u32(0x9E2A83C1) . $i32(-9) . $i32(864) . $i32(503) . $i32(1018) . $i32(0);
        $out .= str_repeat("\0", 20) . $i32($headerSize) . $i32(0);
        $out .= $fstring('/Game/PreHash') . $u32(0x80000000);
        $out .= $i32(1) . $i32($nameOffset);
        $out .= $i32(0) . $i32(0); // SoftObjectPaths
        $out .= $i32(0) . $i32(0); // Gatherable text
        $out .= $i32(0) . $i32(0) . $i32(0) . $i32(0); // Export/import tables
        $out .= $i32(0) . $i32(0) . $i32(0) . $i32(0); // Verse cells
        $out .= $i32(0) . $i32(0); // MetaDataOffset + DependsOffset
        $out .= $i32(0) . $i32(0); // SoftPackageReferences
        $out .= $i32(0); // ThumbnailTableOffset; no SearchableNames at UE4 503
        $out .= $i32(0) . $i32(0); // ImportTypeHierarchies
        $out .= $i32(0); // GenerationCount
        $out .= $engineVersion() . $engineVersion();
        $out .= $u32(0) . $i32(0) . $u32(0) . $i32(0); // Compression + source/additional packages
        $out .= $i32(0) . $i64(0) . $i32(0) . $i32(0); // AssetRegistry, bulk, world tile, chunks
        $out .= $i32(1) . $i64(-1) . $i32(-1); // NamesReferenced; no preload fields at UE4 503
        return $out;
    };
    $summary0 = $build(0, 0);
    $summary = $build(strlen($summary0) + strlen($nameBytes), strlen($summary0));
    return $summary . $nameBytes;
};
$buildNonePackageNameFixture = static function () use ($u32, $i32, $i64, $fstring, $fname, $nameEntry, $engineVersion): string {
    $names = ['None', 'CoreUObject', 'Class', 'MyObject'];
    $nameBytes = '';
    foreach ($names as $name) $nameBytes .= $nameEntry($name);
    $importBytes = $fname(1) . $fname(2) . $i32(0) . $fname(3) . $fname(0) . $i32(0);
    $build = static function (int $headerSize, int $nameOffset, int $importOffset) use ($u32, $i32, $i64, $fstring, $engineVersion): string {
        $out = $u32(0x9E2A83C1) . $i32(-9) . $i32(864) . $i32(522) . $i32(1018) . $i32(0);
        $out .= str_repeat("\0", 20) . $i32($headerSize) . $i32(0);
        $out .= $fstring('/Game/NonePackageName') . $u32(0);
        $out .= $i32(4) . $i32($nameOffset);
        $out .= $i32(0) . $i32(0); // SoftObjectPaths
        $out .= $fstring(''); // LocalizationId
        $out .= $i32(0) . $i32(0); // Gatherable text
        $out .= $i32(0) . $i32(0) . $i32(1) . $i32($importOffset); // Export/import tables
        $out .= $i32(0) . $i32(0) . $i32(0) . $i32(0); // Verse cells
        $out .= $i32(0) . $i32(0); // MetaDataOffset + DependsOffset
        $out .= $i32(0) . $i32(0); // SoftPackageReferences
        $out .= $i32(0) . $i32(0); // SearchableNames + ThumbnailTable
        $out .= $i32(0) . $i32(0); // ImportTypeHierarchies
        $out .= str_repeat("\0", 16); // PersistentGuid
        $out .= $i32(0); // GenerationCount
        $out .= $engineVersion() . $engineVersion();
        $out .= $u32(0) . $i32(0) . $u32(0) . $i32(0); // Compression + source/additional packages
        $out .= $i32(0) . $i64(0) . $i32(0) . $i32(0); // AssetRegistry, bulk, world tile, chunks
        $out .= $i32(0) . $i32(0); // Preload deps
        $out .= $i32(4) . $i64(-1) . $i32(-1); // NamesReferenced, PayloadToc, DataResource
        return $out;
    };
    $summary0 = $build(0, 0, 0);
    $nameOffset = strlen($summary0);
    $importOffset = $nameOffset + strlen($nameBytes);
    $summary = $build($importOffset + strlen($importBytes), $nameOffset, $importOffset);
    if (strlen($summary) !== strlen($summary0)) throw new RuntimeException('None PackageName summary size changed.');
    return $summary . $nameBytes . $importBytes;
};
$summary0 = $buildSummary(0, 0, 0, 0, 0);
$nameOffset = strlen($summary0);
$importOffset = $nameOffset + strlen($nameBytes);
$exportOffset = $importOffset + strlen($importBytes);
$softOffset = $exportOffset + strlen($exportBytes);
$totalHeaderSize = $softOffset + strlen($softReferenceBytes);
$summary = $buildSummary($totalHeaderSize, $nameOffset, $importOffset, $exportOffset, $softOffset);
if (strlen($summary) !== strlen($summary0)) throw new RuntimeException('Synthetic summary size changed.');
$fixture = $summary . $nameBytes . $importBytes . $exportBytes . $softReferenceBytes;
$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-ue5-583-reader-contract.uasset';
$preHashPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-ue5-pre-name-hash-contract.uasset';
$nonePackageNamePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-ue5-none-package-name-contract.uasset';
file_put_contents($path, $fixture);
file_put_contents($preHashPath, $buildPreHashFixture());
file_put_contents($nonePackageNamePath, $buildNonePackageNameFixture());

$checks = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};
try {
    $summaryRoute = gp_read_legacy_summary($path);
    $reader = new UnrealPackageReader5($path);
    $header = $reader->getHeader();
    $imports = $reader->getImports();
    $exports = $reader->getExports();
    $soft = $reader->getStringAssetReferences();

    $check('header_routes_to_ue5', ($summaryRoute['engine_hint'] ?? '') === 'UE5'
        && (int)($summaryRoute['ue5_version'] ?? 0) === 1018, 'Legacy -9 summary must route to UE5.');
    $check('reader_has_no_fatal_issues', $reader->validatePackage() === [], implode('; ', $reader->validatePackage()));
    $check('version_components_preserved', (int)($header['ue4Version'] ?? 0) === 522
        && (int)($header['ue5Version'] ?? 0) === 1018, 'FPackageFileVersion components must stay separate.');
    $check('saved_hash_and_summary_gates_align', strlen((string)($header['savedHash'] ?? '')) === 40
        && (int)($header['namesReferencedFromExportDataCount'] ?? 0) === 5, 'PACKAGE_SAVED_HASH and later summary fields must stay aligned.');
    $import = $imports[0] ?? [];
    $check('filter_editor_only_consumes_package_name', (string)($import['serializedPackageNameText'] ?? '') === 'MyObject'
        && (string)($import['packageNameText'] ?? '') === '', 'Serialized placeholder must be consumed then reset to None.');
    $check('optional_import_preserved', !empty($import['bImportOptional']), 'OPTIONAL_RESOURCES bImportOptional must be retained.');

    $export = $exports[0] ?? [];
    $check('removed_package_guid_does_not_shift_export', (string)($export['packageGuid'] ?? '') === ''
        && !empty($export['isInheritedInstance']), 'UE5 >= REMOVE_OBJECT_EXPORT_PACKAGE_GUID must not consume a GUID.');
    $check('generate_public_hash_preserved', !empty($export['bGeneratePublicHash']), 'bGeneratePublicHash must be retained.');
    $check('script_serialization_offsets_preserved', (int)($export['scriptSerializationStartOffset'] ?? 0) === 11
        && (int)($export['scriptSerializationEndOffset'] ?? 0) === 22, 'Versioned UE5 exports must retain script offsets.');
    $check('soft_package_reference_is_fname', (string)($soft[0]['path'] ?? '') === '/Game/SoftPkg', 'SoftPackageReferenceList is TArray<FName>.');

    $preHashReader = new UnrealPackageReader5($preHashPath);
    $preHashNames = $preHashReader->getNames();
    $check('name_hash_gate_uses_ue4_component', $preHashReader->validatePackage() === []
        && (string)($preHashNames[0]['name'] ?? '') === 'PreHashName'
        && ($preHashNames[0]['nonCaseHash'] ?? null) === null
        && ($preHashNames[0]['caseHash'] ?? null) === null,
        'VER_UE4_NAME_HASHES_SERIALIZED must be gated by the UE4 version component, not the UE5 version.');

    $nonePackageNameReader = new UnrealPackageReader5($nonePackageNamePath);
    $nonePackageNameImport = $nonePackageNameReader->getImports()[0] ?? [];
    $check('serialized_none_package_name_is_effective_none', $nonePackageNameReader->validatePackage() === []
        && (string)($nonePackageNameImport['serializedPackageNameText'] ?? '') === 'None'
        && (string)($nonePackageNameImport['packageNameText'] ?? 'x') === '',
        'Serialized NAME_None must remain raw evidence while effective PackageName behaves like FName::IsNone().');

    $resolved = \UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver::resolve(
        [], 'UE5', 'Reader not found', 'Reader class missing ', ['UE4', 'UE5']
    );
    $check('canonical_resolver_selects_ue5_reader', $resolved === 'UnrealPackageReader5', 'UE5 must not alias UnrealPackageReader4.');
} finally {
    @unlink($path);
    @unlink($preHashPath);
    @unlink($nonePackageNamePath);
}

$failed = array_values(array_filter($checks, static fn(array $row): bool => !$row['ok']));
echo json_encode(['ok' => $failed === [], 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failed === [] ? 0 : 2);
