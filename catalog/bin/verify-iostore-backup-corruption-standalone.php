<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerBackupService;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos($i['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Wrong DB');
$original='C:/Temp/uedb5-iostore-backup-test/iostore-0f72c200169b09519ac727d0149ef4f3c35d6234f1326c7efe5ee0bcf04095e6';
$test='C:/Temp/uedb5-iostore-backup-corrupt-fixture';
if(!is_dir($test))mkdir($test,0700,true);
foreach(['manifest.json','global.utoc','global.ucas'] as $name){
 if(!copy($original.'/'.$name,$test.'/'.$name))throw new RuntimeException('Could not stage isolated corruption test.');
}
$file=$test.'/global.ucas';$fp=fopen($file,'r+b');fseek($fp,0);$b=fread($fp,1);rewind($fp);fwrite($fp,chr(ord($b)^1));fclose($fp);
$rejected=false;$why='';
try{(new CatalogIoStoreContainerBackupService($db,'C:/Temp/uedb5-iostore-restored-test'))
  ->restore($test,9002,'satisfactoryrestore');
}catch(Throwable $e){$why=$e->getMessage();$rejected=str_contains($why,'changed');}
$q=$db->query('SELECT COUNT(*) FROM ue_iostore_containers WHERE game_id=9002');
$count=(int)$q->fetchColumn();
$ok=$rejected&&$count===2;
echo json_encode(['ok'=>$ok,'isolated'=>true,'tampered_ucas_rejected'=>$rejected,'reason'=>$why,'unchanged_target_containers'=>$count],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
