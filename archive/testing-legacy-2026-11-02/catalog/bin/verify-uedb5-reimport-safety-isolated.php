<?php
declare(strict_types=1);
/** Bounded negative-path lifecycle contract on isolated SQLite; never invoke destructive reimport. */
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceActionService;
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,package_name TEXT,md5 TEXT,package_guid TEXT,original_name TEXT,relative_path TEXT,source_relative_path TEXT,stored_name TEXT,scan_status TEXT,uploaded_at TEXT)');
$db->exec("INSERT INTO ue_files VALUES(321,7,'Fixture','samplehash','','Fixture.uasset','games/ut4/verified/no-such-source.uasset','','no-such-source.uasset','verified','2026-10-15')");
$db->exec("INSERT INTO ue_files VALUES(322,7,'Replacement','newhash','','Replacement.uasset','games/ut4/verified/no-such-source-two.uasset','','no-such-source-two.uasset','verified','2026-10-15')");
$storage=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uedb5-lifecycle-empty-'.bin2hex(random_bytes(8));
mkdir($storage);
$config=['storage_path'=>$storage];
$service=new CatalogFileMaintenanceActionService($db,$config,null);
$reflection=new ReflectionMethod(CatalogFileMaintenanceActionService::class,'reimportWithIdentityRefresh');
$checks=[];$errors=[];
try{
 $before=(int)$db->query('SELECT COUNT(*) FROM ue_files')->fetchColumn();
 try{
  $reflection->invoke($service,321,'sync_reimport',['file_id'=>321]);
  $checks['missing_source_reimport_rejected']=false;
 }catch(RuntimeException $e){
  $checks['missing_source_reimport_rejected']=str_contains($e->getMessage(),'Stored package is missing for verified file #321');
  $checks['missing_source_has_restore_guidance']=str_contains($e->getMessage(),'Catalog record preserved; restore the package or remove it explicitly.');
 }
 $checks['missing_source_keeps_catalog_row']=(int)$db->query('SELECT COUNT(*) FROM ue_files')->fetchColumn()===$before;
 $replacement=$reflection->invoke($service,999999,'sync_reimport',
   ['game_id'=>7,'package_name'=>'Replacement','md5'=>'newhash','package_guid'=>'']);
 $checks['stale_id_resolves_existing_identity']=($replacement['status']??'')==='stale_replaced'
  && ($replacement['file_id']??0)===322;
 $checks['stale_id_does_not_rewrite_record']=(int)$db->query('SELECT COUNT(*) FROM ue_files')->fetchColumn()===$before;
 try{$reflection->invoke($service,999998,'sync_reimport',['file_id'=>999998]);$checks['missing_replacement_rejected']=false;}
 catch(RuntimeException $e){$checks['missing_replacement_rejected']=str_contains($e->getMessage(),'File no longer exists');}
 try{$service->execute('sync_game',['file_id'=>321]);$checks['retired_full_sync_route_rejected']=false;}
 catch(RuntimeException $e){$checks['retired_full_sync_route_rejected']=str_contains($e->getMessage(),'short package-by-package');}
 foreach([0,-1,'nonsense'] as $invalid){
  try{$service->execute('sync_reimport',['file_id'=>$invalid]);$checks['invalid_id_'.(string)$invalid]=false;}
  catch(RuntimeException $e){$checks['invalid_id_'.(string)$invalid]=str_contains($e->getMessage(),'valid file ID');}
 }
}catch(Throwable $e){$errors[]=$e->getMessage();}
finally{@rmdir($storage);}
$failures=array_keys(array_filter($checks,static fn(bool $value):bool=>!$value));
$ok=$errors===[]&&$failures===[]&&count($checks)===10;
echo json_encode(['ok'=>$ok,'sqlite_memory_only'=>true,'checks'=>$checks,'errors'=>$errors,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
