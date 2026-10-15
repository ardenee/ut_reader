<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Import\CatalogIoStoreMemberCatalogPublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos((string)$i['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Refusing production DB');
$path='C:/Temp/uedb5-iostore-retention-test/games/unreal2/iostore/';
$hash=$db->query('SELECT utoc_sha256 FROM ue_iostore_containers WHERE id=1')->fetchColumn();
$path.=$hash.'/FactoryGame-WindowsServer.utoc';
$toc=new Uedb5IoStoreTocReader($path,null,'C:/php8.5/ext/oo2core_9_win64.dll');
$publisher=new CatalogIoStoreMemberCatalogPublisher($db,'C:/Temp/uedb5-iostore-retention-test');
$results=[];
foreach([702,703] as $entry){
 $a=$publisher->publish($toc,1,$entry);
 $b=$publisher->publish($toc,1,$entry);
 $results[]=['entry'=>$entry,'first'=>$a,'replay'=>$b];
}
$ok=count($results)===2;
foreach($results as $r){
 $ok=$ok&&$r['replay']['status']==='existing';
 $id=(int)$r['first']['file_id'];
 $row=$db->query('SELECT relative_path,md5,sha1 FROM ue_files WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
 $physical='C:/Temp/uedb5-iostore-retention-test/'.substr((string)$row['relative_path'],strlen('storage/'));
 $ok=$ok&&str_starts_with((string)$row['relative_path'],'storage/')
  &&is_file($physical)&&md5_file($physical)===$row['md5']&&sha1_file($physical)===$row['sha1'];
}
echo json_encode(['ok'=>$ok,'isolated'=>true,'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
