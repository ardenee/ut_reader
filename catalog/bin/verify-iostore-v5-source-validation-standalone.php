<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MigrationValidator;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenIoStoreSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$ident=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$ident['port']!==3339||stripos((string)$ident['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Wrong DB');
$root='C:/Temp/uedb5-iostore-retention-test';
$hash=$db->query('SELECT utoc_sha256 FROM ue_iostore_containers WHERE id=1')->fetchColumn();
$toc=new Uedb5IoStoreTocReader($root.'/games/unreal2/iostore/'.$hash.'/FactoryGame-WindowsServer.utoc',null,'C:/php8.5/ext/oo2core_9_win64.dll');
$results=[];
foreach([50,51] as $entry){
 $q=$db->prepare('SELECT m.file_id,HEX(m.package_id) pkg,f.package_name,f.original_name FROM ue_iostore_packages m JOIN ue_files f ON f.id=m.file_id WHERE m.container_id=1 AND m.toc_index=?');
 $q->execute([$entry]);$item=$q->fetch(PDO::FETCH_ASSOC);
 $source=Uedb5Ue5ZenIoStoreSnapshotBuilder::build($toc,$item['pkg'],[
  'id'=>(int)$item['file_id'],'game_id'=>2,
  'package_name'=>$item['package_name'],'original_name'=>$item['original_name']]);
 try{
  $result=(new Uedb5MigrationValidator($db,['storage_path'=>$root]))->validate((int)$item['file_id'],$source);
  $results[]=['entry'=>$entry,'file_id'=>$item['file_id'],'ok'=>true,'ready'=>$result['ready'],'dependency'=>$result['dependency']];
 }catch(Throwable $e){$results[]=['entry'=>$entry,'file_id'=>$item['file_id'],'ok'=>false,'error'=>$e->getMessage()];}
}
$ok=count(array_filter($results,static fn($x)=>$x['ok']))===2;
echo json_encode(['ok'=>$ok,'isolated'=>true,'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
