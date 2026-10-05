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
    if ($packageVersion === null && $schemaPrefix === 'ue1.ut99') {
        $packageVersion = 68;
    }
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

$whitespaceConsumer = $snapshot(
    41004, 'WhitespaceConsumer', 'classic-linkerload', 'ue1.ut99', 'ue1-ut99-retail-v1400-1999-11-30',
    [$import(0, 'WhitespaceProvider', 'Core', 'Package', 0), $import(1, ' ', 'Core', 'Package', -1)], []
);
$whitespaceProvider = $snapshot(
    41005, 'WhitespaceProvider', 'classic-linkerload', 'ue1.ut99', 'ue1-ut99-retail-v1400-1999-11-30',
    [$import(0, 'Core', 'Core', 'Package', 0), $import(1, 'Package', 'Core', 'Class', -1)],
    [$export(0, ' ', '00000004', 0, -2)]
);
$whitespace = Uedb5ClassicDependencyResolver::resolve($whitespaceConsumer, [[
    'package_name'=>'WhitespaceProvider','provider_id'=>41005,'snapshot'=>$whitespaceProvider,
]]);
$check(($whitespace[1]['status'] ?? null) === 'resolved'
    && (int)($whitespace[1]['provider_id'] ?? 0) === 41005
    && (int)($whitespace[1]['export_index'] ?? -1) === 0,
    'ut99_literal_whitespace_fname_is_not_trimmed');

$noneConsumer = $snapshot(
    41006, 'NoneConsumer', 'classic-linkerload', 'ue1.ut99', 'ue1-ut99-retail-v1400-1999-11-30',
    [
        $import(0, 'Provider', 'Core', 'Package', 0),
        $import(1, 'None', 'Core', 'Class', -1),
        $import(2, 'Obj', 'None', 'Class', -1),
        $import(3, 'Obj', 'Core', 'None', -1),
    ], []
);
$noneResolved = Uedb5ClassicDependencyResolver::resolve($noneConsumer, [[
    'package_name'=>'Provider','provider_id'=>41002,'snapshot'=>$ut99Public,
]]);
foreach ([1,2,3] as $noneIndex) {
    $check(($noneResolved[$noneIndex]['status'] ?? null) === 'unresolved'
        && ($noneResolved[$noneIndex]['reason'] ?? null) === 'source_irrelevant_name_none'
        && ($noneResolved[$noneIndex]['dependency_class'] ?? null) === 'runtime_derived'
        && ($noneResolved[$noneIndex]['provider_id'] ?? null) === null,
        'ut99_name_none_import_' . $noneIndex . '_is_source_irrelevant');
}

$noneAncestorConsumer = $snapshot(
    41007, 'NoneAncestorConsumer', 'classic-linkerload', 'ue1.ut99', 'ue1-ut99-retail-v1400-1999-11-30',
    [
        $import(0, 'Provider', 'Core', 'Package', 0),
        $import(1, 'None', 'Core', 'Class', -1),
        $import(2, 'Child', 'Core', 'Class', -2),
    ], []
);
$noneAncestorProvider = $snapshot(
    41008, 'Provider', 'classic-linkerload', 'ue1.ut99', 'ue1-ut99-retail-v1400-1999-11-30',
    [], [$export(0, 'Child', '00000004')]
);
$noneAncestorResolved = Uedb5ClassicDependencyResolver::resolve($noneAncestorConsumer, [[
    'package_name'=>'Provider','provider_id'=>41008,'snapshot'=>$noneAncestorProvider,
]]);
$check(($noneAncestorResolved[1]['reason'] ?? null) === 'source_irrelevant_name_none'
    && ($noneAncestorResolved[2]['status'] ?? null) === 'unresolved'
    && ($noneAncestorResolved[2]['reason'] ?? null) === 'parent_source_linker_unavailable'
    && ($noneAncestorResolved[2]['dependency_class'] ?? null) === 'runtime_derived'
    && (int)($noneAncestorResolved[2]['parent_import_index'] ?? -1) === 1
    && (int)($noneAncestorResolved[2]['provider_id'] ?? 0) === 41008,
    'ut99_child_of_name_none_import_fails_parent_source_linker_check');

$unreal2Consumer = $snapshot(
    42001, 'Consumer', 'classic-linkerload', 'ue2.unreal2', 'ue2-unreal2-package-v126',
    $consumerImports, [], 126
);
$unreal2Private = $snapshot(
    42002, 'Provider', 'classic-linkerload', 'ue2.unreal2', 'ue2-unreal2-package-v126',
    [], [$export(0, 'Obj', '00000000')], 126
);
$unreal2 = Uedb5ClassicDependencyResolver::resolve($unreal2Consumer, [[
    'package_name'=>'Provider','provider_id'=>42002,'snapshot'=>$unreal2Private,
]]);
$check(($unreal2[1]['status'] ?? null) === 'unresolved'
    && ($unreal2[1]['reason'] ?? null) === 'ue2_verify_import_source_implementation_unavailable',
    'unreal2_v126_does_not_inherit_v69_verify_import');
