<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;

$failures = [];
$checks = [];
$check = static function (bool $ok, string $name) use (&$failures, &$checks): void {
    $checks[$name] = $ok;
    if (!$ok) { $failures[] = $name; }
};
$fname = static fn(string $text): array => ['name_index'=>0,'number'=>0,'text'=>$text];
$import = static function (int $index, string $object, string $classPackage, string $className, int $outer) use ($fname): array {
    return [
        'index'=>$index,'class_package'=>$fname($classPackage),'class_name'=>$fname($className),
        'outer_index'=>$outer,'object_name'=>$fname($object),
    ];
};
$export = static function (int $index, string $object, string $flags, int $outer = 0, int $classIndex = 0) use ($fname): array {
    return [
        'index'=>$index,'class_index'=>$classIndex,'super_index'=>0,'outer_index'=>$outer,
        'object_name'=>$fname($object),'object_flags'=>$flags,
    ];
};
$snapshot = static function (
    int $fileId,
    string $packageName,
    string $family,
    string $schemaPrefix,
    string $sourcePolicy,
    array $imports,
    array $exports,
    ?int $packageVersion = null
): array {
    $sections = ['imports'=>array_values($imports),'exports'=>array_values($exports)];
    $schemas = [
        'imports'=>$schemaPrefix . '.object-import.v1',
        'exports'=>$schemaPrefix . '.object-export.v1',
    ];
    if ($packageVersion !== null) {
        $sections['summary'] = [['package_version'=>$packageVersion]];
        $schemas['summary'] = $schemaPrefix . '.package-summary.v1';
    }
    return [
        'file'=>['id'=>$fileId,'game_id'=>99,'package_name'=>$packageName,'original_name'=>$packageName . '.u'],
        'package_family'=>$family,
        'source_policy'=>$sourcePolicy,
        'section_schemas'=>$schemas,
        'sections'=>$sections,
    ];
};

$consumerImports = [
    $import(0, 'Provider', 'Core', 'Package', 0),
    $import(1, 'Obj', 'Core', 'Class', -1),
];
$ut99Consumer = $snapshot(
    41001, 'Consumer', 'classic-linkerload', 'ue1.ut99', 'ue1-ut99-retail-v1400-1999-11-30',
    $consumerImports, []
);
$ut99Public = $snapshot(
    41002, 'Provider', 'classic-linkerload', 'ue1.ut99', 'ue1-ut99-retail-v1400-1999-11-30',
    [], [$export(0, 'Obj', '00000004')]
);
$ut99Private = $snapshot(
    41003, 'Provider', 'classic-linkerload', 'ue1.ut99', 'ue1-ut99-retail-v1400-1999-11-30',
    [], [$export(0, 'Obj', '00000000')]
);
$resolved = Uedb5ClassicDependencyResolver::resolve($ut99Consumer, [[
    'package_name'=>'Provider','provider_id'=>41002,'snapshot'=>$ut99Public,
]]);
$check(($resolved[0]['status'] ?? null) === 'package_only', 'ut99_package_import_is_package_only');
$check(($resolved[1]['status'] ?? null) === 'resolved'
    && (int)($resolved[1]['provider_id'] ?? 0) === 41002
    && (int)($resolved[1]['export_index'] ?? -1) === 0,
    'ut99_public_export_resolves');
$private = Uedb5ClassicDependencyResolver::resolve($ut99Consumer, [[
    'package_name'=>'Provider','provider_id'=>41003,'snapshot'=>$ut99Private,
]]);
$check(($private[1]['status'] ?? null) === 'missing', 'ut99_private_export_is_rejected');
$unreal2Consumer = $snapshot(
    42001, 'Consumer', 'classic-linkerload', 'ue2.unreal2', 'ue2-unreal2-package-v126',
    $consumerImports, []
);
$unreal2Private = $snapshot(
    42002, 'Provider', 'classic-linkerload', 'ue2.unreal2', 'ue2-unreal2-package-v126',
    [], [$export(0, 'Obj', '00000000')]
);
$unreal2 = Uedb5ClassicDependencyResolver::resolve($unreal2Consumer, [[
    'package_name'=>'Provider','provider_id'=>42002,'snapshot'=>$unreal2Private,
]]);
$check(($unreal2[1]['status'] ?? null) === 'resolved', 'unreal2_private_export_exception_is_preserved');

