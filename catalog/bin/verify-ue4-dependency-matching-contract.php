#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportProjectionResolver;

$failures = [];
$check = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) $failures[] = $name;
};

$scriptSnapshot = CatalogCompactIdentityEnricher::enrich([
    'file' => ['id'=>1,'game_id'=>7,'package_name'=>'/Game/Test','original_name'=>'Test.uasset'],
    'names' => [],
    'imports' => [[
        'import_index'=>0,'class_package'=>'/Script/CoreUObject','class_name'=>'Class','object_name'=>'Material',
        'outer_index'=>-2,'full_path'=>'/Script/Engine.Material','root_package'=>'/Script/Engine',
        'relative_object_path'=>'Material','is_common'=>0,
    ]],
    'exports' => [],
    'dependencies' => [],
    'paths' => ['imports'=>[0=>['full'=>'/Script/Engine.Material','root'=>'/Script/Engine','relative'=>'Material']],'exports'=>[]],
], 'UE4');
$check((int)$scriptSnapshot['imports'][0]['is_common'] === 1, 'ue4_script_package_is_common');

$consumer = [
    ['import_index'=>0,'class_package'=>'/Script/CoreUObject','class_name'=>'Package','object_name'=>'/Game/TestPkg','outer_index'=>0,'root_package'=>'/Game/TestPkg','relative_object_path'=>''],
    ['import_index'=>1,'class_package'=>'/Script/CoreUObject','class_name'=>'Class','object_name'=>'Material','outer_index'=>-1,'root_package'=>'/Game/TestPkg','relative_object_path'=>'Material'],
];
$publicRootExport = [[
    'export_index'=>0,'class_index'=>0,'object_name'=>'Material','outer_index'=>0,'object_flags'=>4,'local_path'=>'Material','class_name'=>'',
]];
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory($consumer, [], $publicRootExport, '/Game/TestPkg');
$check(($matches[1] ?? null) === 0, 'ue4_exact_public_root_match');

$wrongOuter = $publicRootExport;
$wrongOuter[0]['outer_index'] = 1;
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory($consumer, [], $wrongOuter, '/Game/TestPkg');
$check(!isset($matches[1]), 'ue4_wrong_outer_rejected');

$private = $publicRootExport;
$private[0]['object_flags'] = 0;
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory($consumer, [], $private, '/Game/TestPkg');
$check(!isset($matches[1]), 'ue4_private_export_rejected');

$providerImports = [
    ['import_index'=>0,'object_name'=>'/Other/CoreUObject','outer_index'=>0],
    ['import_index'=>1,'object_name'=>'SomeClass','outer_index'=>-1],
];
$shortConsumer = [
    ['import_index'=>0,'class_package'=>'/Script/CoreUObject','class_name'=>'Package','object_name'=>'/Game/TestPkg','outer_index'=>0,'root_package'=>'/Game/TestPkg','relative_object_path'=>''],
    ['import_index'=>1,'class_package'=>'/Script/CoreUObject','class_name'=>'SomeClass','object_name'=>'Thing','outer_index'=>-1,'root_package'=>'/Game/TestPkg','relative_object_path'=>'Thing'],
];
$shortProvider = [[
    'export_index'=>0,'class_index'=>-2,'object_name'=>'Thing','outer_index'=>0,'object_flags'=>4,'local_path'=>'Thing','class_name'=>'/Other/CoreUObject.SomeClass',
]];
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory($shortConsumer, $providerImports, $shortProvider, '/Game/TestPkg');
$check(($matches[1] ?? null) === 0, 'ue4_short_class_package_fallback_when_no_full_match');

$providerImportsWithExact = [
    ['import_index'=>0,'object_name'=>'/Other/CoreUObject','outer_index'=>0],
    ['import_index'=>1,'object_name'=>'SomeClass','outer_index'=>-1],
    ['import_index'=>2,'object_name'=>'/Script/CoreUObject','outer_index'=>0],
    ['import_index'=>3,'object_name'=>'SomeClass','outer_index'=>-3],
];
$fullSuppressesShort = [
    ['export_index'=>0,'class_index'=>-2,'object_name'=>'Thing','outer_index'=>0,'object_flags'=>4,'local_path'=>'Thing','class_name'=>'/Other/CoreUObject.SomeClass'],
    ['export_index'=>1,'class_index'=>-4,'object_name'=>'Thing','outer_index'=>1,'object_flags'=>4,'local_path'=>'Outer.Thing','class_name'=>'/Script/CoreUObject.SomeClass'],
];
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory($shortConsumer, $providerImportsWithExact, $fullSuppressesShort, '/Game/TestPkg');
$check(!isset($matches[1]), 'ue4_full_class_package_candidate_suppresses_short_fallback_before_outer');

$exportOuterConsumer = $consumer;
$exportOuterConsumer[1]['outer_index'] = 1;
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory($exportOuterConsumer, [], $publicRootExport, '/Game/TestPkg');
$check(!isset($matches[1]), 'v4_does_not_guess_ue4_export_outer_package_context');

$result = [
    'ok' => $failures === [],
    'checks' => 6,
    'failures' => $failures,
    'contract' => [
        'consumer_imports_only_create_requirements',
        'object_class_class_package_outer_and_public_must_match',
        'short_class_package_fallback_only_without_any_full_package_match',
        'one_physical_provider_must_satisfy_complete_requirement_set',
        'v4_does_not_guess_package_name_for_modern_export_outer_imports',
    ],
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
