<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerBackupService;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos((string)$i['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Wrong isolated MySQL');
$manifests=glob('C:/Temp/uedb5-iostore-backup-test/iostore-*/manifest.json')?:[];
if(count($manifests)!==1)throw new RuntimeException('Expected one retained backup manifest');
$original=dirname($manifests[0]);
$names=['manifest.json','FactoryGame-WindowsServer.utoc','FactoryGame-WindowsServer.ucas'];
$test='C:/Temp/uedb5-iostore-backup-corrupt-fixture-'.bin2hex(random_bytes(4));
if(!mkdir($test,0700,true))throw new RuntimeException('Cannot create isolated test directory');
$before=(int)$db->query('SELECT COUNT(*) FROM ue_iostore_containers WHERE game_id=9002')->fetchColumn();
try {
 foreach($names as $name){
  if(!copy($original.'/'.$name,$test.'/'.$name))throw new RuntimeException('Cannot copy backup fixture '.$name);
 }
 $path=$test.'/FactoryGame-WindowsServer.ucas';
 $fp=fopen($path,'r+b');if($fp===false)throw new RuntimeException('Cannot open fixture');
 try{$byte=fread($fp,1);if($byte==='')throw new RuntimeException('Empty fixture');rewind($fp);fwrite($fp,chr(ord($byte)^1));}finally{fclose($fp);}
 $rejected=false;$why='';
 try{(new CatalogIoStoreContainerBackupService($db,'C:/Temp/uedb5-iostore-restored-test'))->restore($test,9002,'satisfactoryrestore');}
 catch(Throwable $e){$why=$e->getMessage();$rejected=str_contains(strtolower($why),'changed')||str_contains(strtolower($why),'hash')||str_contains(strtolower($why),'checksum');}
 $after=(int)$db->query('SELECT COUNT(*) FROM ue_iostore_containers WHERE game_id=9002')->fetchColumn();
 $ok=$rejected&&$after===$before;
 echo json_encode(['ok'=>$ok,'isolated'=>true,'corrupt_partition_rejected'=>$rejected,'reason'=>$why,'container_count_before'=>$before,'container_count_after'=>$after],JSON_PRETTY_PRINT),PHP_EOL;
 exit($ok?0:2);
}finally{foreach($names as $name){@unlink($test.'/'.$name);}@rmdir($test);}
