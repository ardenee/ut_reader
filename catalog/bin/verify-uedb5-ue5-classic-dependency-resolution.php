<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ClassicSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ClassicVerifyImportResolver;

$fname = static fn(string $text, int $index = 0): array => [
    'name_index' => $index,
    'number' => 0,
    'text' => $text,
];
$none = static fn(): array => [
    'name_index' => null,
    'number' => null,
    'text' => '',
    'is_none' => true,
];
$effective = static fn(string $text, int $index = 0): array => $text === ''
    ? $none()
    : ['name_index' => $index, 'number' => 0, 'text' => $text, 'is_none' => false];
$import = static function (
    int $index,
    string $objectName,
    string $className = 'Class',
    string $classPackage = '/Script/CoreUObject',
    int $outerIndex = 0,
    string $packageName = '',
    bool $optional = false
) use ($fname, $effective): array {
    return [
        'index' => $index,
        'package_index' => -($index + 1),
        'class_package' => $fname($classPackage),
        'class_name' => $fname($className),
        'outer_index' => $outerIndex,
        'object_name' => $fname($objectName),
        'serialized_package_name_present' => true,
        'serialized_package_name' => $fname($packageName),
        'effective_package_name' => $effective($packageName),
        'b_import_optional_present' => true,
        'b_import_optional' => $optional,
    ];
};
$export = static function (
    int $index,
    string $objectName,
    int $classIndex = 0,
    int $outerIndex = 0,
    string $flags = '0000000000000001'
) use ($fname): array {
    return [
        'index' => $index,
        'package_index' => $index + 1,
        'class_index' => $classIndex,
        'super_index' => 0,
        'template_index' => 0,
        'outer_index' => $outerIndex,
        'object_name' => $fname($objectName),
        'object_flags' => $flags,
        'object_flags_serialized_width_bits' => 32,
        'package_flags' => '00000000',
        'b_generate_public_hash_present' => true,
        'b_generate_public_hash' => false,
    ];
};

$snapshot = static function (array $imports, array $exports): array {
    return [
        'package_family' => Uedb5Ue5ClassicSnapshotBuilder::PACKAGE_FAMILY,
        'source_policy' => Uedb5Ue5ClassicSnapshotBuilder::SOURCE_POLICY,
        'section_schemas' => [
            'imports' => 'ue5.classic.object-import.v1',
            'exports' => 'ue5.classic.object-export.v1',
        ],
        'sections' => [
            'imports' => array_values($imports),
            'exports' => array_values($exports),
        ],
    ];
};
$provider = static fn(string $packageName, array $snapshot, int $id): array => [
    'package_name' => $packageName,
    'snapshot' => $snapshot,
    'provider_id' => $id,
];

$checks = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};

$rootP = $import(0, '/Game/P', 'Package', '/Script/CoreUObject', 0);
$consumer = $snapshot([$rootP, $import(1, 'Obj', 'Texture2D', '/Script/Engine', -1)], []);
$classPkg = $import(1, '/Script/Engine', 'Package', '/Script/CoreUObject', 0);
$classTexture = $import(0, 'Texture2D', 'Class', '/Script/CoreUObject', -2);
$providerP = $snapshot([$classTexture, $classPkg], [$export(0, 'Obj', -1, 0)]);
$result = Uedb5Ue5ClassicVerifyImportResolver::resolve($consumer, [$provider('/Game/P', $providerP, 10)]);
$check(
    'same_linker_outer_matches_source_index',
    ($result[0]['status'] ?? '') === 'package_only'
        && ($result[1]['status'] ?? '') === 'resolved'
        && (int)($result[1]['export_index'] ?? -1) === 0,
    'A top-level package establishes SourceLinker and its child must match a root export in that linker.'
);

$classStaticMesh = $import(0, 'StaticMesh', 'Class', '/Script/CoreUObject', -2);
$classMismatchProvider = $snapshot([$classStaticMesh, $classPkg], [$export(0, 'Obj', -1, 0)]);
$classMismatch = Uedb5Ue5ClassicVerifyImportResolver::resolve(
    $consumer,
    [$provider('/Game/P', $classMismatchProvider, 11)]
);
$check(
    'class_mismatch_is_deferred_not_rejected',
    ($classMismatch[1]['status'] ?? '') === 'resolved'
        && !empty($classMismatch[1]['deferred_class_verification']),
    'UE5 VerifyImportInner accepts the name/outer candidate and defers exact class verification to create time.'
);

