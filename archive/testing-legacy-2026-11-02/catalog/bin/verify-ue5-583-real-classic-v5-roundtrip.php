<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
require dirname(__DIR__,2).'/UE5/UnrealPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ClassicSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataStagingReader;
$root='H:/UE5-583-runtime-probe/Engine/Content';
$dir=sys_get_temp_dir().'/ue583-real-classic-v5-'.bin2hex(random_bytes(5));
if(!mkdir($dir))throw new RuntimeException('Unable to make disposable fixture folder');
$checked=0;$legacy=0;$errors=[];$versions=[];
try {
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
 foreach($it as $file){
  if(!in_array(strtolower($file->getExtension()),['uasset','umap'],true)||$file->getSize()>40*1024*1024)continue;
  $reader=new UnrealPackageReader5($file->getPathname());
  $issues=$reader->validatePackage();
  if(count($issues)===1&&str_contains($issues[0],'predates the UE5 summary version field.')){$legacy++;continue;}
  if($issues!==[]){$errors[]=['file'=>$file->getFilename(),'error'=>implode('; ',$issues)];continue;}
  $id=700000+$checked;
  try{
   $snap=Uedb5Ue5ClassicSnapshotBuilder::build($reader,['id'=>$id,'game_id'=>8,'package_name'=>'/Engine/Test/'.pathinfo($file->getFilename(),PATHINFO_FILENAME),'original_name'=>$file->getFilename()]);
   $path=$dir.'/'.$id.'.uedb5';
   Uedb5MetadataContainer::buildToFile($snap,$path,1);
   $staged=new Uedb5MetadataStagingReader($path,$id);
   $manifest=$staged->manifest();
   if(($manifest['package_family']??'')!==Uedb5Ue5ClassicSnapshotBuilder::PACKAGE_FAMILY)throw new RuntimeException('V5 package family missing');
   foreach($snap['sections'] as $part=>$expectedRows){
    if($staged->count($part)!==count($expectedRows))throw new RuntimeException('Section count mismatch '.$part);
    $actualRows=iterator_to_array($staged->scan($part),false);
    if($actualRows!==$expectedRows)throw new RuntimeException('Section row semantics mismatch '.$part);
   }
   $v=(string)($reader->getHeader()['ue5Version']??'');$versions[$v]=($versions[$v]??0)+1;$checked++;
   unlink($path);
  }catch(Throwable $e){if(count($errors)<20)$errors[]=['file'=>$file->getFilename(),'error'=>$e->getMessage()];}
  if($checked+$legacy>=140)break;
 }
}finally{foreach(glob($dir.'/*')?:[] as $p)unlink($p);rmdir($dir);}
$ok=$checked>0&&$errors===[];
echo json_encode(['ok'=>$ok,'real_classic_v5_roundtrips'=>$checked,'legacy_excluded'=>$legacy,'versions'=>$versions,'errors'=>$errors],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
