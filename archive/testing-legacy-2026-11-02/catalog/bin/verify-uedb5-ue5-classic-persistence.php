<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once dirname($root) . '/UE5/UnrealPackageReader.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataStagingReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ClassicSnapshotBuilder;

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
$buildFixture = static function (bool $unversioned) use ($u16, $u32, $i32, $i64, $fstring, $fname, $nameEntry): string {
    $names = ['CoreUObject', 'Class', 'MyObject', 'MyExport', '/Game/SoftPkg'];
    $nameBytes = '';
    foreach ($names as $name) $nameBytes .= $nameEntry($name);

    $importBytes = $fname(0) . $fname(1) . $i32(0) . $fname(2) . $fname(2) . $i32(1);
    $exportBytes = $i32(0) . $i32(0) . $i32(0) . $i32(0) . $fname(3)
        . $u32(1) . $i64(123) . $i64(456)
        . $i32(1) . $i32(0) . $i32(1)
        . $i32(1) . $u32(0x20) . $i32(1) . $i32(1) . $i32(1)
        . $i32(7) . $i32(1) . $i32(2) . $i32(3) . $i32(4)
        . $i64(11) . $i64(22);
    $softReferenceBytes = $fname(4);

    $engineVersion = static function () use ($u16, $u32, $fstring): string {
        return $u16(5) . $u16(8) . $u16(3) . $u32(0) . $fstring('UE5');
    };

    $buildSummary = static function (
        int $totalHeaderSize, int $nameOffset, int $importOffset, int $exportOffset, int $softOffset
    ) use ($u32, $i32, $i64, $fstring, $engineVersion, $unversioned): string {
        $ue4 = $unversioned ? 0 : 522;
        $ue5 = $unversioned ? 0 : 1018;
        $licensee = 0;
        $out = $u32(0x9E2A83C1) . $i32(-9) . $i32(864) . $i32($ue4) . $i32($ue5) . $i32($licensee);
        $out .= str_repeat("\0", 20) . $i32($totalHeaderSize) . $i32(0);
        $out .= $fstring('/Game/TestPkg') . $u32(0x80000000);
        $out .= $i32(5) . $i32($nameOffset);
        $out .= $i32(0) . $i32(0); // SoftObjectPaths
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
        $out .= $u32(0) . $i32(0); // CompressionFlags + chunks
        $out .= $u32(0) . $i32(0); // PackageSource + AdditionalPackagesToCook
        $out .= $i32(0) . $i64(0) . $i32(0); // AssetRegistry, BulkDataStart, WorldTileInfo
        $out .= $i32(0); // ChunkIDs
        $out .= $i32(0) . $i32(0); // Preload dependency table
        $out .= $i32(5) . $i64(-1) . $i32(-1); // NamesReferenced, PayloadToc, DataResource
        return $out;
    };
    $summary0 = $buildSummary(0, 0, 0, 0, 0);
    $nameOffset = strlen($summary0);
    $importOffset = $nameOffset + strlen($nameBytes);
    $exportOffset = $importOffset + strlen($importBytes);
    $softOffset = $exportOffset + strlen($exportBytes);
    $totalHeaderSize = $softOffset + strlen($softReferenceBytes);
    $summary = $buildSummary($totalHeaderSize, $nameOffset, $importOffset, $exportOffset, $softOffset);
    if (strlen($summary) !== strlen($summary0)) throw new RuntimeException('Synthetic UE5 summary size changed.');
    return $summary . $nameBytes . $importBytes . $exportBytes . $softReferenceBytes;
};

