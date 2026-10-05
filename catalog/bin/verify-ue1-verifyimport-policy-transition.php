#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe1VerifyImportImpactQuery;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5UnrealSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut99SnapshotBuilder;

$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,package_version INTEGER,licensee_version INTEGER,scan_status TEXT)');
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,profile_id INTEGER)');
$db->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,engine_key TEXT,is_active INTEGER)');
$db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY,game_id INTEGER,source_policy TEXT)');
$db->exec('CREATE TABLE ue_uedb5_dependency_edges(file_id INTEGER,source_kind INTEGER,outcome INTEGER)');
$db->exec('CREATE TABLE ue_terms(id INTEGER PRIMARY KEY,value_length INTEGER,value_prefix TEXT)');
$db->exec('CREATE TABLE ue_dependency_links(file_id INTEGER,import_class_name_term_id INTEGER)');
$db->exec("INSERT INTO ue_game_profiles VALUES(1,'UE1',1),(2,'UE2',1)");
$db->exec('INSERT INTO ue_games VALUES(3,1),(12,1),(5,2)');
$files=[
 [1,3,68,0,Uedb5Ut99SnapshotBuilder::POLICY_RETAIL],
 [2,3,68,0,Uedb5Ut99SnapshotBuilder::POLICY_RETAIL],
 [3,3,69,0,Uedb5Ut99SnapshotBuilder::POLICY_SUPPLEMENTAL],
 [4,12,49,0,Uedb5UnrealSnapshotBuilder::POLICY_V120_EARLY],
 [5,5,128,0,'ue2-ut2004-v3369-package-v128'],
 [6,3,68,0,Uedb5Ut99SnapshotBuilder::POLICY_RETAIL],
];
$if=$db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,"verified")');$iv=$db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)');
foreach($files as[$id,$gid,$ver,$lic,$policy]){$if->execute([$id,$gid,$ver,$lic]);$iv->execute([$id,$gid,$policy]);}
$db->exec('INSERT INTO ue_uedb5_dependency_edges VALUES(1,1,0),(2,1,1),(3,1,1),(5,1,0),(6,1,1)');
$db->exec("INSERT INTO ue_terms VALUES(100,4,'Mesh')");
$db->exec('INSERT INTO ue_dependency_links VALUES(2,100)');
$r=(new PdoUe1VerifyImportImpactQuery($db))->run([1,2,3,4,5,6]);$why=(array)$r['reasons_by_file'];
$checks=[];$fail=[];$check=static function(string$n,bool$ok)use(&$checks,&$fail){$checks[$n]=$ok;if(!$ok)$fail[]=$n;};
$check('audited_missing_or_unresolved_is_rebuilt',isset($why[1]['ue1_verifyimport_outcome_change']));
$check('resolved_ut99_mesh_is_rebuilt_for_unconditional_rehack',isset($why[2]['ut99_mesh_rehack']));
$check('audited_nonmesh_resolved_rolls_forward',!isset($why[6]));
$check('later_ut99_does_not_inherit_v1400',isset($why[3]['ue1_source_implementation_unavailable']));
$check('pre50_unreal_requires_pass1_reparse',isset($why[4]['ue1_pre50_pass1_reparse']));
$check('ue2_is_outside_ue1_transition',!isset($why[5]));
$check('impact_count_is_exact',(int)$r['total']===4);
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail,'impact'=>$r],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit($fail===[]?0:1);
