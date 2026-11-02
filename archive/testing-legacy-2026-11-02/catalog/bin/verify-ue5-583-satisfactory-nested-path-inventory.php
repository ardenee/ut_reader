<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ParityV5ReadService;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$v=$db->query('SELECT @@port p,@@datadir d')->fetch();if((int)$v['p']!==3339||stripos((string)$v['d'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Unsafe database');
$root='C:/Temp/uedb5-iostore-retention-test';$reader=new Uedb5MetadataReader($root);
$dep=new Uedb5ParityV5ReadService($db,['storage_path'=>$root]);
$q=$db->query('SELECT id FROM ue_files WHERE game_id=2 ORDER BY id');
$seen=0;$expNested=0;$impNested=0;$expSamples=[];$impSamples=[];$errors=[];
while($row=$q->fetch(PDO::FETCH_ASSOC)){
 $id=(int)$row['id'];$seen++;
 try{
 $snapshot=$reader->snapshot(2,$id);
 foreach(Uedb5ClassicDependencyResolver::exportCoverageRows($snapshot) as $e){
  $path=(string)($e['local_path']??'');
  if(str_contains($path,'.')){$expNested++;if(count($expSamples)<15)$expSamples[]=['file_id'=>$id,'package'=>$snapshot['file']['package_name']??'','path'=>$path,'object'=>$e['object_name']??''];}
 }
 foreach($dep->dependencies(2,$id) as $e){
  $path=(string)($e['required_object_path']??'');
  $pkg=(string)($e['required_package']??'');
  $rel=$path;
  if($pkg!==''&&str_starts_with($path,$pkg.'.'))$rel=substr($path,strlen($pkg)+1);
  if(str_contains($rel,'.')){$impNested++;if(count($impSamples)<15)$impSamples[]=['file_id'=>$id,'path'=>$path,'relative'=>$rel,'required_package'=>$pkg];}
 }
 }catch(Throwable $e){if(count($errors)<20)$errors[]=['file_id'=>$id,'error'=>$e->getMessage()];}
 $reader->clearCache(2,$id);
 if($seen%2000===0){echo json_encode(['stage'=>'progress','scanned'=>$seen,'nested_exports'=>$expNested,'nested_imports'=>$impNested,'errors'=>count($errors)]),PHP_EOL;flush();}
}
echo json_encode(['stage'=>'final','scanned'=>$seen,'nested_exports'=>$expNested,'nested_imports'=>$impNested,'export_samples'=>$expSamples,'import_samples'=>$impSamples,'errors'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
