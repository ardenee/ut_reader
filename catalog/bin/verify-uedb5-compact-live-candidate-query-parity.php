#!/usr/bin/env php
<?php
/** Bounded direct parity of live candidate SQL against temporary compact reads.
 * Production candidate tables queried read-only, no permanent writes. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5CompactTemporaryCandidateStore as Store;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader as Reader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionBuilder as Builder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract as K;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash as H;
$db=catalog_bootstrap(false)->db;
$reader=new Reader(catalog_config()['storage_path']);
$store=new Store($db);
$ids=[2=>111139,3=>113253,4=>148919,5=>111460,6=>112300,7=>113526,12=>110902];
$reg=$db->prepare('SELECT * FROM ue_uedb5_files WHERE file_id=? AND game_id=?');
$oldNames=$db->prepare('SELECT file_id,first_name_index FROM ue_uedb5_name_candidates WHERE file_id=?
 AND name_key_hash=? AND name_key_length=? AND name_key_fingerprint=? ORDER BY file_id,first_name_index');
$oldObjects=$db->prepare('SELECT file_id,object_kind,object_index,public_export_hash
 FROM ue_uedb5_object_candidates WHERE file_id=? AND object_name_hash=? AND object_name_length=?
 ORDER BY file_id,object_kind,object_index');
$results=[];$checks=0;$fail=[];
try{
 $store->create();
 foreach($ids as $game=>$id){
  try{
   $reg->execute([$id,$game]);$registration=$reg->fetch(PDO::FETCH_ASSOC);
   if(!is_array($registration))throw new RuntimeException('Registration missing');
   $snapshot=$reader->snapshot($game,$id);
   $projection=Builder::build($snapshot,$registration);
   $store->publish($snapshot,$registration);
   $testedNames=0;$testedObjects=0;
   foreach(array_slice((array)$projection['search_keys'],0,12) as $key){
    $text=(string)$key['normalized_text'];
    $oldNames->execute([$id,$key['hash'],$key['length'],$key['fingerprint']]);
    $a=$oldNames->fetchAll(PDO::FETCH_ASSOC);
    $b=array_values(array_filter($store->exactNames($text),static fn($row)=>(int)$row['file_id']===$id));
    $norm=static function(array $rows):array{
      return array_map(static fn(array $r):array=>[(int)$r['file_id'],(int)$r['first_name_index']],$rows);
    };
    if($norm($a)!==$norm($b))throw new RuntimeException('Live name-query mismatch for key '.bin2hex($key['fingerprint']));
    $testedNames++;$checks++;
   }
   foreach(array_slice((array)$projection['object_candidates'],0,12) as $o){
     $kind=(int)$o['object_kind'];$index=(int)$o['object_index'];
     $section=$kind===K::OBJECT_KIND_CELL_EXPORT?'cell_exports':'exports';
     $found=null;
     foreach((array)($snapshot['sections'][$section]??[]) as $ordinal=>$row)
       if((int)($row['index']??$ordinal)===$index){$found=(array)$row;break;}
     if($found===null)throw new RuntimeException('Missing source object');
     $value=$found['object_name']??$found['objectName']??null;
     $text=is_array($value)?trim((string)($value['text']??$value['base_text']??'')):
       trim((string)($found['objectNameText']??$value??''));
     $name=H::nameKey($text);
     $oldObjects->execute([$id,md5($name,true),strlen($name)]);
     $a=$oldObjects->fetchAll(PDO::FETCH_ASSOC);
     $b=array_values(array_filter($store->exactObjects($text),static fn($row)=>(int)$row['file_id']===$id));
     $norm=static function(array $rows):array{
       return array_map(static fn(array $r):array=>[
         (int)$r['file_id'],(int)$r['object_kind'],(int)$r['object_index'],
         $r['public_export_hash']===null?null:bin2hex((string)$r['public_export_hash'])],$rows);
     };
     if($norm($a)!==$norm($b))throw new RuntimeException('Live object-query mismatch for kind/index '.$kind.'/'.$index);
     $testedObjects++;$checks++;
   }
   $results[]=['game_id'=>$game,'file_id'=>$id,'ok'=>true,'name_queries'=>$testedNames,
     'object_queries'=>$testedObjects];
  }catch(Throwable $e){
   $fail[]=['game_id'=>$game,'file_id'=>$id,'error'=>$e->getMessage()];
  }finally{$reader->clearCache($game,$id);}
 }
}catch(Throwable $e){$fail[]=['error'=>$e->getMessage()];}
finally{try{$store->drop();}catch(Throwable $e){$fail[]=['cleanup_error'=>$e->getMessage()];}}
echo json_encode(['ok'=>!$fail,'session_temporary_tables_only'=>true,'live_candidate_tables_read_only'=>true,
 'query_comparisons'=>$checks,'results'=>$results,'failures'=>$fail],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($fail?2:0);
