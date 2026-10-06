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
$db->exec("INSERT INTO ue_game_profiles VALUES(1,'UE3',1,'UT3 January 2008','source policy')");
$db->exec("INSERT INTO ue_games VALUES(6,'Unreal Tournament 3','ut3',1)");
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,scan_status TEXT,file_size INTEGER,md5 TEXT,sha1 TEXT,package_name TEXT,uploaded_at TEXT,package_version INTEGER,licensee_version INTEGER)');
$db->exec('CREATE TABLE ue_package_providers(game_id INTEGER,package_name TEXT,file_id INTEGER,source_kind TEXT,source_id INTEGER,provider_created_at TEXT)');
$db->exec('CREATE TABLE ue_file_package_aliases(id INTEGER PRIMARY KEY,file_id INTEGER,game_id INTEGER,package_name TEXT)');
$db->exec('CREATE TABLE ue_invalid_file_identities(file_size INTEGER,md5 TEXT,sha1 TEXT)');
$db->exec("INSERT INTO ue_files VALUES(99,6,'verified',99,'m','s','Consumer','2026-10-05',512,0)");

$imports = [
    [
        'id'=>1,'import_index'=>0,'class_package'=>'Engine','class_name'=>'SoundCue',
        'object_name'=>'Leaf','outer_index'=>7,'full_path'=>'Stock.Group.Leaf',
        'root_package'=>'Stock','relative_object_path'=>'Group.Leaf','is_common'=>0,
    ],
    [
        'id'=>2,'import_index'=>1,'class_package'=>'Engine','class_name'=>'Material',
        'object_name'=>'Child','outer_index'=>-1,'full_path'=>'Stock.Group.Leaf.Child',
        'root_package'=>'Stock','relative_object_path'=>'Group.Leaf.Child','is_common'=>0,
    ],
    [
        'id'=>3,'import_index'=>2,'class_package'=>'Core','class_name'=>'Package',
        'object_name'=>'DefinitelyAbsent','outer_index'=>0,'full_path'=>'DefinitelyAbsent',
        'root_package'=>'DefinitelyAbsent','relative_object_path'=>'','is_common'=>0,
    ],
];

$resolved = PdoDependencyResolver::resolve($db, 6, 99, $imports, null);
$checks = [];
$checks['direct_export_outer_is_unresolved'] = ($resolved[1]['status'] ?? '') === 'unresolved';
$checks['descendant_of_export_outer_is_unresolved'] = ($resolved[2]['status'] ?? '') === 'unresolved';
$checks['source_label_is_explicit'] = ($resolved[1]['source'] ?? '') === 'ue3_cooked_export_outer'
    && ($resolved[1]['confidence'] ?? '') === 'source_unresolved';
$checks['ordinary_absent_package_stays_missing'] = ($resolved[3]['status'] ?? '') === 'missing';
$checks['compact_code_is_distinct'] = CompactDependencyEncoding::codes('unresolved') === [4,4,0];
$checks['read_label_is_distinct'] = CatalogCompactDependencyReadService::statusLabel(4) === 'unresolved';

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
