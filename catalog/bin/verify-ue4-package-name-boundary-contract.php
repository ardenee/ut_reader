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
    'packageNamePresent' => true,
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
require_once dirname($root) . '/UE4/UnrealPackageReader.php';
$epicVersionTable = json_decode(
    (string)file_get_contents(dirname($root) . '/specs/epic-source/ue4-4.27.2/package-loading/epic-version-enum.json'),
    true
);
$gate = (new ReflectionClass(UnrealPackageReader4::class))
    ->getReflectionConstant('VER_NON_OUTER_PACKAGE_IMPORT');
$checks['reader_gate_matches_epic_source'] = $gate !== false
    && $gate->getValue() === ($epicVersionTable['members']['VER_UE4_NON_OUTER_PACKAGE_IMPORT']['value'] ?? null);

$checks['reader_honors_editor_only_filter_gate'] = str_contains(
    $readerSource,
    '$version >= self::VER_NON_OUTER_PACKAGE_IMPORT && !$filterEditorOnly'
);
$checks['reader_retains_package_name_text'] = str_contains($readerSource, "'packageNameText' => \$this->fnameText(\$packageName)");
$checks['v4_builder_preserves_parser_package_name'] =
    !empty($storedImport['serialized_package_name_present'])
    && !empty($storedImport['package_name_present'])
    && ($storedImport['serialized_package_name'] ?? '') === '/Game/ExternalProvider'
    && ($storedImport['package_name'] ?? '') === '/Game/ExternalProvider'
    && ($storedImport['serialized_package_name_index'] ?? null) === 3
    && ($storedImport['root_package'] ?? '') === '/Game/ExternalProvider'
    && ($storedImport['outer_root_package'] ?? '') === '/Game/OuterProvider';

// Actual V4 snapshot behavior: NAME_None is serialized but does not
// override the outer package; absent wire field is not an explicit package.
$noneImports = $imports;
$noneImports[0]['packageName'] = ['index'=>0,'number'=>0,'text'=>'None'];
$noneImports[0]['packageNameText'] = 'None';
$noneSnapshot = $builder->buildParsedSections(
    99, 7, '/Game/Consumer', 'Consumer.uasset', $names, $noneImports, []
);
$noneRow = (array)(($noneSnapshot['imports'] ?? [])[0] ?? []);
$checks['name_none_has_no_explicit_package_override'] =
    !empty($noneRow['serialized_package_name_present'])
    && empty($noneRow['package_name_present'])
    && ($noneRow['package_name'] ?? '') === ''
    && ($noneRow['root_package'] ?? '') === '/Game/OuterProvider';

$absentImports = $imports;
$absentImports[0]['packageNamePresent'] = false;
$absentSnapshot = $builder->buildParsedSections(
    99, 7, '/Game/Consumer', 'Consumer.uasset', $names, $absentImports, []
);
$absentRow = (array)(($absentSnapshot['imports'] ?? [])[0] ?? []);
$checks['absent_wire_field_has_no_explicit_package_override'] =
    empty($absentRow['serialized_package_name_present'])
    && empty($absentRow['package_name_present'])
    && ($absentRow['package_name'] ?? '') === ''
    && ($absentRow['root_package'] ?? '') === '/Game/OuterProvider';

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