$unreal2V69Consumer = $snapshot(
    42003, 'Consumer69', 'classic-linkerload', 'ue2.unreal2', 'ue2-unreal2-2000-12-09-package-v69',
    $consumerImports, [], 69
);
$unreal2V69Private = $snapshot(
    42004, 'Provider', 'classic-linkerload', 'ue2.unreal2', 'ue2-unreal2-2000-12-09-package-v69',
    [], [$export(0, 'Obj', '00000000')], 69
);
$unreal2V69 = Uedb5ClassicDependencyResolver::resolve($unreal2V69Consumer, [[
    'package_name'=>'Provider','provider_id'=>42004,'snapshot'=>$unreal2V69Private,
]]);
$check(($unreal2V69[1]['status'] ?? null) === 'missing'
    && ($unreal2V69[1]['reason'] ?? null) === 'verify_import_private_export',
    'unreal2_v69_private_export_is_rejected');
$ut2003V120 = $snapshot(
    42005, 'Consumer120', 'classic-linkerload', 'ue2.ut2003', 'ue2-ut2003-v2107-package-v120',
    $consumerImports, [], 120
);
$ut2003Provider = $snapshot(
    42006, 'Provider', 'classic-linkerload', 'ue2.ut2003', 'ue2-ut2003-v2107-package-v120',
    [], [$export(0, 'Obj', '00000004')], 120
);
$ut2003Resolved = Uedb5ClassicDependencyResolver::resolve($ut2003V120, [[
    'package_name'=>'Provider','provider_id'=>42006,'snapshot'=>$ut2003Provider,
]]);
$check(($ut2003Resolved[1]['status'] ?? null) === 'resolved', 'ut2003_v120_uses_v2107_verify_import');
$ut2003V121 = $snapshot(
    42007, 'Consumer121', 'classic-linkerload', 'ue2.ut2003', 'ue2-ut2003-forward-loader-compatible',
    $consumerImports, [], 121
);
$ut2003Unverified = Uedb5ClassicDependencyResolver::resolve($ut2003V121, [[
    'package_name'=>'Provider','provider_id'=>42006,'snapshot'=>$ut2003Provider,
]]);
$check(($ut2003Unverified[1]['status'] ?? null) === 'unresolved'
    && ($ut2003Unverified[1]['reason'] ?? null) === 'ue2_verify_import_source_implementation_unavailable',
    'ut2003_v121_does_not_inherit_v2107_verify_import');

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

    $writer->write($whitespaceConsumer);
    $writer->write($whitespaceProvider);
    (new Uedb5DependencyRebuilder($reader, $writer))->rebuild(99, 41004, [[
        'game_id'=>99,'file_id'=>41005,'package_name'=>'WhitespaceProvider',
    ]]);
    $whitespaceRows = $reader->snapshot(99, 41004)['sections'][Uedb5DependencyRebuilder::SECTION] ?? [];
    $whitespaceIdentity = (array)($whitespaceRows[1]['required_object_identity'] ?? []);
    $check(($whitespaceRows[1]['outcome'] ?? null) === 'resolved'
        && ($whitespaceIdentity['object_name'] ?? null) === ' '
        && (int)($whitespaceRows[1]['selected_provider_file_id'] ?? 0) === 41005,
        'rebuilder_preserves_literal_whitespace_fname_identity');

    $writer->write($noneConsumer);
    (new Uedb5DependencyRebuilder($reader, $writer))->rebuild(99, 41006, [[
        'game_id'=>99,'file_id'=>41002,'package_name'=>'Provider',
    ]]);
    $noneRows = $reader->snapshot(99, 41006)['sections'][Uedb5DependencyRebuilder::SECTION] ?? [];
    $noneIdentity = (array)($noneRows[1]['required_object_identity'] ?? []);
    $check(($noneRows[1]['outcome'] ?? null) === 'unresolved'
        && ($noneRows[1]['reason_code'] ?? null) === 'source_irrelevant_name_none'
        && ($noneRows[1]['dependency_class'] ?? null) === 'runtime_derived'
        && ($noneRows[1]['hard'] ?? true) === false
        && ($noneRows[1]['required_package_identity'] ?? null) === null
        && ($noneIdentity['object_name'] ?? null) === 'None',
        'rebuilder_persists_source_irrelevant_name_none_without_hard_dependency');

    $writer->write($noneAncestorConsumer);
    $writer->write($noneAncestorProvider);
    (new Uedb5DependencyRebuilder($reader, $writer))->rebuild(99, 41007, [[
        'game_id'=>99,'file_id'=>41008,'package_name'=>'Provider',
    ]]);
    $ancestorRows = $reader->snapshot(99, 41007)['sections'][Uedb5DependencyRebuilder::SECTION] ?? [];
    $check(($ancestorRows[2]['outcome'] ?? null) === 'unresolved'
        && ($ancestorRows[2]['reason_code'] ?? null) === 'parent_source_linker_unavailable'
        && ($ancestorRows[2]['dependency_class'] ?? null) === 'runtime_derived'
        && ($ancestorRows[2]['hard'] ?? true) === false
        && ($ancestorRows[2]['required_package_identity']['value'] ?? null) === 'Provider'
        && (int)(($ancestorRows[2]['resolver_detail']['parent_import_index'] ?? -1)) === 1,
        'rebuilder_persists_name_none_ancestor_as_invalid_parent_linker');
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
