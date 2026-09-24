#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/src/Infrastructure/Persistence/PdoLegacyVerifyImportProjectionResolver.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoLegacyVerifyImportProjectionResolver;

$checks = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};

$providerImports = [
    ['import_index' => 0, 'object_name' => 'Engine', 'outer_index' => 0],
    ['import_index' => 1, 'object_name' => 'Texture', 'outer_index' => -1],
    ['import_index' => 2, 'object_name' => 'LodMesh', 'outer_index' => -1],
    ['import_index' => 3, 'object_name' => 'OtherClass', 'outer_index' => -1],
];

$consumer = [
    ['import_index' => 0, 'class_package' => 'Core', 'class_name' => 'Package', 'object_name' => 'TestPkg', 'outer_index' => 0],
    ['import_index' => 1, 'class_package' => 'Engine', 'class_name' => 'Texture', 'object_name' => 'Parent', 'outer_index' => -1],
    ['import_index' => 2, 'class_package' => 'Engine', 'class_name' => 'Texture', 'object_name' => 'Child', 'outer_index' => -2],
];

$exports = [
    ['export_index' => 0, 'class_index' => -2, 'object_name' => 'Parent', 'outer_index' => 0, 'object_flags' => 4],
    ['export_index' => 1, 'class_index' => -2, 'object_name' => 'Child', 'outer_index' => 1, 'object_flags' => 4],
];

$variants = PdoLegacyVerifyImportProjectionResolver::resolveInMemoryVariants(
    $consumer,
    $providerImports,
    $exports,
    'TestPkg'
);
$check(
    'exact_identity_and_parent_index',
    ($variants['standard'][1] ?? null) === 0 && ($variants['standard'][2] ?? null) === 1,
    'ObjectName/ClassName/ClassPackage and immediate PackageIndex parent must reproduce VerifyImport.'
);

$outerZero = $exports;
$outerZero[] = ['export_index' => 2, 'class_index' => -2, 'object_name' => 'Child', 'outer_index' => 0, 'object_flags' => 4];
$variants = PdoLegacyVerifyImportProjectionResolver::resolveInMemoryVariants(
    $consumer,
    $providerImports,
    $outerZero,
    'TestPkg'
);
$check(
    'source_outer_zero_fallback',
    ($variants['standard'][2] ?? null) === 2,
    'VerifyImport accepts Source.PackageIndex == 0 even after the parent Import resolved.'
);

$classMismatch = $consumer;
$classMismatch[2]['class_package'] = 'Core';
$variants = PdoLegacyVerifyImportProjectionResolver::resolveInMemoryVariants(
    $classMismatch,
    $providerImports,
    $exports,
    'TestPkg'
);
$check(
    'class_package_is_identity',
    !isset($variants['standard'][2]),
    'Object path/name alone must not resolve an Export with the wrong ClassPackage.'
);

$wrongOuter = $exports;
$wrongOuter[1]['outer_index'] = 99;
$variants = PdoLegacyVerifyImportProjectionResolver::resolveInMemoryVariants(
    $consumer,
    $providerImports,
    $wrongOuter,
    'TestPkg'
);
$check(
    'wrong_parent_rejected',
    !isset($variants['standard'][2]),
    'A nonzero Source.PackageIndex other than Parent.SourceIndex + 1 must be rejected.'
);

$meshConsumer = [
    ['import_index' => 0, 'class_package' => 'Core', 'class_name' => 'Package', 'object_name' => 'TestPkg', 'outer_index' => 0],
    ['import_index' => 1, 'class_package' => 'Engine', 'class_name' => 'Mesh', 'object_name' => 'LegacyModel', 'outer_index' => -1],
];
$meshExports = [
    ['export_index' => 0, 'class_index' => -3, 'object_name' => 'LegacyModel', 'outer_index' => 0, 'object_flags' => 4],
];
$variants = PdoLegacyVerifyImportProjectionResolver::resolveInMemoryVariants(
    $meshConsumer,
    $providerImports,
    $meshExports,
    'TestPkg'
);
$check(
    'mesh_retries_as_lodmesh',
    ($variants['standard'][1] ?? null) === 0,
    'A missing Mesh identity retries the same ObjectName/ClassPackage as LodMesh.'
);

$duplicates = $exports;
$duplicates[] = ['export_index' => 3, 'class_index' => -2, 'object_name' => 'Parent', 'outer_index' => 0, 'object_flags' => 4];
$variants = PdoLegacyVerifyImportProjectionResolver::resolveInMemoryVariants(
    $consumer,
    $providerImports,
    $duplicates,
    'TestPkg'
);
$check(
    'export_hash_visit_order',
    ($variants['standard'][1] ?? null) === 3,
    'ExportHash prepending makes the highest matching Export index the first candidate.'
);

$private = $exports;
$private[0]['object_flags'] = 0;
$variants = PdoLegacyVerifyImportProjectionResolver::resolveInMemoryVariants(
    $consumer,
    $providerImports,
    $private,
    'TestPkg'
);
$check(
    'private_export_revision_difference',
    !isset($variants['standard'][1])
        && ($variants['unreal2'][1] ?? null) === 0
        && ($variants['unreal2_only'][1] ?? null) === 0,
    'UE2.5/UT2004 reject the private Export while the supplied Unreal II revision accepts it.'
);

$meshPrivateExact = [
    ['export_index' => 0, 'class_index' => -3, 'object_name' => 'LegacyModel', 'outer_index' => 0, 'object_flags' => 4],
    ['export_index' => 1, 'class_index' => -2, 'object_name' => 'LegacyModel', 'outer_index' => 0, 'object_flags' => 0],
];
$variants = PdoLegacyVerifyImportProjectionResolver::resolveInMemoryVariants(
    $meshConsumer,
    $providerImports,
    $meshPrivateExact,
    'TestPkg'
);
$check(
    'private_exact_match_stops_before_lodmesh',
    !isset($variants['standard'][1]) && ($variants['unreal2'][1] ?? null) === 1,
    'UE2.5/UT2004 fail on an exact private Mesh match and do not continue to the LodMesh retry.'
);

$source = (string)file_get_contents($root . '/src/Infrastructure/Persistence/PdoLegacyVerifyImportProjectionResolver.php');
$writer = (string)file_get_contents($root . '/src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php');
$check(
    'runtime_uses_indexed_projection',
    str_contains($source, 'ue_legacy_export_identity_lookup')
        && !str_contains($source, 'BlockedCompressedMetadataReader'),
    'Normal UE1/UE2 dependency resolution must use the SQL identity projection rather than reopen provider metadata files.'
);
$check(
    'publication_projects_exact_identity',
    str_contains($writer, 'ue_legacy_export_identity_lookup')
        && str_contains($writer, 'legacyExportClassIdentity')
        && str_contains($writer, 'CatalogUnrealIdentityHash::verifyImportBinary'),
    'Metadata publication must derive the indexed identity from serialized Export/Class/PackageIndex data.'
);

$ok = !in_array(false, array_column($checks, 'ok'), true);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
