#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogLegacyNameMapPreprocessor;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogParsedPackageMetadataSnapshotBuilder;

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/lib/CatalogSupportCore.php';
require_once $root . '/bootstrap/autoload.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required.\n");
    exit(2);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,engine_key TEXT,is_active INTEGER)');
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,profile_id INTEGER)');
$db->exec("INSERT INTO ue_game_profiles VALUES(1,'UE1',1)");
$db->exec('INSERT INTO ue_games VALUES(3,1)');

$fname = static fn(int $index, string $text): array => ['index'=>$index,'number'=>0,'text'=>$text];
$names = [
    ['name'=>'Core','flags'=>CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS],
    ['name'=>'Package','flags'=>CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS],
    ['name'=>'Provider','flags'=>CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS],
    ['name'=>'Class','flags'=>CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS],
    ['name'=>'FilteredObject','flags'=>0],
];
$imports = [[
    'classPackage'=>$fname(0,'Core'),'ClassPackage'=>$fname(0,'Core'),'classPackageText'=>'Core',
    'className'=>$fname(1,'Package'),'ClassName'=>$fname(1,'Package'),'classNameText'=>'Package',
    'outerIndex'=>0,'OuterIndex'=>0,
    'objectName'=>$fname(2,'Provider'),'ObjectName'=>$fname(2,'Provider'),'objectNameText'=>'Provider',
],[
    'classPackage'=>$fname(0,'Core'),'ClassPackage'=>$fname(0,'Core'),'classPackageText'=>'Core',
    'className'=>$fname(3,'Class'),'ClassName'=>$fname(3,'Class'),'classNameText'=>'Class',
    'outerIndex'=>-1,'OuterIndex'=>-1,
    'objectName'=>$fname(4,'FilteredObject'),'ObjectName'=>$fname(4,'FilteredObject'),'objectNameText'=>'FilteredObject',
]];

$builder = new CatalogParsedPackageMetadataSnapshotBuilder($db, ['common_packages'=>[]]);
$snapshot = $builder->buildParsedSections(99,3,'Consumer','Consumer.u',$names,$imports,[]);
$storedNames = array_values((array)($snapshot['names'] ?? []));
$storedImports = array_values((array)($snapshot['imports'] ?? []));

$checks = [
    'serialized_name_text_is_preserved' => (($storedNames[4]['name_text'] ?? null) === 'FilteredObject'),
    'serialized_name_flags_are_preserved' => ((int)($storedNames[4]['flags'] ?? -1) === 0),
    'root_package_name_survives_all_context_map' => (($storedImports[0]['object_name'] ?? null) === 'Provider'),
    'zero_context_object_name_becomes_none' => (($storedImports[1]['object_name'] ?? null) === 'None'),
    'zero_context_name_index_is_preserved' => ((int)($storedImports[1]['object_name_index'] ?? -1) === 4),
];
$failures = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures===[]?0:1);
