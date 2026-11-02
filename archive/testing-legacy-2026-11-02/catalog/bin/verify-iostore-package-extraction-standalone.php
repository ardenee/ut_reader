<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreIndexedPackageReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos($i['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Wrong MySQL instance');
$root='C:/Temp/uedb5-iostore-retention-test';
$lib='C:/php8.5/ext/oo2core_9_win64.dll';
$reader=new CatalogIoStoreIndexedPackageReader($db,$root,$lib);
$source='//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/FactoryGame-WindowsServer.utoc';
$toc=new Uedb5IoStoreTocReader($source,null,$lib);
$results=[];
foreach([0,1,2,3,4] as $entry){
 $out=$reader->extract(1,$entry);
 $original=$toc->readChunk($entry);
 $results[]=[
  'entry'=>$entry,
  'package_id'=>$out['package_id'],
  'bytes'=>strlen($out['bytes']),
  'matches_original_sha256'=>hash_equals(hash('sha256',$original),hash('sha256',$out['bytes']))
 ];
}
$limitReject=false;
try{$reader->extract(1,0,100);}catch(RuntimeException $e){$limitReject=str_contains($e->getMessage(),'size limit');}
$ok=$limitReject&&count($results)===5&&count(array_filter($results,static fn($row)=>$row['matches_original_sha256']))===5;
echo json_encode(['ok'=>$ok,'isolated'=>true,'limit_rejected'=>$limitReject,'results'=>$results],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
