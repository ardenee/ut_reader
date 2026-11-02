<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenIoStoreSnapshotBuilder;
$utoc='//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/FactoryGame-WindowsServer.utoc';
$dll='C:/php8.5/ext/oo2core_9_win64.dll';
if(!is_file($utoc)||!is_file($dll))throw new RuntimeException('Required read-only source or Oodle DLL is unavailable');
$toc=new Uedb5IoStoreTocReader($utoc,null,$dll);
$indexes=$toc->chunkIndexesByType(Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA);
$sample=array_slice($indexes,0,12);
$success=[];$failures=[];
foreach($sample as $idx){
 $chunk=$toc->chunks()[$idx];$id=$chunk['package_id'];
 try {
  $snapshot=Uedb5Ue5ZenIoStoreSnapshotBuilder::build($toc,$id,[
   'id'=>10000000+$idx,'game_id'=>999999,'package_name'=>'','original_name'=>'read-only']);
  $success[]=['toc_index'=>$idx,'package_id'=>$id,'package_name'=>$snapshot['sections']['package_summary'][0]['package_name_text']??'',
   'imports'=>count($snapshot['sections']['imports']??[]),'exports'=>count($snapshot['sections']['exports']??[]),
   'dependencies'=>count($snapshot['sections']['dependency_bundle_entries']??[])];
 }catch(Throwable $e){$failures[]=['toc_index'=>$idx,'package_id'=>$id,'error'=>$e->getMessage()];}
}
$ok=count($success)===count($sample)&&$failures===[];
echo json_encode(['ok'=>$ok,'read_only'=>true,'sampled'=>count($sample),'successes'=>$success,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