$checks = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-uedb5-ue5-classic-' . bin2hex(random_bytes(4));
if (!mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Could not create temp contract directory.');

$versionedPath = $dir . DIRECTORY_SEPARATOR . 'versioned.uasset';
$unversionedPath = $dir . DIRECTORY_SEPARATOR . 'unversioned.uasset';
$uedb5Path = $dir . DIRECTORY_SEPARATOR . '900001.uedb5';
file_put_contents($versionedPath, $buildFixture(false));
file_put_contents($unversionedPath, $buildFixture(true));
try {
    $reader = new UnrealPackageReader5($versionedPath);
    $snapshot = Uedb5Ue5ClassicSnapshotBuilder::build($reader, [
        'id' => 900001,
        'game_id' => 8,
        'package_name' => 'TestPkg',
        'original_name' => 'TestPkg.uasset',
    ]);
    Uedb5MetadataContainer::buildToFile($snapshot, $uedb5Path, 1);
    $staged = new Uedb5MetadataStagingReader($uedb5Path, 900001);
    $manifest = $staged->manifest();
    $summary = $staged->page('summary', 0, 1)[0] ?? [];
    $import = $staged->page('imports', 0, 1)[0] ?? [];
    $export = $staged->page('exports', 0, 1)[0] ?? [];
    $soft = $staged->page('soft_package_references', 0, 1)[0] ?? [];

    $check('classic_source_policy_is_explicit',
        ($manifest['package_family'] ?? '') === Uedb5Ue5ClassicSnapshotBuilder::PACKAGE_FAMILY
        && ($manifest['source_policy'] ?? '') === Uedb5Ue5ClassicSnapshotBuilder::SOURCE_POLICY,
        'UE5 classic UEDB5 files must identify both package representation and audited source policy.');
    $check('source_specific_section_schemas_survive',
        ($manifest['section_schemas']['imports'] ?? '') === 'ue5.classic.object-import.v1'
        && ($manifest['section_schemas']['exports'] ?? '') === 'ue5.classic.object-export.v1',
        'Classic UE5 import/export rows must carry explicit source-shaped schema IDs.');
    $check('serialized_version_and_tag_identity_survive',
        ($summary['serialized_file_version']['ue4'] ?? null) === 522
        && ($summary['serialized_file_version']['ue5'] ?? null) === 1018
        && ($summary['serialized_tag'] ?? '') === '9E2A83C1'
        && ($summary['byte_swapping'] ?? true) === false,
        'Serialized package version components and byte-order/tag identity must survive UEDB5.');
    $check('import_fname_graph_is_lossless',
        ($import['class_package']['name_index'] ?? null) === 0
        && ($import['class_name']['name_index'] ?? null) === 1
        && ($import['object_name']['name_index'] ?? null) === 2
        && ($import['serialized_package_name']['name_index'] ?? null) === 2
        && ($import['outer_index'] ?? null) === 0,
        'Raw FName index/number identity and signed OuterIndex must be persisted, not reconstructed from paths.');
    $check('serialized_and_effective_package_name_are_distinct',
        ($import['serialized_package_name_present'] ?? false) === true
        && ($import['serialized_package_name']['text'] ?? '') === 'MyObject'
        && ($import['effective_package_name']['is_none'] ?? false) === true
        && array_key_exists('name_index', (array)($import['effective_package_name'] ?? []))
        && $import['effective_package_name']['name_index'] === null,
        'Filtered editor-only PackageName placeholder and load-time None fixup must both survive.');
    $check('optional_import_survives', ($import['b_import_optional_present'] ?? false) === true
        && ($import['b_import_optional'] ?? false) === true,
        'bImportOptional must remain explicit source metadata.');
    $check('export_graph_flags_and_public_hash_survive',
        ($export['template_index_present'] ?? false) === true
        && ($export['package_guid_present'] ?? true) === false
        && ($export['b_generate_public_hash_present'] ?? false) === true
        && ($export['serial_size_width_bits'] ?? null) === 64
        && ($export['class_index'] ?? null) === 0
        && ($export['super_index'] ?? null) === 0
        && ($export['template_index'] ?? null) === 0
        && ($export['outer_index'] ?? null) === 0
        && ($export['object_flags'] ?? '') === '0000000000000001'
        && ($export['object_flags_serialized_width_bits'] ?? null) === 32
        && ($export['package_flags'] ?? '') === '00000020'
        && ($export['b_generate_public_hash'] ?? false) === true,
        'UE5 classic export graph, uint32 RF_Load serialization semantics, package flags, and public-hash request must survive.');
    $check('export_ranges_and_script_offsets_survive',
        ($export['preload_dependency_range_present'] ?? false) === true
        && ($export['script_serialization_offsets_present'] ?? false) === true
        && ($export['b_is_inherited_instance_present'] ?? false) === true
        && ($export['preload_dependency_range']['first_export_dependency'] ?? null) === 7
        && ($export['preload_dependency_range']['create_before_create'] ?? null) === 4
        && ($export['script_serialization_start_offset'] ?? null) === 11
        && ($export['script_serialization_end_offset'] ?? null) === 22
        && ($export['b_is_inherited_instance'] ?? false) === true,
        'Preload ranges, inherited-instance state, and script serialization offsets must remain source-shaped.');
    $check('soft_reference_keeps_raw_fname_identity',
        ($soft['package_name']['name_index'] ?? null) === 4
        && ($soft['package_name']['text'] ?? '') === '/Game/SoftPkg'
        && ($soft['dependency_class'] ?? '') === 'soft_package_reference',
        'Soft package references must stay separate from hard imports and retain raw FName identity.');

    $unversioned = new UnrealPackageReader5($unversionedPath);
    $unversionedSnapshot = Uedb5Ue5ClassicSnapshotBuilder::build($unversioned, [
        'id' => 900002,
        'game_id' => 8,
        'package_name' => 'TestPkg',
        'original_name' => 'TestPkg-Unversioned.uasset',
    ]);
    $unversionedSummary = $unversionedSnapshot['sections']['summary'][0] ?? [];
    $check('unversioned_keeps_serialized_zero_versions',
        ($unversionedSummary['unversioned'] ?? false) === true
        && ($unversionedSummary['serialized_file_version']['ue4'] ?? null) === 0
        && ($unversionedSummary['serialized_file_version']['ue5'] ?? null) === 0
        && ($unversionedSummary['effective_file_version']['ue4'] ?? null) === 522
        && ($unversionedSummary['effective_file_version']['ue5'] ?? null) === 1018,
        'UEDB5 must distinguish serialized zero versions from parser-profile assumptions.');
} finally {
    foreach ([$uedb5Path, $versionedPath, $unversionedPath] as $path) {
        @unlink($path);
    }
    @rmdir($dir);
}

$failed = array_values(array_filter($checks, static fn(array $row): bool => !$row['ok']));
echo json_encode(['ok' => $failed === [], 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failed === [] ? 0 : 2);
