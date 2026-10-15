<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenIoStoreSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyRebuilder;
$utoc='//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/FactoryGame-WindowsServer.utoc';
$dll='C:/php8.5/ext/oo2core_9_win64.dll';
if(!is_file($utoc)||!is_file($dll))throw new RuntimeException('Missing read-only Satisfactory source or decoder');
$toc=new Uedb5IoStoreTocReader($utoc,null,$dll);
$storage=sys_get_temp_dir().'/uedb5_satisfactory_mysql_test';
$writer=new Uedb5MetadataSnapshotWriter($storage);
$reader=new Uedb5MetadataReader($storage);
$gameId=990001;$consumerId=980000000;
$consumer=$reader->snapshot($gameId,$consumerId);
if(($consumer['package_family']??'')!=='zen-iostore')throw new RuntimeException('Missing isolated Zen consumer');
$ids=$consumer['sections']['package_store'][0]['imported_package_ids']??[];
$selected=[];$errors=[];
foreach($ids as $i=>$packageId){
 try{
  $tocIndex=$toc->findPackageChunk($packageId,Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA,0);
  if($tocIndex===null)throw new RuntimeException('Referenced provider has no chunk');
  $providerId=980001000+$i;
  $snap=Uedb5Ue5ZenIoStoreSnapshotBuilder::build($toc,$packageId,[
    'id'=>$providerId,'game_id'=>$gameId,'package_name'=>'','original_name'=>'provider.utoc']);
  $writer->write($snap,2);
  $selected[]=['game_id'=>$gameId,'file_id'=>$providerId,'package_name'=>$snap['file']['package_name'],'selection_status'=>'selected'];
 }catch(Throwable $e){$errors[]=['package_id'=>$packageId,'error'=>$e->getMessage()];}
}
$result=null;$section=[];
if(!$errors){
 try{
  $result=(new Uedb5DependencyRebuilder($reader,$writer))->rebuild($gameId,$consumerId,$selected);
  $reader->clearCache($gameId,$consumerId);
  $after=$reader->snapshot($gameId,$consumerId);
  $section=$after['sections']['dependency_results']??[];
 }catch(Throwable $e){$errors[]=['stage'=>'dependency_rebuild','error'=>$e->getMessage()];}
}
$counts=[];
foreach($section as $row){$key=(string)($row['outcome']??'unknown');$counts[$key]=($counts[$key]??0)+1;}
$ok=$errors===[]&&count($selected)===count($ids)&&count($section)>0;
echo json_encode(['ok'=>$ok,'isolated_storage_only'=>true,'consumer_file_id'=>$consumerId,
 'referenced_packages'=>count($ids),'selected_physical_providers'=>count($selected),
 'dependency_rows'=>count($section),'outcomes'=>$counts,'dependency_schema'=>$result['dependency_schema']??null,
 'errors'=>$errors,'sample'=>array_slice($section,0,3)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
