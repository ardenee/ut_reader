#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe3VerifyImportImpactQuery;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut3SnapshotBuilder;
if(!extension_loaded('pdo_sqlite')){fwrite(STDERR,"pdo_sqlite required\n");exit(2);}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,engine_key TEXT,is_active INTEGER)');
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,slug TEXT,profile_id INTEGER)');
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,scan_status TEXT,package_version INTEGER,licensee_version INTEGER)');
$db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY,game_id INTEGER,source_policy TEXT)');
$db->exec('CREATE TABLE ue_uedb5_dependency_edges(file_id INTEGER,source_kind INTEGER,required_object_key BLOB,outcome INTEGER)');
$db->exec("INSERT INTO ue_game_profiles VALUES(1,'UE3',1),(2,'UE2',1)");
$db->exec("INSERT INTO ue_games VALUES(6,'ut3',1),(5,'ut2004',2)");
$rows=[
 [1,6,512,0,Uedb5Ut3SnapshotBuilder::SOURCE_POLICY,1,0],
 [2,6,512,0,Uedb5Ut3SnapshotBuilder::SOURCE_POLICY,1,1],
 [3,6,512,0,Uedb5Ut3SnapshotBuilder::SOURCE_POLICY,0,0],
 [4,6,513,0,Uedb5Ut3SnapshotBuilder::SOURCE_POLICY,1,1],
 [5,6,512,1,Uedb5Ut3SnapshotBuilder::SOURCE_POLICY,1,1],
 [6,5,129,29,'ue2-ut2004-ut2004src-v129-64bit',1,0],
];
foreach($rows as[$id,$gid,$ver,$lic,$policy,$object,$outcome]){
 $db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,?)')->execute([$id,$gid,'verified',$ver,$lic]);
 $db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)')->execute([$id,$gid,$policy]);
 if($object)$db->prepare('INSERT INTO ue_uedb5_dependency_edges VALUES(?,?,?,?)')->execute([$id,1,"k",$outcome]);
 else $db->prepare('INSERT INTO ue_uedb5_dependency_edges VALUES(?,?,NULL,2)')->execute([$id,1]);
}
$r=(new PdoUe3VerifyImportImpactQuery($db))->run([1,2,3,4,5,6]);$why=$r['reasons_by_file'];
$checks=[];$fail=[];$check=static function(string$n,bool$ok)use(&$checks,&$fail){$checks[$n]=$ok;if(!$ok)$fail[]=$n;};
$check('ut3_v512_missing_object_edge_rebuilds',isset($why[1]['ue3_ut3_verifyimport_outcome_change']));
$check('ut3_v512_resolved_public_edge_rolls_forward',!isset($why[2]));
$check('ut3_package_only_rolls_forward',!isset($why[3]));
$check('ut3_v513_does_not_inherit_v512',isset($why[4]['ue3_ut3_source_implementation_unavailable']));
$check('ut3_licensee_nonzero_does_not_inherit_v512',isset($why[5]['ue3_ut3_source_implementation_unavailable']));
$check('ue2_is_outside_ut3_transition',!isset($why[6]));
$check('impact_count_is_exact',(int)$r['total']===3);
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail,'impact'=>$r],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit($fail===[]?0:1);
