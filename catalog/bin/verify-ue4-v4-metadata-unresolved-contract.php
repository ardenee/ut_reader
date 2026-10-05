#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactDependencyReadService;
use UnrealDb\Catalog\Infrastructure\Metadata\CompactDependencyEncoding;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyResolver;

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
$db->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,engine_key TEXT,is_active INTEGER,profile_name TEXT,notes TEXT)');
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,name TEXT,slug TEXT,profile_id INTEGER)');
$db->exec("INSERT INTO ue_game_profiles VALUES(1,'UE4',1,'UE4 4.27.2','source policy')");
$db->exec("INSERT INTO ue_games VALUES(7,'Unreal Tournament 4','ut4',1)");
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,scan_status TEXT,file_size INTEGER,md5 TEXT,sha1 TEXT,package_name TEXT,uploaded_at TEXT,package_version INTEGER)');
$db->exec("INSERT INTO ue_files VALUES(99,7,'verified',1,'','', 'Consumer', '',520)");
$db->exec("INSERT INTO ue_files VALUES(100,7,'verified',2,'a','a','Stock','2026-10-01',511)");
$db->exec("INSERT INTO ue_files VALUES(101,7,'verified',3,'b','b','Stock','2026-09-01',511)");
$db->exec('CREATE TABLE ue_package_providers(game_id INTEGER,package_name TEXT,file_id INTEGER,source_kind TEXT,source_id INTEGER,provider_created_at TEXT)');
$db->exec('CREATE TABLE ue_file_package_aliases(id INTEGER PRIMARY KEY,file_id INTEGER,game_id INTEGER,package_name TEXT)');
$db->exec('CREATE TABLE ue_invalid_file_identities(file_size INTEGER,md5 TEXT,sha1 TEXT)');
$db->exec("INSERT INTO ue_package_providers VALUES(7,'Stock',100,'primary',100,'2026-10-01')");
$db->exec("INSERT INTO ue_package_providers VALUES(7,'Stock',101,'primary',101,'2026-09-01')");
$imports = [
    [
        'id'=>1,'import_index'=>0,'class_package'=>'/Script/Engine','class_name'=>'SoundCue',
        'object_name'=>'Leaf','outer_index'=>7,'full_path'=>'Stock.Group.Leaf',
        'root_package'=>'Stock','relative_object_path'=>'Group.Leaf','is_common'=>0,
    ],
    [
        'id'=>2,'import_index'=>1,'class_package'=>'/Script/Engine','class_name'=>'Material',
        'object_name'=>'Child','outer_index'=>-1,'full_path'=>'Stock.Group.Leaf.Child',
        'root_package'=>'Stock','relative_object_path'=>'Group.Leaf.Child','is_common'=>0,
    ],
    [
        'id'=>3,'import_index'=>2,'class_package'=>'/Script/CoreUObject','class_name'=>'Package',
        'object_name'=>'DefinitelyAbsent','outer_index'=>0,'full_path'=>'DefinitelyAbsent',
        'root_package'=>'DefinitelyAbsent','relative_object_path'=>'','is_common'=>0,
    ],
    [
        'id'=>4,'import_index'=>3,'class_package'=>'/Script/CoreUObject','class_name'=>'Package',
        'object_name'=>'Stock','outer_index'=>0,'full_path'=>'Stock',
        'root_package'=>'Stock','relative_object_path'=>'','is_common'=>0,
    ],
    [
        'id'=>5,'import_index'=>4,'class_package'=>'/Script/CoreUObject','class_name'=>'Package',
        'object_name'=>' /Script/Engine','outer_index'=>0,'full_path'=>'/Script/Engine',
        'root_package'=>'/Script/Engine','relative_object_path'=>'','is_common'=>1,
    ],
];

$resolved520 = PdoDependencyResolver::resolve($db, 7, 99, $imports, null);
$db->exec('UPDATE ue_files SET package_version=519 WHERE id=99');
$resolved519 = PdoDependencyResolver::resolve($db, 7, 99, $imports, null);

$checks = [];
$checks['version_520_direct_export_outer_is_metadata_unresolved'] = ($resolved520[1]['status'] ?? '') === 'unresolved';
$checks['version_520_descendant_is_metadata_unresolved'] = ($resolved520[2]['status'] ?? '') === 'unresolved';
$checks['ue4_metadata_reason_is_explicit'] = ($resolved520[1]['source'] ?? '') === 'ue4_v4_missing_package_name'
    && ($resolved520[1]['confidence'] ?? '') === 'metadata_unresolved';
$checks['pre_520_export_outer_stops_at_unknown_provider_environment'] = ($resolved519[1]['status'] ?? '') === 'unresolved'
    && ($resolved519[1]['source'] ?? '') === 'provider_environment_ambiguous';
$checks['ordinary_absent_package_stays_missing'] = ($resolved520[3]['status'] ?? '') === 'missing';
$checks['padded_script_fname_does_not_trust_normalized_common_flag'] = ($resolved520[5]['status'] ?? '') === 'missing';
$checks['duplicate_provider_environment_is_unresolved_not_guessed'] = ($resolved520[4]['status'] ?? '') === 'unresolved'
    && ($resolved520[4]['source'] ?? '') === 'provider_environment_ambiguous'
    && ($resolved520[4]['confidence'] ?? '') === 'source_unresolved'
    && ($resolved520[4]['candidate_file_ids'] ?? []) === [100,101];
$method = new ReflectionMethod(PdoDependencyResolver::class, 'requiredImportIndexes');
$required = $method->invoke(null, [
    ['import_index'=>0,'root_package'=>'Stock','relative_object_path'=>'NeedsPackageName','outer_index'=>-1,'is_common'=>0],
    ['import_index'=>1,'root_package'=>'Stock','relative_object_path'=>'Deterministic','outer_index'=>-1,'is_common'=>0],
], 'k:stock', 'UE4', [], [0=>true]);
$checks['metadata_unresolved_import_does_not_poison_provider_completeness'] = $required === [1];
$checks['compact_code_remains_distinct'] = CompactDependencyEncoding::codes('unresolved') === [4,4,0];
$checks['read_label_remains_distinct'] = CatalogCompactDependencyReadService::statusLabel(4) === 'unresolved';

$failed = [];
foreach ($checks as $name => $ok) {
    fwrite(STDOUT, ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL);
    if (!$ok) {
        $failed[] = $name;
    }
}
fwrite(STDOUT, sprintf("Result: %d/%d checks passed.\n", count($checks)-count($failed), count($checks)));
if ($failed !== []) {
    fwrite(STDERR, 'Failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
exit(0);
