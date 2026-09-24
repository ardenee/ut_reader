#!/usr/bin/env php
<?php
/**
 * Runtime/static contract for UE1/UE2 dependency matching against ULinkerLoad::VerifyImport().
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/src/Infrastructure/Persistence/PdoLegacyVerifyImportMatcher.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoLegacyVerifyImportMatcher;

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

$baseConsumer = [
    [
        'import_index' => 0,
        'class_package' => 'Core',
        'class_name' => 'Package',
        'object_name' => 'TestPkg',
        'outer_index' => 0,
    ],
    [
        'import_index' => 1,
        'class_package' => 'Engine',
        'class_name' => 'Texture',
        'object_name' => 'Parent',
        'outer_index' => -1,
    ],
    [
        'import_index' => 2,
        'class_package' => 'Engine',
        'class_name' => 'Texture',
        'object_name' => 'Child',
        'outer_index' => -2,
    ],
];

$providerExports = [
    [
        'export_index' => 0,
        'class_index' => -2,
        'object_name' => 'Parent',
        'outer_index' => 0,
        'object_flags' => 4,
    ],
    [
        'export_index' => 1,
        'class_index' => -2,
        'object_name' => 'Child',
        'outer_index' => 1,
        'object_flags' => 4,
    ],
];

$matches = PdoLegacyVerifyImportMatcher::match(
    $baseConsumer,
    $providerImports,
    $providerExports,
    'TestPkg'
);
$check(
    'exact_identity_and_parent_index',
    ($matches[1] ?? null) === 0 && ($matches[2] ?? null) === 1,
    'ObjectName/ClassName/ClassPackage must match and nested Source.PackageIndex must equal Parent.SourceIndex + 1.'
);

$outerZeroExports = $providerExports;
$outerZeroExports[] = [
    'export_index' => 2,
    'class_index' => -2,
    'object_name' => 'Child',
    'outer_index' => 0,
    'object_flags' => 4,
];
$matches = PdoLegacyVerifyImportMatcher::match(
    $baseConsumer,
    $providerImports,
    $outerZeroExports,
    'TestPkg'
);
$check(
    'source_outer_zero_fallback',
    ($matches[2] ?? null) === 2,
    'VerifyImport accepts Source.PackageIndex == 0 even when the parent Import resolved to an Export.'
);

$classMismatch = $baseConsumer;
$classMismatch[2]['class_package'] = 'Core';
$matches = PdoLegacyVerifyImportMatcher::match(
    $classMismatch,
    $providerImports,
    $providerExports,
    'TestPkg'
);
$check(
    'class_package_is_identity',
    !isset($matches[2]),
    'A matching object path/name is insufficient when ClassPackage differs.'
);

$wrongOuterExports = $providerExports;
$wrongOuterExports[1]['outer_index'] = 99;
$matches = PdoLegacyVerifyImportMatcher::match(
    $baseConsumer,
    $providerImports,
    $wrongOuterExports,
    'TestPkg'
);
$check(
    'wrong_parent_rejected',
    !isset($matches[2]),
    'A nonzero Source.PackageIndex that is not Parent.SourceIndex + 1 must be rejected.'
);

$meshConsumer = [
    [
        'import_index' => 0,
        'class_package' => 'Core',
        'class_name' => 'Package',
        'object_name' => 'TestPkg',
        'outer_index' => 0,
    ],
    [
        'import_index' => 1,
        'class_package' => 'Engine',
        'class_name' => 'Mesh',
        'object_name' => 'LegacyModel',
        'outer_index' => -1,
    ],
];
$meshExports = [
    [
        'export_index' => 0,
        'class_index' => -3,
        'object_name' => 'LegacyModel',
        'outer_index' => 0,
        'object_flags' => 4,
    ],
];
$matches = PdoLegacyVerifyImportMatcher::match(
    $meshConsumer,
    $providerImports,
    $meshExports,
    'TestPkg'
);
$check(
    'mesh_retries_as_lodmesh',
    ($matches[1] ?? null) === 0,
    'A failed Mesh identity lookup must retry the same ObjectName/ClassPackage as LodMesh.'
);

$duplicateExports = $providerExports;
$duplicateExports[] = [
    'export_index' => 3,
    'class_index' => -2,
    'object_name' => 'Parent',
    'outer_index' => 0,
    'object_flags' => 4,
];
$matches = PdoLegacyVerifyImportMatcher::match(
    $baseConsumer,
    $providerImports,
    $duplicateExports,
    'TestPkg'
);
$check(
    'export_hash_visit_order',
    ($matches[1] ?? null) === 3,
    'ExportHash prepending means the highest matching Export index is visited first.'
);

$matcherSource = (string)file_get_contents(
    $root . '/src/Infrastructure/Persistence/PdoLegacyVerifyImportMatcher.php'
);
$check(
    'native_transient_limitation_is_explicit',
    str_contains($matcherSource, 'native/public/transient StaticFindObject fallback')
        && str_contains($matcherSource, 'not reproducible from package data alone'),
    'Runtime native/transient objects are not silently replaced with a package-data guess.'
);
$privateExports = $providerExports;
$privateExports[0]['object_flags'] = 0;
$variants = PdoLegacyVerifyImportMatcher::matchVariants(
    $baseConsumer,
    $providerImports,
    $privateExports,
    'TestPkg'
);
$check(
    'private_export_revision_difference',
    !isset($variants['standard'][1])
        && ($variants['unreal2'][1] ?? null) === 0
        && ($variants['unreal2_only'][1] ?? null) === 0,
    'UE2.5/UT2004 reject the private Export while the supplied Unreal II revision accepts the same exact VerifyImport match.'
);
$check(
    'private_export_revision_difference_is_explicit',
    str_contains($matcherSource, 'standard')
        && str_contains($matcherSource, 'unreal2_only')
        && str_contains($matcherSource, 'RF_PUBLIC'),
    'The matcher must retain both reviewed private-Export behaviours so Unreal II-only candidates remain queryable.'
);

$ok = !in_array(false, array_column($checks, 'ok'), true);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