$ut3Consumer = $snapshot(
    43001, 'Consumer', 'classic-linkerload', 'ue3.ut3', 'ue3-ut3-jan2008-package-v512',
    $consumerImports, [], 512
);
$ut3Provider = $snapshot(
    43002, 'Provider', 'classic-linkerload', 'ue3.ut3', 'ue3-ut3-jan2008-package-v512',
    [], [$export(0, 'Obj', '0000000400000000')], 512
);
$ut3 = Uedb5ClassicDependencyResolver::resolve($ut3Consumer, [[
    'package_name'=>'Provider','provider_id'=>43002,'snapshot'=>$ut3Provider,
]]);
$check(($ut3[1]['status'] ?? null) === 'resolved', 'ut3_64bit_rf_public_resolves');
$ut3ExportOuter = $snapshot(
    43003, 'Consumer', 'classic-linkerload', 'ue3.ut3', 'ue3-ut3-jan2008-package-v512',
    [$consumerImports[0], $import(1, 'Obj', 'Core', 'Class', 1)],
    [$export(0, 'Group', '0000000400000000', -1)], 512
);
$ut3Unresolved = Uedb5ClassicDependencyResolver::resolve($ut3ExportOuter, [[
    'package_name'=>'Provider','provider_id'=>43002,'snapshot'=>$ut3Provider,
]]);
$check(($ut3Unresolved[1]['status'] ?? null) === 'unresolved'
    && ($ut3Unresolved[1]['reason'] ?? null) === 'ue3_cooked_export_outer'
    && ($ut3Unresolved[1]['provider_package'] ?? null) === 'Provider'
    && ($ut3Unresolved[1]['provider_id'] ?? null) === null,
    'ut3_cooked_export_outer_retains_path_identity_but_stays_unresolved');
$ut3RootExportOuter = $snapshot(
    43004, 'Consumer', 'classic-linkerload', 'ue3.ut3', 'ue3-ut3-jan2008-package-v512',
    [$import(0, 'Obj', 'Engine', 'MaterialInstanceConstant', 2)],
    [$export(0, 'Provider', '0000000400000000'), $export(1, 'Group', '0000000400000000', 1)], 512
);
$ut3RootExport = Uedb5ClassicDependencyResolver::resolve($ut3RootExportOuter, []);
$check(($ut3RootExport[0]['status'] ?? null) === 'unresolved'
    && ($ut3RootExport[0]['reason'] ?? null) === 'ue3_cooked_export_outer'
    && ($ut3RootExport[0]['provider_package'] ?? null) === 'Provider'
    && ($ut3RootExport[0]['provider_id'] ?? null) === null,
    'ut3_cooked_root_export_retains_getimportpathname_root_but_stays_unresolved');

$ue4Imports = [
    $import(0, 'Provider', '/Script/CoreUObject', 'Package', 0),
    $import(1, 'Obj', '/Script/CoreUObject', 'Class', -1),
];
$ut4Consumer = $snapshot(
    44001, 'Consumer', 'ue4-classic-package', 'ue4.ut4', 'ue4-4.27.2-release-classic-package',
    $ue4Imports, []
);
$ut4Provider = $snapshot(
    44002, 'Provider', 'ue4-classic-package', 'ue4.ut4', 'ue4-4.27.2-release-classic-package',
    [], [$export(0, 'Obj', '00000001')]
);
$ut4 = Uedb5ClassicDependencyResolver::resolve($ut4Consumer, [[
    'package_name'=>'Provider','provider_id'=>44002,'snapshot'=>$ut4Provider,
]]);
$check(($ut4[1]['status'] ?? null) === 'resolved', 'ut4_verify_import_resolves_from_v5_tables');
$scriptConsumer = $snapshot(
    44003, 'Consumer', 'ue4-classic-package', 'ue4.ut4', 'ue4-4.27.2-release-classic-package',
    [$import(0, '/Script/Engine', '/Script/CoreUObject', 'Package', 0)], []
);
$script = Uedb5ClassicDependencyResolver::resolve($scriptConsumer, []);
$check(($script[0]['status'] ?? null) === 'common'
    && ($script[0]['dependency_class'] ?? null) === 'script',
    'ut4_script_package_is_common_without_fake_provider');

$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-uedb5-classic-resolve-' . bin2hex(random_bytes(5));
try {
    $writer = new Uedb5MetadataSnapshotWriter($tempRoot);
    $reader = new Uedb5MetadataReader($tempRoot);
    $writer->write($ut99Consumer);
    $writer->write($ut99Public);
    $rebuilt = (new Uedb5DependencyRebuilder($reader, $writer))->rebuild(99, 41001, [[
        'game_id'=>99,'file_id'=>41002,'package_name'=>'Provider',
    ]]);
    $rows = $reader->snapshot(99, 41001)['sections'][Uedb5DependencyRebuilder::SECTION] ?? [];
    $check(($rebuilt['dependency_schema'] ?? null) === Uedb5DependencyRebuilder::UNREAL_CLASSIC_SCHEMA,
        'rebuilder_uses_unreal_classic_dependency_schema');
    $check(count($rows) === 2
        && ($rows[0]['outcome'] ?? null) === 'package_only'
        && ($rows[1]['outcome'] ?? null) === 'resolved'
        && (int)($rows[1]['selected_provider_file_id'] ?? 0) === 41002,
        'rebuilder_persists_v5_only_classic_results');
} finally {
    if (is_dir($tempRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($tempRoot);
    }
}

$source = file_get_contents($root . '/src/Infrastructure/Metadata/Uedb5ClassicDependencyResolver.php');
$check(is_string($source)
    && !str_contains($source, 'BlockedCompressedMetadata')
    && !str_contains($source, 'ue_file_metadata')
    && !str_contains($source, 'ue_dependency_links'),
    'classic_v5_resolver_has_no_v4_metadata_dependency');

echo json_encode([
    'ok'=>$failures === [],
    'checks'=>$checks,
    'failures'=>$failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