$classRedirector = $import(0, 'ObjectRedirector', 'Class', '/Script/CoreUObject', -2);
$redirectProvider = $snapshot([$classRedirector, $classPkg], [$export(0, 'Obj', -1, 0)]);
$redirect = Uedb5Ue5ClassicVerifyImportResolver::resolve($consumer, [$provider('/Game/P', $redirectProvider, 12)]);
$check(
    'redirector_is_not_direct_object_match',
    ($redirect[1]['status'] ?? '') === 'runtime_only',
    'Non-redirector imports skip ObjectRedirector exports; following the redirector needs runtime object payload state.'
);
$consumerCross = $snapshot([
    $import(0, '/Game/A', 'Package', '/Script/CoreUObject', 0),
    $import(1, 'OuterObj', 'Class', '/Script/CoreUObject', -1),
    $import(2, 'InnerObj', 'Class', '/Script/CoreUObject', -2, '/Game/B'),
], []);
$providerA = $snapshot([], [$export(0, 'OuterObj', 0, 0)]);
$providerB = $snapshot([
    $import(0, 'OuterObj', 'WrongOuterClass', '/Script/Wrong', 0),
], [$export(0, 'InnerObj', 0, -1)]);
$cross = Uedb5Ue5ClassicVerifyImportResolver::resolve($consumerCross, [
    $provider('/Game/A', $providerA, 20),
    $provider('/Game/B', $providerB, 21),
]);
$check(
    'explicit_package_name_selects_different_linker',
    ($cross[2]['status'] ?? '') === 'resolved'
        && ($cross[2]['provider_package'] ?? '') === '/Game/B'
        && !empty($cross[2]['deferred_outer_class_verification']),
    'A child import may load its explicit PackageName linker independently of its outer linker.'
);

$consumerExportOuter = $snapshot([
    $import(0, 'ExternalObj', 'Class', '/Script/CoreUObject', 1, '/Game/B'),
], [$export(0, 'LocalOuter')]);
$exportOuter = Uedb5Ue5ClassicVerifyImportResolver::resolve(
    $consumerExportOuter,
    [$provider('/Game/B', $snapshot([], [$export(0, 'ExternalObj')]), 22)]
);
$check(
    'export_outer_is_allowed_with_explicit_package',
    ($exportOuter[0]['status'] ?? '') === 'resolved'
        && (int)($exportOuter[0]['export_index'] ?? -1) === 0,
    'UE5 permits an import with an export outer when explicit PackageName selected the source linker.'
);

$consumerBadExportOuter = $snapshot([
    $import(0, 'ExternalObj', 'Class', '/Script/CoreUObject', 1),
], [$export(0, 'LocalOuter')]);
$badExportOuter = Uedb5Ue5ClassicVerifyImportResolver::resolve(
    $consumerBadExportOuter,
    [$provider('/Game/B', $snapshot([], [$export(0, 'ExternalObj')]), 23)]
);
$check(
    'export_outer_without_package_is_invalid',
    ($badExportOuter[0]['status'] ?? '') === 'invalid',
    'The UE5 source asserts that export-outers require an explicit PackageName/source linker.'
);

$privateProvider = $snapshot([], [$export(0, 'Obj', 0, 0, '0000000000000000')]);
$privateSecretProvider = $snapshot([], [$export(0, 'Secret', 0, 0, '0000000000000000')]);
$private = Uedb5Ue5ClassicVerifyImportResolver::resolve(
    $snapshot([$rootP, $import(1, 'Obj', 'Class', '/Script/CoreUObject', -1)], []),
    [$provider('/Game/P', $privateProvider, 30)]
);
$check(
    'private_export_rejected_without_graph_exception',
    ($private[1]['status'] ?? '') === 'private_export',
    'A private provider export is not accepted merely because its name and outer match.'
);
$markAsNativeOnly = $snapshot([], [$export(0, 'Obj', 0, 0, '0000000000000004')]);
$rfPublic = Uedb5Ue5ClassicVerifyImportResolver::resolve(
    $snapshot([$rootP, $import(1, 'Obj', 'Class', '/Script/CoreUObject', -1)], []),
    [$provider('/Game/P', $markAsNativeOnly, 34)]
);
$check(
    'ue5_rf_public_is_bit_0',
    ($rfPublic[1]['status'] ?? '') === 'private_export',
    'UE5 RF_Public is 0x00000001; RF_MarkAsNative 0x00000004 must not be mistaken for public.'
);
$consumerImportInExport = $snapshot([
    $rootP,
    $import(1, 'OuterObj', 'Class', '/Script/CoreUObject', 1, '/Game/P'),
    $import(2, 'Secret', 'Class', '/Script/CoreUObject', -2),
], [$export(0, 'LocalContainer')]);
$providerImportInExport = $snapshot([], [
    $export(0, 'OuterObj'),
    $export(1, 'Secret', 0, 1, '0000000000000000'),
]);
$graphA = Uedb5Ue5ClassicVerifyImportResolver::resolve(
    $consumerImportInExport,
    [$provider('/Game/P', $providerImportInExport, 31)]
);
$check(
    'private_allowed_import_is_in_any_export',
    ($graphA[2]['status'] ?? '') === 'resolved'
        && !empty($graphA[2]['private_graph']['import_in_export']),
    'ImportIsInAnyExport permits the otherwise-private provider export.'
);

