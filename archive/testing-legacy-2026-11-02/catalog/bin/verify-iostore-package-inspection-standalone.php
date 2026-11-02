<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreIndexedPackageReader;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos($i['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Wrong SQL');
$viewer=new CatalogIoStoreIndexedPackageReader($db,'C:/Temp/uedb5-iostore-retention-test',getenv('UNREALDB_OODLE_LIBRARY') ?: 'D:/php8.5/ext/oo2core_9_win64.dll');
$rows=[];$fail=[];
foreach([0,1,2,3,4] as $idx){
 try{$rows[]=$viewer->inspect(4,$idx);}catch(Throwable $e){$fail[]=['idx'=>$idx,'error'=>$e->getMessage()];}
}
$badRejected=false;
try{$viewer->inspect(3,0);}catch(RuntimeException $e){$badRejected=str_contains($e->getMessage(),'member is missing');}
$ok=count($rows)===5&&$fail===[]&&$badRejected;
echo json_encode(['ok'=>$ok,'isolated'=>true,'count'=>count($rows),'sample'=>$rows,'failures'=>$fail,'global_has_no_packages'=>$badRejected],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
