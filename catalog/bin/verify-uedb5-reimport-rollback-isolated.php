<?php
declare(strict_types=1);
/** Isolated reimport relational-state capture and rollback contract (actual V5 maintenance support). */
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceSupport;
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,package_name TEXT,original_name TEXT,scan_status TEXT,md5 TEXT)');
$db->exec('CREATE TABLE ue_file_locations(id INTEGER PRIMARY KEY,file_id INTEGER,source_relative_path TEXT)');
$db->exec('CREATE TABLE related_downloads(id INTEGER PRIMARY KEY,file_id INTEGER)');
$db->exec("INSERT INTO ue_files VALUES(321,7,'Fixture','Fixture.uasset','verified','originalhash')");
$db->exec("INSERT INTO ue_file_locations VALUES(1,321,'backup/Fixture.uasset')");
$db->exec('INSERT INTO related_downloads VALUES(19,321)');
$storage=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uedb5-state-'.bin2hex(random_bytes(8));
mkdir($storage,0700,true);
$checks=[];$errors=[];
try{
 $support=new CatalogFileMaintenanceSupport($db,['storage_path'=>$storage]);
 $state=$support->reimportState(321);
 $checks['capture_format_is_authoritative']=($state['format']??'')==='unrealdb.reimport-relational-state'&&($state['format_version']??0)===1;
 $checks['capture_preserves_source_locations']=count($state['locations']??[])===1&&($state['locations'][0]['source_relative_path']??'')==='backup/Fixture.uasset';
 $db->exec("UPDATE ue_files SET package_name='Renamed',md5='changed',scan_status='failed' WHERE id=321");
 $support->restoreReimportFileRow($state);
 $row=$db->query('SELECT * FROM ue_files WHERE id=321')->fetch(PDO::FETCH_ASSOC);
 $checks['rollback_restores_verified_row']=($row['scan_status']??'')==='verified'&&($row['package_name']??'')==='Fixture'&&($row['md5']??'')==='originalhash';
 $checks['rollback_preserves_stable_identity']=(int)($row['id']??0)===321;
 $checks['rollback_preserves_other_relationships']=(int)$db->query('SELECT COUNT(*) FROM related_downloads WHERE file_id=321')->fetchColumn()===1;
 $checks['rollback_preserves_source_locations']=(int)$db->query('SELECT COUNT(*) FROM ue_file_locations WHERE file_id=321')->fetchColumn()===1;
 foreach([0,9999] as $id){
  try{$support->reimportState($id);$checks['invalid_or_missing_'.$id]=false;}
  catch(RuntimeException $e){$checks['invalid_or_missing_'.$id]=true;}
 }
 $db->exec("UPDATE ue_files SET scan_status='failed' WHERE id=321");
 try{$support->reimportState(321);$checks['unverified_source_rejected']=false;}
 catch(RuntimeException $e){$checks['unverified_source_rejected']=str_contains($e->getMessage(),'not verified');}
 $db->exec("UPDATE ue_files SET scan_status='verified' WHERE id=321");
 $db->exec('DELETE FROM ue_files WHERE id=321');
 try{$support->restoreReimportFileRow($state);$checks['deleted_identity_not_recreated']=false;}
 catch(RuntimeException $e){$checks['deleted_identity_not_recreated']=str_contains($e->getMessage(),'stable identity disappeared');}
 $checks['deleted_identity_still_absent']=(int)$db->query('SELECT COUNT(*) FROM ue_files')->fetchColumn()===0;
}catch(Throwable $e){$errors[]=$e->getMessage().' at '.$e->getFile().':'.$e->getLine();}
finally{@rmdir($storage);}
$failed=array_keys(array_filter($checks,static fn(bool $v):bool=>!$v));
$ok=$failed===[]&&$errors===[]&&count($checks)===11;
echo json_encode(['ok'=>$ok,'isolated_sqlite_only'=>true,'checks'=>$checks,'errors'=>$errors,'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