$consumerExportInImport = $snapshot([
    $rootP,
    $import(1, 'Secret', 'Class', '/Script/CoreUObject', -1),
    $import(2, 'Child', 'Class', '/Script/CoreUObject', -2),
], [$export(0, 'NestedExport', 0, -3)]);
$graphB = Uedb5Ue5ClassicVerifyImportResolver::resolve(
    $consumerExportInImport,
    [$provider('/Game/P', $privateSecretProvider, 32)]
);
$check(
    'private_allowed_any_export_is_in_import',
    ($graphB[1]['status'] ?? '') === 'resolved'
        && !empty($graphB[1]['private_graph']['export_in_import']),
    'AnyExportIsInImport permits the otherwise-private provider export.'
);

$consumerSharedOuter = $snapshot([
    $rootP,
    $import(1, 'Secret', 'Class', '/Script/CoreUObject', -1),
    $import(2, 'Sibling', 'Class', '/Script/CoreUObject', -1),
], [$export(0, 'SiblingExport', 0, -3)]);
$graphC = Uedb5Ue5ClassicVerifyImportResolver::resolve(
    $consumerSharedOuter,
    [$provider('/Game/P', $privateSecretProvider, 33)]
);
$check(
    'private_allowed_shared_outermost',
    ($graphC[1]['status'] ?? '') === 'resolved'
        && !empty($graphC[1]['private_graph']['shared_outermost'])
        && empty($graphC[1]['private_graph']['export_in_import']),
    'AnyExportShareOuterWithImport independently permits a private provider export.'
);

$duplicateProvider = $snapshot([], [
    $export(0, 'Obj', 0, 0, '0000000000000001'),
    $export(1, 'Obj', 0, 0, '0000000000000000'),
]);
$duplicateOrder = Uedb5Ue5ClassicVerifyImportResolver::resolve(
    $snapshot([$rootP, $import(1, 'Obj', 'Class', '/Script/CoreUObject', -1)], []),
    [$provider('/Game/P', $duplicateProvider, 40)]
);
$check(
    'export_hash_order_stops_on_first_private_match',
    ($duplicateOrder[1]['status'] ?? '') === 'private_export',
    'ExportHash prepends low-to-high inserts, so higher-index private duplicates are encountered before lower public ones.'
);

$optionalConsumer = $snapshot([
    $rootP,
    $import(1, 'OptionalObj', 'Class', '/Script/CoreUObject', -1, '/Game/Optional', true),
], []);
$optional = Uedb5Ue5ClassicVerifyImportResolver::resolve(
    $optionalConsumer,
    [$provider('/Game/P', $snapshot([], []), 41)]
);
$check(
    'optional_missing_is_not_hard_missing',
    ($optional[1]['status'] ?? '') === 'optional_missing',
    'bImportOptional must preserve a distinct missing classification.'
);

$duplicateRejected = false;
try {
    Uedb5Ue5ClassicVerifyImportResolver::resolve($consumer, [
        $provider('/Game/P', $providerP, 50),
        $provider('/Game/P', $providerP, 51),
    ]);
} catch (RuntimeException $error) {
    $duplicateRejected = str_contains($error->getMessage(), 'exactly one selected physical linker');
}
$check(
    'resolver_never_combines_same_name_physical_providers',
    $duplicateRejected,
    'One VerifyImport simulation must operate on one selected physical linker per package.'
);

$scriptConsumer = $snapshot([
    $import(0, '/Script/Engine', 'Package', '/Script/CoreUObject', 0),
], []);
$script = Uedb5Ue5ClassicVerifyImportResolver::resolve($scriptConsumer, []);
$check(
    'script_package_absence_is_runtime_only',
    ($script[0]['status'] ?? '') === 'runtime_only',
    'Compiled/native script package lookup must not be fabricated as a missing serialized package.'
);

$dynamicConsumer = $snapshot(
    [$rootP, $import(1, 'Obj', 'Class', '/Script/CoreUObject', -1)],
    [$export(0, 'DynamicOwner', 0, 0, '0000000080000000')]
);
$dynamic = Uedb5Ue5ClassicVerifyImportResolver::resolve(
    $dynamicConsumer,
    [$provider('/Game/P', $providerP, 60)]
);
$check(
    'dynamic_imports_are_not_fabricated',
    count($dynamic) === 2 && array_keys($dynamic) === [0, 1],
    'RF_HasDynamicImports is serialized evidence only; runtime AddDynamicImports rows must not be invented.'
);

$failed = array_values(array_filter($checks, static fn(array $row): bool => !$row['ok']));
echo json_encode(
    ['ok' => $failed === [], 'checks' => $checks],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
), PHP_EOL;
exit($failed === [] ? 0 : 2);
