#!/usr/bin/env php
<?php
declare(strict_types=1);

$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut4SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportImpactQuery;

if(!extension_loaded('pdo_sqlite')){fwrite(STDERR,"pdo_sqlite is required.\n");exit(2);}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,engine_key TEXT,is_active INTEGER)');
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,profile_id INTEGER)');
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,scan_status TEXT,package_version INTEGER,licensee_version INTEGER)');
$db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY,game_id INTEGER,source_policy TEXT)');
$db->exec('CREATE TABLE ue_uedb5_dependency_edges(file_id INTEGER,source_kind INTEGER,source_index INTEGER,outcome INTEGER,required_object_key BLOB,resolved_file_id INTEGER,resolved_object_index INTEGER)');
$db->exec('CREATE TABLE ue_export_path_lookup(file_id INTEGER,export_index INTEGER,object_flags INTEGER)');
$db->exec("INSERT INTO ue_game_profiles VALUES(1,'UE4',1),(2,'UE3',1)");
$db->exec('INSERT INTO ue_games VALUES(7,1),(6,2)');
$files=[
 [1,7,'ue4-4.27.2-release-classic-package',508,0],[2,7,Uedb5Ut4SnapshotBuilder::SOURCE_POLICY,508,0],
 [3,7,Uedb5Ut4SnapshotBuilder::SOURCE_POLICY,508,0],[4,7,Uedb5Ut4SnapshotBuilder::SOURCE_POLICY,508,0],
 [5,7,Uedb5Ut4SnapshotBuilder::SOURCE_POLICY,508,0],[6,7,'ue4-other-source-policy',508,0],
 [7,6,'ue3-ut3-jan2008-package-v512',512,0],[8,7,'ue4-4.27.2-release-classic-package',510,0],
 [9,7,'ue4-4.27.2-release-classic-package',508,0],[10,7,'ue4-4.27.2-release-classic-package',522,0]
];
$insF=$db->prepare('INSERT INTO ue_files VALUES(?,?,"verified",?,?)');$insV=$db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)');
foreach($files as[$id,$game,$policy,$version,$licensee]){$insF->execute([$id,$game,$version,$licensee]);$insV->execute([$id,$game,$policy]);}
$edge=$db->prepare('INSERT INTO ue_uedb5_dependency_edges VALUES(?,?,?,?,?,?,?)');
$objectKey='x';
$edge->execute([1,1,0,Uedb5SqlProjectionContract::OUTCOME_MISSING,$objectKey,null,null]);
$edge->execute([2,1,0,Uedb5SqlProjectionContract::OUTCOME_UNRESOLVED,$objectKey,null,null]);
$edge->execute([3,1,0,Uedb5SqlProjectionContract::OUTCOME_RESOLVED,$objectKey,101,0]);
$edge->execute([4,1,0,Uedb5SqlProjectionContract::OUTCOME_RESOLVED,$objectKey,102,0]);
$edge->execute([5,1,0,Uedb5SqlProjectionContract::OUTCOME_PACKAGE_ONLY,null,103,null]);
$edge->execute([6,1,0,Uedb5SqlProjectionContract::OUTCOME_RESOLVED,$objectKey,104,0]);
$edge->execute([7,1,0,Uedb5SqlProjectionContract::OUTCOME_MISSING,$objectKey,null,null]);
$db->exec('INSERT INTO ue_export_path_lookup VALUES(101,0,1),(102,0,0),(104,0,1)');
$out=(new PdoUe4VerifyImportImpactQuery($db))->run([1,2,3,4,5,6,7,8,9,10]);
$r=$out['reasons_by_file'];$checks=[];
$checks['legacy_ut4_missing_edge_requires_policy_refresh_and_rebuild']=isset($r[1]['ue4_ut4_source_policy_refresh_required'])
    && isset($r[1]['ue4_ut4_verifyimport_outcome_change']);
$checks['ut4_unresolved_object_edge_rebuilds']=isset($r[2]['ue4_ut4_verifyimport_outcome_change']);
$checks['ut4_resolved_public_edge_rolls_forward']=!isset($r[3]);
$checks['ut4_resolved_private_edge_rebuilds']=isset($r[4]['ue4_ut4_verifyimport_outcome_change']);
$checks['ut4_package_only_rolls_forward']=!isset($r[5]);
$checks['unprofiled_ue4_object_edge_fails_closed']=isset($r[6]['ue4_source_implementation_unavailable']);
$checks['ue3_is_outside_ue4_transition']=!isset($r[7]);
$checks['legacy_reader_gate_requires_pass1']=isset($r[8]['ue4_ut4_reader_gate_pass1_reparse']);
$checks['legacy_non_gate_without_object_edges_still_requires_policy_refresh']=isset($r[9]['ue4_ut4_source_policy_refresh_required']);
$checks['legacy_above_clean_master_requires_profile_review']=isset($r[10]['ue4_ut4_source_profile_review_required']);
$checks['impact_count_is_exact']=(int)$out['total']===7;
$failures=array_keys(array_filter($checks,static fn(bool$v):bool=>!$v));
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures,'impact'=>$out],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failures===[]?0:1);
