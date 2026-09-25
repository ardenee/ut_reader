#!/usr/bin/env php
<?php
/**
 * Contract verifier for deterministic UE3 VerifyImportInner projection matching.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/src/Infrastructure/Persistence/PdoUe3VerifyImportProjectionResolver.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe3VerifyImportProjectionResolver;

$checks = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};

$consumer = [
    ['import_index' => 0, 'class_package' => 'Core', 'class_name' => 'Package', 'object_name' => 'Foo', 'outer_index' => 0, 'relative_object_path' => ''],
    ['import_index' => 1, 'class_package' => 'Engine', 'class_name' => 'Texture', 'object_name' => 'Group', 'outer_index' => -1, 'relative_object_path' => 'Group'],
    ['import_index' => 2, 'class_package' => 'Engine', 'class_name' => 'Texture', 'object_name' => 'Wall', 'outer_index' => -2, 'relative_object_path' => 'Group.Wall'],
];
$providerImports = [
    ['import_index' => 0, 'class_package' => 'Core', 'class_name' => 'Package', 'object_name' => 'Engine', 'outer_index' => 0],
    ['import_index' => 1, 'class_package' => 'Core', 'class_name' => 'Class', 'object_name' => 'Texture', 'outer_index' => -1],
];
$providerExports = [
    ['export_index' => 0, 'class_index' => -2, 'object_name' => 'Group', 'outer_index' => 0, 'object_flags' => 4, 'local_path' => 'Group'],
    ['export_index' => 1, 'class_index' => -2, 'object_name' => 'Wall', 'outer_index' => 1, 'object_flags' => 4, 'local_path' => 'Group.Wall'],
];

$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $consumer,
    $providerImports,
    $providerExports,
    'Foo'
);
$check(
    'exact_identity_and_outer_resolve',
    ($matches[1] ?? null) === 0 && ($matches[2] ?? null) === 1,
    'UE3 must resolve the parent first and require the child export OuterIndex to reference that exact export.'
);

$wrongOuter = $providerExports;
$wrongOuter[1]['outer_index'] = 0;
$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $consumer,
    $providerImports,
    $wrongOuter,
    'Foo'
);
$check(
    'wrong_outer_rejected',
    !isset($matches[2]),
    'A same-path/class leaf whose serialized OuterIndex does not reference the resolved parent must not satisfy the import.'
);

$private = $providerExports;
$private[1]['object_flags'] = 0;
$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $consumer,
    $providerImports,
    $private,
    'Foo'
);
$check(
    'private_export_rejected',
    !isset($matches[2]),
    'Ordinary UE3 external matching requires RF_Public.'
);

$wrongClassPackage = $consumer;
$wrongClassPackage[2]['class_package'] = 'OtherEngine';
$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $wrongClassPackage,
    $providerImports,
    $providerExports,
    'Foo'
);
$check(
    'class_package_is_exact',
    !isset($matches[2]),
    'ClassName alone is insufficient; UE3 VerifyImportInner also requires exact ClassPackage.'
);

$cookedOuter = $consumer;
$cookedOuter[2]['outer_index'] = 1;
$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $cookedOuter,
    $providerImports,
    $providerExports,
    'Foo'
);
$check(
    'cooked_import_export_outer_not_invented',
    !isset($matches[2]),
    'The audited UE3 source returns on an import whose cooked OuterIndex is an export; the catalog must not invent a fallback.'
);

$ok = !in_array(false, array_column($checks, 'ok'), true);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 3);
