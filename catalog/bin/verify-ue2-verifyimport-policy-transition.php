<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe2VerifyImportImpactQuery;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Unreal2SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut2003SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut2004SnapshotBuilder;

$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,package_version INTEGER,licensee_version INTEGER,scan_status TEXT)');
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,profile_id INTEGER)');
$db->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,engine_key TEXT,is_active INTEGER)');
$db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY,game_id INTEGER,source_policy TEXT)');
$db->exec('CREATE TABLE ue_uedb5_dependency_edges(file_id INTEGER,source_kind INTEGER,outcome INTEGER,required_object_key BLOB)');
$db->exec("INSERT INTO ue_game_profiles VALUES(1,'UE2',1),(2,'UE1',1)");
$db->exec('INSERT INTO ue_games VALUES(6,1),(4,1),(5,1),(3,2)');
$rows=[
 [1,6,69,0,'ue2-unreal2-package-v126',1],
 [2,6,126,29,Uedb5Unreal2SnapshotBuilder::POLICY_V126_GENERIC,1],
 [3,6,69,0,Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000,0],
 [4,4,120,28,Uedb5Ut2003SnapshotBuilder::SOURCE_POLICY,1],
 [5,4,121,29,Uedb5Ut2003SnapshotBuilder::POLICY_POST_V120_UNRESOLVED,1],
 [6,5,128,29,Uedb5Ut2004SnapshotBuilder::POLICY_V128,1],
 [7,3,68,0,'ue1-ut99-retail-v1400-1999-11-30',1],
 [8,5,129,29,Uedb5Ut2004SnapshotBuilder::POLICY_V129,1],
 [9,5,130,29,Uedb5Ut2004SnapshotBuilder::POLICY_POST_V129_UNRESOLVED,1],
 [10,5,128,29,Uedb5Ut2004SnapshotBuilder::POLICY_V128,0],
];
$if=$db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,"verified")');$iv=$db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)');$ie=$db->prepare('INSERT INTO ue_uedb5_dependency_edges VALUES(?,1,1,?)');
foreach($rows as[$id,$gid,$ver,$lic,$policy,$object]){$if->execute([$id,$gid,$ver,$lic]);$iv->execute([$id,$gid,$policy]);if($object)$ie->execute([$id,random_bytes(16)]);}
$r=(new PdoUe2VerifyImportImpactQuery($db))->run([1,2,3,4,5,6,7,8,9,10]);$why=(array)$r['reasons_by_file'];
$checks=[];$fail=[];$check=static function(string$n,bool$ok)use(&$checks,&$fail){$checks[$n]=$ok;if(!$ok)$fail[]=$n;};
$check('unreal2_v69_old_policy_requires_exact_pass1_restage',isset($why[1]['ue2_unreal2_v69_pass1_reparse']));
$check('unreal2_v69_object_semantics_are_rebuilt',isset($why[1]['ue2_verifyimport_profile_change']));
$check('unreal2_v126_does_not_inherit_v69',isset($why[2]['ue2_source_implementation_unavailable']));
$check('already_retagged_unreal2_v69_without_object_edges_rolls_forward',!isset($why[3]));
$check('ut2003_v120_uses_source_profile',isset($why[4]['ue2_verifyimport_profile_change']));
$check('ut2003_v121_does_not_inherit_v2107',isset($why[5]['ue2_source_implementation_unavailable']));
$check('ut2004_v128_object_semantics_are_rebuilt',isset($why[6]['ue2_ut2004_verifyimport_profile_change']));
$check('ue1_is_outside_ue2_transition',!isset($why[7]));
$check('ut2004_v129_object_semantics_are_rebuilt',isset($why[8]['ue2_ut2004_verifyimport_profile_change']));
$check('ut2004_v130_does_not_inherit_v129',isset($why[9]['ue2_ut2004_source_implementation_unavailable']));
$check('ut2004_package_only_file_rolls_forward',!isset($why[10]));
$check('impact_count_is_exact',(int)$r['total']===7);
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail,'impact'=>$r],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit($fail===[]?0:1);