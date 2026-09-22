#!/usr/bin/env php
<?php
/**
 * Offline/resumable v2 -> v3 metadata staging.
 *
 * Safe against the live v2 site: reads catalog/package data, writes only .uedb3,
 * never changes ue_file_metadata, projections, .uedb2, or ue_files.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(1); }

$catalogRoot=dirname(__DIR__,2);
require_once $catalogRoot.'/lib/CatalogSupport.php';
require_once $catalogRoot.'/lib/CatalogFileMaintenance.php';
require_once __DIR__.'/MetadataContainerV3.php';
require_once __DIR__.'/SnapshotBuilderV3.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogVerifiedPackageInspector;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceSupport;
use UnrealDb\Catalog\MigrationV3\MetadataContainerV3;
use UnrealDb\Catalog\MigrationV3\SnapshotBuilderV3;

$o=getopt('',['apply','limit:','after-id:','game-id:','file-ids:','stop-on-error','rebuild']);
$apply=array_key_exists('apply',$o); $limit=max(1,min(5000,(int)($o['limit']??250)));
$after=max(0,(int)($o['after-id']??0)); $game=max(0,(int)($o['game-id']??0));
$stop=array_key_exists('stop-on-error',$o); $rebuild=array_key_exists('rebuild',$o);
$config=catalog_config(); $db=catalog_db($config); $storageRoot=trim((string)($config['storage_path']??''));
if($storageRoot==='') throw new RuntimeException('catalog storage_path is not configured.');

$where=['f.scan_status="verified"','m.format_version=2','f.id>?']; $args=[$after];
if($game>0){$where[]='f.game_id=?';$args[]=$game;}
$raw=trim((string)($o['file-ids']??''));
if($raw!==''){
 $ids=[]; foreach(preg_split('/[\\s,;]+/',$raw)?:[] as $v){$id=(int)$v;if($id>0)$ids[$id]=$id;}
 if(!$ids)throw new RuntimeException('No positive --file-ids were supplied.');
 $where[]='f.id IN ('.implode(',',array_fill(0,count($ids),'?')).')'; array_push($args,...array_values($ids));
}
$sql='SELECT f.*,m.format_version FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id WHERE '.implode(' AND ',$where).' ORDER BY f.id LIMIT '.$limit;
$s=$db->prepare($sql);$s->execute($args);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];
if(!$apply){
 echo json_encode(['ok'=>true,'dry_run'=>true,'selected'=>count($rows),'after_id'=>$after,'limit'=>$limit,
 'first_file_id'=>$rows?(int)$rows[0]['id']:0,'last_file_id'=>$rows?(int)$rows[array_key_last($rows)]['id']:0,
 'writes'=>'*.uedb3 only','database_writes'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit(0);
}

$inspector=new CatalogVerifiedPackageInspector($db,$config);
$builder=new SnapshotBuilderV3($db,$config);
$support=new CatalogFileMaintenanceSupport($db,$config);
$done=0;$skipped=0;$failed=0;$lastAttempted=$after;$safeCursor=$after;$cursorFrozen=false;$errors=[];$started=microtime(true);
foreach($rows as $n=>$file){
 $id=(int)$file['id'];$lastAttempted=$id;$target=MetadataContainerV3::path($storageRoot,(int)$file['game_id'],$id);
 fwrite(STDOUT,'['.($n+1).'/'.count($rows)."] #{$id} ".(string)$file['original_name'].' ... ');
 try{
  if(!$rebuild && is_file($target)){
   MetadataContainerV3::verifyFile($target,$id,null,3);
   $skipped++;if(!$cursorFrozen)$safeCursor=$id;fwrite(STDOUT,"VALID v3 (skip)\n");continue;
  }
  $state=$support->reimportState($id);
  $source=catalog_file_maintenance_storage_path($config,$file);
  if($source===null||!is_file($source))throw new RuntimeException('Authoritative stored package is missing.');
  $sourceRelative=catalog_file_maintenance_source_relative_path($state);
  $inspection=$inspector->inspect((int)$file['game_id'],$source,(string)$file['original_name'],false,$sourceRelative,null);
  $catalogMd5=strtolower(trim((string)($file['md5']??'')));
  if($catalogMd5===''||!hash_equals($catalogMd5,strtolower($inspection->md5)))throw new RuntimeException('Stored package MD5 does not match catalog identity.');
  $snapshot=$builder->build($id,(int)$file['game_id'],(string)$file['package_name'],(string)$file['original_name'],
    $inspection->names,$inspection->imports,$inspection->exports);
  validateV3Snapshot($snapshot,$id);
  $tmp=$target.'.tmp.'.bin2hex(random_bytes(6));
  try{
   $built=MetadataContainerV3::buildToFile($snapshot,$tmp);
   MetadataContainerV3::verifyFile($tmp,$id,(string)$built['payload_sha256'],3);
   $dir=dirname($target);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Could not create metadata directory.');
   if(is_file($target)&&!@unlink($target))throw new RuntimeException('Could not replace existing staged v3 file.');
   if(!@rename($tmp,$target))throw new RuntimeException('Could not atomically publish staged v3 file.');
   MetadataContainerV3::verifyFile($target,$id,(string)$built['payload_sha256'],3);
  }finally{@unlink($tmp);}
  $done++;if(!$cursorFrozen)$safeCursor=$id;fwrite(STDOUT,"v3 STAGED\n");
 }catch(Throwable $e){
  $failed++;$cursorFrozen=true;$errors[]=['file_id'=>$id,'error'=>$e->getMessage()];fwrite(STDOUT,'FAILED: '.$e->getMessage()."\n");
  if($stop)break;
 }
}
$elapsed=max(.001,microtime(true)-$started);
echo json_encode(['ok'=>$failed===0,'selected'=>count($rows),'staged'=>$done,'skipped_valid'=>$skipped,'failed'=>$failed,
 'last_processed_id'=>$lastAttempted,'elapsed_seconds'=>round($elapsed,2),'files_per_second'=>round(($done+$skipped)/$elapsed,3),
 'resume_after_id'=>$safeCursor,'errors'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($failed===0?0:3);

/** @param array<string,mixed> $snapshot */
function validateV3Snapshot(array $snapshot,int $fileId): void
{
 $names=array_values((array)($snapshot['names']??[]));
 $imports=array_values((array)($snapshot['imports']??[]));
 $exports=array_values((array)($snapshot['exports']??[]));
 $dependencies=array_values((array)($snapshot['dependencies']??[]));
 $nameCount=count($names);$importCount=count($imports);$exportCount=count($exports);
 $usage=array_fill(0,$nameCount,['imports_count'=>0,'exports_count'=>0,'first_import_index'=>null,'first_export_index'=>null]);

 $checkNameIndex=static function(mixed $value,string $field,int $row)use($nameCount,$fileId):?int{
  if($value===null)return null;
  $index=(int)$value;
  if($index<0||$index>=$nameCount)throw new RuntimeException("v3 semantic validation failed for file {$fileId}: {$field}={$index} at row {$row}, name_count={$nameCount}.");
  return $index;
 };
 $checkObjectRef=static function(mixed $value,string $field,int $row)use($importCount,$exportCount,$fileId):void{
  $ref=(int)$value;
  if($ref<0&&(-$ref-1)>=$importCount)throw new RuntimeException("v3 semantic validation failed for file {$fileId}: {$field}={$ref} at row {$row}, import_count={$importCount}.");
  if($ref>0&&($ref-1)>=$exportCount)throw new RuntimeException("v3 semantic validation failed for file {$fileId}: {$field}={$ref} at row {$row}, export_count={$exportCount}.");
 };

 foreach($imports as $i=>$row){
  if(!is_array($row)||(int)($row['import_index']??-1)!==$i)throw new RuntimeException("v3 semantic validation failed for file {$fileId}: Import index sequence mismatch at row {$i}.");
  $seen=[];
  foreach(['class_package_name_index','class_name_index','object_name_index'] as $field){
   $idx=$checkNameIndex($row[$field]??null,"Import.{$field}",$i);
   if($idx!==null)$seen[$idx]=true;
  }
  foreach(array_keys($seen) as $idx){$usage[$idx]['imports_count']++;$usage[$idx]['first_import_index']??=$i;}
  $checkObjectRef($row['outer_index']??0,'Import.outer_index',$i);
 }
 foreach($exports as $i=>$row){
  if(!is_array($row)||(int)($row['export_index']??-1)!==$i)throw new RuntimeException("v3 semantic validation failed for file {$fileId}: Export index sequence mismatch at row {$i}.");
  $idx=$checkNameIndex($row['object_name_index']??null,'Export.object_name_index',$i);
  if($idx!==null){$usage[$idx]['exports_count']++;$usage[$idx]['first_export_index']??=$i;}
  foreach(['class_index','super_index','template_index','outer_index'] as $field)$checkObjectRef($row[$field]??0,"Export.{$field}",$i);
 }
 foreach($names as $i=>$row){
  if(!is_array($row)||(int)($row['name_index']??-1)!==$i)throw new RuntimeException("v3 semantic validation failed for file {$fileId}: Name index sequence mismatch at row {$i}.");
  foreach(['imports_count','exports_count'] as $field)if((int)($row[$field]??-1)!==$usage[$i][$field])throw new RuntimeException("v3 semantic validation failed for file {$fileId}: Name {$i} {$field} mismatch.");
  foreach(['first_import_index','first_export_index'] as $field){
   $actual=$row[$field]??null;$expected=$usage[$i][$field];
   if(($actual===null?null:(int)$actual)!==$expected)throw new RuntimeException("v3 semantic validation failed for file {$fileId}: Name {$i} {$field} mismatch.");
  }
 }
 if(count($dependencies)!==$importCount)throw new RuntimeException("v3 semantic validation failed for file {$fileId}: dependency_count=".count($dependencies).", import_count={$importCount}.");
}
