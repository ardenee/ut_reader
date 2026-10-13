#!/usr/bin/env php
<?php
/** Targeted real UEDB5-snapshot smoke test for collision-safe compact projection.
 * Read-only. No DB writes, no schema migration, no package reparse. */
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5CompactCandidateProjectionBuilder;
$db=catalog_bootstrap(false)->db;
$reader=new Uedb5MetadataReader(catalog_config()['storage_path']);
$ids=[2=>111139,3=>113253,4=>148919,5=>111460,6=>112300,7=>113526,12=>110902];
$stmt=$db->prepare('SELECT * FROM ue_uedb5_files WHERE file_id=? AND game_id=?');
$rows=[];$passed=0;
foreach($ids as $gameId=>$fileId){
 try{
  $stmt->execute([$fileId,$gameId]);
  $registration=$stmt->fetch(PDO::FETCH_ASSOC);
  if(!is_array($registration))throw new RuntimeException('Missing V5 registration.');
  $snapshot=$reader->snapshot($gameId,$fileId);
  $projection=Uedb5SqlProjectionBuilder::build($snapshot,$registration);
  $dictionary=[];$nextId=1;
  foreach((array)$projection['search_keys'] as $key){
   $dictionary[]=['name_id'=>$nextId++,'key_hash'=>$key['hash'],
     'key_length'=>$key['length'],'key_fingerprint'=>$key['fingerprint'],
     'normalized_text'=>$key['normalized_text']];
  }
  $compact=Uedb5CompactCandidateProjectionBuilder::build($snapshot,$projection,$dictionary);
  $names=count($projection['name_candidates']);$objects=count($projection['object_candidates']);
  if(count($compact['name_candidates'])!==$names || count($compact['object_candidates'])!==$objects)
    throw new RuntimeException('Compact candidate cardinality mismatch.');
  $rows[]=['game_id'=>$gameId,'file_id'=>$fileId,'ok'=>true,'names'=>$names,'objects'=>$objects];
  $passed++;
 }catch(Throwable $e){
  $rows[]=['game_id'=>$gameId,'file_id'=>$fileId,'ok'=>false,'error'=>$e->getMessage()];
 }finally{$reader->clearCache($gameId,$fileId);}
}
echo json_encode(['ok'=>$passed===count($ids),'read_only'=>true,'passed'=>$passed,
  'total'=>count($ids),'tests'=>$rows,'dictionary_source'=>'UEDB5-derived projection keys, not an SQL dictionary lookup'],
  JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($passed===count($ids)?0:2);
