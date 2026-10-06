#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogParsedPackageMetadataSnapshotBuilder;

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/lib/CatalogSupportCore.php';
require_once $root . '/bootstrap/autoload.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required.\n");
    exit(2);
}

$readerSource = file_get_contents(dirname($root) . '/UE4/UnrealPackageReader.php') ?: '';
$builderSource = file_get_contents($root . '/src/Infrastructure/Metadata/CatalogParsedPackageMetadataSnapshotBuilder.php') ?: '';
$auditSource = file_get_contents(dirname($root) . '/specs/ue4-4.27.2-verifyimport-conformance-audit.md') ?: '';
$specSource = file_get_contents(dirname($root) . '/specs/ue4-4.27.2-dependency-resolution.md') ?: '';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,engine_key TEXT,is_active INTEGER)');
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,profile_id INTEGER)');
$db->exec("INSERT INTO ue_game_profiles VALUES(1,'UE4',1)");
$db->exec('INSERT INTO ue_games VALUES(7,1)');
$builder = new CatalogParsedPackageMetadataSnapshotBuilder($db, [
    'common_packages' => [],
]);

$imports = [[
    'classPackage' => ['index'=>0,'number'=>0,'text'=>'/Script/CoreUObject'],
    'ClassPackage' => ['index'=>0,'number'=>0,'text'=>'/Script/CoreUObject'],
    'classPackageText' => '/Script/CoreUObject',
    'className' => ['index'=>1,'number'=>0,'text'=>'Object'],
    'ClassName' => ['index'=>1,'number'=>0,'text'=>'Object'],
    'classNameText' => 'Object',
    'outerIndex' => -2,
    'OuterIndex' => -2,
    'objectName' => ['index'=>2,'number'=>0,'text'=>'Thing'],
    'ObjectName' => ['index'=>2,'number'=>0,'text'=>'Thing'],
    'objectNameText' => 'Thing',
    'packageName' => ['index'=>3,'number'=>0,'text'=>'/Game/ExternalProvider'],
    'PackageName' => ['index'=>3,'number'=>0,'text'=>'/Game/ExternalProvider'],
    'packageNameText' => '/Game/ExternalProvider',
], [
    'classPackageText' => '/Script/CoreUObject',
    'classNameText' => 'Package',
    'outerIndex' => 0,
    'objectNameText' => '/Game/OuterProvider',
]];
$names = [
    ['name'=>'/Script/CoreUObject'],
    ['name'=>'Object'],
    ['name'=>'Thing'],
    ['name'=>'/Game/ExternalProvider'],
];
$snapshot = $builder->buildParsedSections(
    99,
    7,
    '/Game/Consumer',
    'Consumer.uasset',
    $names,
    $imports,
    []
);
$storedImport = (array)(($snapshot['imports'] ?? [])[0] ?? []);

$checks = [];
$checks['reader_gate_is_version_519'] = str_contains($readerSource, 'private const VER_NON_OUTER_PACKAGE_IMPORT = 519;');
$checks['reader_honors_editor_only_filter_gate'] = str_contains(
    $readerSource,
    '$version >= self::VER_NON_OUTER_PACKAGE_IMPORT && !$filterEditorOnly'
);
$checks['reader_retains_package_name_text'] = str_contains($readerSource, "'packageNameText' => \$this->fnameText(\$packageName)");
$checks['v4_builder_drops_parser_package_name'] = !array_key_exists('packageNameText', $storedImport)
    && !array_key_exists('package_name', $storedImport)
    && !array_key_exists('package_name_text', $storedImport);
$checks['audit_classifies_import_outer_uncertainty'] = str_contains(
    $auditSource,
    'cannot distinguish every non-empty ExternalPackage PackageName after persistence'
);
$checks['audit_keeps_cross_linker_runtime_cases_out_of_static_resolution'] = str_contains(
    $auditSource,
    'pre-519 different-linker cases require runtime redirect/instancing state.'
) && str_contains(
    $auditSource,
    'exact Import-outer explicit-PackageName reconstruction requires reparsing the original package or migrating to metadata that retains PackageName.'
);
$checks['cross_linker_outer_requires_full_identity_match'] = str_contains(
    $specSource,
    "must match the consumer outer Import's `ObjectName`, `ClassName`, and `ClassPackage`"
);

$failed = [];
foreach ($checks as $name => $ok) {
    fwrite(STDOUT, ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL);
    if (!$ok) {
        $failed[] = $name;
    }
}

fwrite(STDOUT, sprintf("Result: %d/%d checks passed.\n", count($checks) - count($failed), count($checks)));
if ($failed !== []) {
    fwrite(STDERR, 'Failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
exit(0);
