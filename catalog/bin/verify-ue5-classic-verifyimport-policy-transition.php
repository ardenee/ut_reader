#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);require_once $root.'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ClassicSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe5ClassicVerifyImportImpactQuery;
if(!extension_loaded('pdo_sqlite')){fwrite(STDERR,"pdo_sqlite is required.\n");exit(2);} $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,engine_key TEXT,is_active INTEGER)');
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,profile_id INTEGER)');
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,scan_status TEXT)');
$db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY,game_id INTEGER,package_family TEXT,source_policy TEXT)');
$db->exec('CREATE TABLE ue_uedb5_dependency_edges(file_id INTEGER,source_kind INTEGER,source_index INTEGER,outcome INTEGER,required_object_key BLOB,resolved_file_id INTEGER)');
$db->exec("INSERT INTO ue_game_profiles VALUES(1,'UE5',1),(2,'UE4',1)");$db->exec('INSERT INTO ue_games VALUES(8,1),(7,2)');
$rows=[[1,8,Uedb5Ue5ClassicSnapshotBuilder::PACKAGE_FAMILY,Uedb5Ue5ClassicSnapshotBuilder::SOURCE_POLICY],[2,8,Uedb5Ue5ClassicSnapshotBuilder::PACKAGE_FAMILY,Uedb5Ue5ClassicSnapshotBuilder::SOURCE_POLICY],[3,8,Uedb5Ue5ClassicSnapshotBuilder::PACKAGE_FAMILY,Uedb5Ue5ClassicSnapshotBuilder::SOURCE_POLICY],[4,8,Uedb5Ue5ClassicSnapshotBuilder::PACKAGE_FAMILY,'ue5-other'],[5,8,'zen-iostore',Uedb5Ue5ClassicSnapshotBuilder::SOURCE_POLICY],[6,7,'ue4-classic-package','ue4-4.27.2-release-classic-package']];
$f=$db->prepare('INSERT INTO ue_files VALUES(?,?,"verified")');$v=$db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?,?)');foreach($rows as[$id,$game,$family,$policy]){$f->execute([$id,$game]);$v->execute([$id,$game,$family,$policy]);}
$e=$db->prepare('INSERT INTO ue_uedb5_dependency_edges VALUES(?,?,?,?,?,?)');$k='x';
$e->execute([1,1,0,Uedb5SqlProjectionContract::OUTCOME_MISSING,$k,null]);
$e->execute([2,1,0,Uedb5SqlProjectionContract::OUTCOME_RESOLVED,$k,99]);
$e->execute([3,1,0,Uedb5SqlProjectionContract::OUTCOME_COMMON,$k,null]);
$e->execute([4,1,0,Uedb5SqlProjectionContract::OUTCOME_RESOLVED,$k,99]);
$e->execute([5,1,0,Uedb5SqlProjectionContract::OUTCOME_MISSING,$k,null]);
$e->execute([6,1,0,Uedb5SqlProjectionContract::OUTCOME_MISSING,$k,null]);
$out=(new PdoUe5ClassicVerifyImportImpactQuery($db))->run([1,2,3,4,5,6]);$r=$out['reasons_by_file'];$checks=[];
$checks['missing_object_edge_rebuilds']=isset($r[1]['ue5_classic_verifyimport_outcome_change']);
$checks['resolved_provider_edge_rechecks_private_package_access']=isset($r[2]['ue5_classic_private_package_access_recheck']);
$checks['common_only_row_rolls_forward']=!isset($r[3]);
$checks['wrong_source_policy_fails_closed']=isset($r[4]['ue5_classic_source_implementation_unavailable']);
$checks['zen_is_outside_classic_transition']=!isset($r[5]);
$checks['ue4_is_outside_ue5_transition']=!isset($r[6]);
$checks['impact_count_exact']=(int)$out['total']===3;
$fail=array_keys(array_filter($checks,static fn(bool$v):bool=>!$v));echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail,'impact'=>$out],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit($fail===[]?0:1);
