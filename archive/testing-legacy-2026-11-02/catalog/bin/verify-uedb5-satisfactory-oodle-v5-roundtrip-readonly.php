<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenIoStoreSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataStagingReader;
$utoc='//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/FactoryGame-WindowsServer.utoc';
$dll='C:/php8.5/ext/oo2core_9_win64.dll';
if(!is_file($utoc)||!is_file($dll))throw new RuntimeException('Missing read-only source or decoder');
$toc=new Uedb5IoStoreTocReader($utoc,null,$dll);
$indexes=array_slice($toc->chunkIndexesByType(Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA),0,12);
$dir=sys_get_temp_dir().'/uedb5_satisfactory_roundtrip_'.bin2hex(random_bytes(8));
if(!mkdir($dir,0700,true))throw new RuntimeException('Could not create isolated scratch');
$pass=[];$failed=[];
try {
 foreach($indexes as $idx){
  $fileId=500000+$idx;
  $chunk=$toc->chunks()[$idx];
  $name=$chunk['package_id'];
  $path=$dir.'/'.$fileId.'.uedb5';
  try{
   $snapshot=Uedb5Ue5ZenIoStoreSnapshotBuilder::build($toc,$name,[
    'id'=>$fileId,'game_id'=>999999,'package_name'=>'','original_name'=>'Satisfactory.utoc']);
   $out=Uedb5MetadataContainer::buildToFile($snapshot,$path,2);
   $check=Uedb5MetadataContainer::verifyFile($path,$fileId);
   $reader=new Uedb5MetadataStagingReader($path,$fileId);
   $orig=(string)$snapshot['sections']['package_summary'][0]['package_name_text'];
   $stored=$reader->page('package_summary',0,1);
   $storedName=(string)($stored[0]['package_name_text']??'');
   if($orig!==$storedName||$reader->count('imports')!==count($snapshot['sections']['imports'])
    ||$reader->count('exports')!==count($snapshot['sections']['exports'])
    ||$reader->count('dependency_bundle_entries')!==count($snapshot['sections']['dependency_bundle_entries'])) {
    throw new RuntimeException('V5 snapshot section round-trip mismatch');
   }
   $pass[]=['toc_index'=>$idx,'package'=>$orig,'imports'=>$reader->count('imports'),
    'exports'=>$reader->count('exports'),'dependencies'=>$reader->count('dependency_bundle_entries'),
    'v5_bytes'=>filesize($path)];
  }catch(Throwable $e){$failed[]=['toc_index'=>$idx,'error'=>$e->getMessage()];}
  finally{if(is_file($path))@unlink($path);}
 }
}finally{@rmdir($dir);}
$ok=count($pass)===12&&$failed===[];
echo json_encode(['ok'=>$ok,'source_read_only'=>true,'temporary_v5_only'=>true,'sampled'=>count($indexes),
 'passed'=>$pass,'failed'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
