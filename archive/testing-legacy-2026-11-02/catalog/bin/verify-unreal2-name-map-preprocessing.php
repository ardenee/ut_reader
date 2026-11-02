#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogLegacyNameMapPreprocessor;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogParsedPackageMetadataSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Unreal2SnapshotBuilder;

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
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,package_version INTEGER,licensee_version INTEGER)');
$db->exec("INSERT INTO ue_game_profiles VALUES(1,'UE2',1)");
$db->exec('INSERT INTO ue_games VALUES(2,1)');
$db->exec('INSERT INTO ue_files VALUES(1001,2,69,127),(1002,2,126,0)');

$long = str_repeat('LongPackage', 8);
$long63 = mb_substr($long, 0, 63, 'UTF-8');
$fname = static fn(int $index, string $text): array => ['index'=>$index,'number'=>0,'text'=>$text];
$names = [
    ['name'=>'Core','flags'=>CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS],
    ['name'=>'Package','flags'=>CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS],
    ['name'=>$long,'flags'=>CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS],
    ['name'=>'Class','flags'=>CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS],
    ['name'=>'FilteredObject','flags'=>0],
];
$imports = [[
    'classPackage'=>$fname(0,'Core'),'ClassPackage'=>$fname(0,'Core'),'classPackageText'=>'Core',
    'className'=>$fname(1,'Package'),'ClassName'=>$fname(1,'Package'),'classNameText'=>'Package',
    'outerIndex'=>0,'OuterIndex'=>0,
    'objectName'=>$fname(2,$long),'ObjectName'=>$fname(2,$long),'objectNameText'=>$long,
],[
    'classPackage'=>$fname(0,'Core'),'ClassPackage'=>$fname(0,'Core'),'classPackageText'=>'Core',
    'className'=>$fname(3,'Class'),'ClassName'=>$fname(3,'Class'),'classNameText'=>'Class',
    'outerIndex'=>-1,'OuterIndex'=>-1,
    'objectName'=>$fname(4,'FilteredObject'),'ObjectName'=>$fname(4,'FilteredObject'),'objectNameText'=>'FilteredObject',
]];

$builder = new CatalogParsedPackageMetadataSnapshotBuilder($db, ['common_packages'=>[]]);
$v69 = $builder->buildParsedSections(1001,2,'Consumer69','Consumer69.u',$names,$imports,[]);
$v126 = $builder->buildParsedSections(1002,2,'Consumer126','Consumer126.u',$names,$imports,[]);

$checks = [];
$failures = [];
$check = static function (string $name, bool $ok) use (&$checks, &$failures): void {
    $checks[$name] = $ok;
    if (!$ok) { $failures[] = $name; }
};

$check('v69_serialized_long_name_is_preserved', (($v69['names'][2]['name_text'] ?? null) === $long));
$check('v69_effective_name_is_not_v126_truncated', (($v69['imports'][0]['object_name'] ?? null) === $long));
$check('v69_zero_context_name_maps_to_none', (($v69['imports'][1]['object_name'] ?? null) === 'None'));
$check('v126_serialized_long_name_is_preserved', (($v126['names'][2]['name_text'] ?? null) === $long));
$check('v126_effective_name_is_truncated_to_name_size_minus_one', (($v126['imports'][0]['object_name'] ?? null) === $long63));
$check('v126_zero_context_name_maps_to_none', (($v126['imports'][1]['object_name'] ?? null) === 'None'));

$uedbFname = static fn(int $index, string $text): array => ['name_index'=>$index,'number'=>0,'text'=>$text];
$snapshot = static function (int $version, string $policy, string $objectText, string $objectFlags) use ($uedbFname): array {
    return [
        'file'=>['id'=>5000+$version,'game_id'=>2,'package_name'=>'Consumer'.$version,'original_name'=>'Consumer'.$version.'.u'],
        'package_family'=>'classic-linkerload',
        'source_policy'=>$policy,
        'section_schemas'=>[
            'summary'=>'ue2.unreal2.package-summary.v1',
            'names'=>'ue2.unreal2.name-entry.v1',
            'imports'=>'ue2.unreal2.object-import.v1',
            'exports'=>'ue2.unreal2.object-export.v1',
        ],
        'sections'=>[
            'summary'=>[['package_version'=>$version,'licensee_version'=>0]],
            'names'=>[
                ['index'=>0,'text'=>'Core','flags'=>'00070000'],
                ['index'=>1,'text'=>'Package','flags'=>'00070000'],
                ['index'=>2,'text'=>$objectText,'flags'=>$objectFlags],
            ],
            'imports'=>[[
                'index'=>0,
                'class_package'=>$uedbFname(0,'Core'),
                'class_name'=>$uedbFname(1,'Package'),
                'outer_index'=>0,
                'object_name'=>$uedbFname(2,$objectText),
            ]],
            'exports'=>[],
        ],
    ];
};

$v126Snapshot = $snapshot(126,Uedb5Unreal2SnapshotBuilder::POLICY_V126_GENERIC,$long,'00070000');
$v126Resolved = Uedb5ClassicDependencyResolver::resolve($v126Snapshot, []);
$check('v5_v126_required_package_uses_truncated_effective_name',
    ($v126Resolved[0]['provider_package'] ?? null) === $long63
    && ($v126Resolved[0]['reason'] ?? null) === 'package_provider_unavailable');

$v69None = $snapshot(69,Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000,'FilteredRoot','00000000');
$v69NoneResolved = Uedb5ClassicDependencyResolver::resolve($v69None, []);
$check('v5_v69_zero_context_name_is_source_irrelevant',
    ($v69NoneResolved[0]['reason'] ?? null) === 'source_irrelevant_name_none'
    && ($v69NoneResolved[0]['dependency_class'] ?? null) === 'runtime_derived');

$v127Snapshot = $snapshot(127,Uedb5Unreal2SnapshotBuilder::POLICY_POST_V126_UNRESOLVED,$long,'00070000');
$v127Resolved = Uedb5ClassicDependencyResolver::resolve($v127Snapshot, []);
$check('v5_v127_does_not_inherit_v126_name_truncation',
    ($v127Resolved[0]['provider_package'] ?? null) === $long);

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures===[]?0:1);
