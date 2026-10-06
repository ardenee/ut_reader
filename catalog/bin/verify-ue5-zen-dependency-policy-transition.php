#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);require_once $root.'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe5ZenDependencyImpactQuery;
if(!extension_loaded('pdo_sqlite')){fwrite(STDERR,"pdo_sqlite is required.\n");exit(2);} $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,engine_key TEXT,is_active INTEGER)');
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,profile_id INTEGER)');
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,scan_status TEXT)');
$db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY,game_id INTEGER,package_family TEXT,source_policy TEXT)');
$db->exec('CREATE TABLE ue_uedb5_dependency_edges(file_id INTEGER,source_kind INTEGER,source_index INTEGER,required_package_key_kind INTEGER,required_package_key BLOB)');
$db->exec("INSERT INTO ue_game_profiles VALUES(1,'UE5',1),(2,'UE4',1)");$db->exec('INSERT INTO ue_games VALUES(8,1),(7,2)');
$zen=Uedb5ZenPackageReader::PACKAGE_FAMILY;$policy=Uedb5ZenPackageReader::SOURCE_POLICY;
$rows=[[1,8,$zen,$policy],[2,8,$zen,$policy],[3,8,$zen,$policy],[4,8,$zen,$policy],[5,8,$zen,$policy],[6,8,'classic-linkerload','ue5-5.8.3-classic-linkerload'],[7,8,$zen,'ue5-other'],[8,7,$zen,$policy]];
$f=$db->prepare('INSERT INTO ue_files VALUES(?,?,"verified")');$v=$db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?,?)');foreach($rows as[$id,$game,$family,$sp]){$f->execute([$id,$game]);$v->execute([$id,$game,$family,$sp]);}
$e=$db->prepare('INSERT INTO ue_uedb5_dependency_edges VALUES(?,?,?,?,?)');$k=hex2bin('0011223344556677');$z=Uedb5SqlProjectionContract::PACKAGE_KEY_ZEN_PACKAGE_ID;
$e->execute([1,Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT,0,$z,$k]);
$e->execute([2,Uedb5SqlProjectionContract::DEP_SOURCE_CELL_IMPORT,0,$z,$k]);
$e->execute([3,Uedb5SqlProjectionContract::DEP_SOURCE_LOAD_ORDER,0,$z,$k]);
$e->execute([4,Uedb5SqlProjectionContract::DEP_SOURCE_SOFT_PACKAGE,0,$z,$k]);
$e->execute([5,Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT,0,null,null]);
$e->execute([6,Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT,0,$z,$k]);
$e->execute([7,Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT,0,$z,$k]);
$e->execute([8,Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT,0,$z,$k]);
$out=(new PdoUe5ZenDependencyImpactQuery($db))->run(range(1,8));$r=$out['reasons_by_file'];$checks=[];
$checks['package_import_rebuilds']=isset($r[1]['ue5_zen_dependency_v3_outcome_change']);
$checks['cell_import_rebuilds']=isset($r[2]['ue5_zen_dependency_v3_outcome_change']);
$checks['package_import_load_order_rebuilds']=isset($r[3]['ue5_zen_dependency_v3_outcome_change']);
$checks['soft_reference_rolls_forward']=!isset($r[4]);
$checks['script_import_without_package_key_rolls_forward']=!isset($r[5]);
$checks['classic_ue5_is_outside_zen_transition']=!isset($r[6]);
$checks['wrong_zen_source_policy_fails_closed']=isset($r[7]['ue5_zen_source_implementation_unavailable']);
$checks['ue4_is_outside_zen_transition']=!isset($r[8]);
$checks['impact_count_exact']=(int)$out['total']===4;
$fail=array_keys(array_filter($checks,static fn(bool$v):bool=>!$v));echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail,'impact'=>$out],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit($fail===[]?0:1);
