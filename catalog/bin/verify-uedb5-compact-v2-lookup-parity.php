#!/usr/bin/env php
<?php
/** Direct live SQL vs compact v2 query parity; only session temporary table writes. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5CompactCandidatePublisher as Publisher;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5CompactCandidateLookup as Lookup;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader as Reader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionBuilder as Builder;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash as H;
$db=catalog_bootstrap(false)->db;
$reader=new Reader(catalog_config()['storage_path']);
$ids=[2=>111139,3=>113253,4=>148919,5=>111460,6=>112300,7=>113526,12=>110902];
$ddl=[
 'CREATE TEMPORARY TABLE u5_v2_test_dictionary(name_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name_key_hash BINARY(16) NOT NULL,name_key_length INT UNSIGNED NOT NULL,name_key_fingerprint BINARY(32) NOT NULL,UNIQUE KEY fingerprint(name_key_fingerprint),KEY lookup(name_key_hash,name_key_length,name_key_fingerprint)) ENGINE=InnoDB',
 'CREATE TEMPORARY TABLE u5_v2_test_names(file_id BIGINT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,first_name_index INT UNSIGNED NOT NULL,PRIMARY KEY(file_id,name_id),KEY lookup(name_id,file_id)) ENGINE=InnoDB',
 'CREATE TEMPORARY TABLE u5_v2_test_objects(file_id BIGINT UNSIGNED NOT NULL,object_kind TINYINT UNSIGNED NOT NULL,object_index INT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,public_export_hash BINARY(8) NULL,PRIMARY KEY(file_id,object_kind,object_index),KEY lookup(name_id,file_id,object_kind,object_index),KEY public_lookup(file_id,object_kind,public_export_hash,object_index)) ENGINE=InnoDB'
];
$tables=['u5_v2_test_dictionary','u5_v2_test_names','u5_v2_test_objects'];
$created=[];$fail=[];$results=[];$checks=['names'=>0,'objects'=>0,'public_exports'=>0];
$normalize=static function(array $rows,array $fields):array{
 return array_map(static function(array $r) use($fields):array{
  $a=[];foreach($fields as $f){$v=$r[$f]??null;
   $a[]=$f==='public_export_hash' && $v!==null?bin2hex((string)$v):
     (in_array($f,['file_id','first_name_index','object_kind','object_index'],true)?(int)$v:$v);}
  return $a;
 },$rows);
};
try{
 foreach($ddl as $i=>$sql){$db->exec($sql);$created[]=$tables[$i];}
 $pub=new Publisher($db,true);$lookup=new Lookup($db,true);
 $registration=$db->prepare('SELECT * FROM ue_uedb5_files WHERE game_id=? AND file_id=?');
 $oldName=$db->prepare('SELECT file_id,first_name_index FROM ue_uedb5_name_candidates
   WHERE file_id=? AND name_key_hash=? AND name_key_length=? AND name_key_fingerprint=?
   ORDER BY file_id,first_name_index');
 $oldObject=$db->prepare('SELECT file_id,object_kind,object_index,public_export_hash
   FROM ue_uedb5_object_candidates WHERE file_id=? AND object_name_hash=? AND object_name_length=?
   ORDER BY file_id,object_kind,object_index');
 $oldPublic=$db->prepare('SELECT file_id,object_kind,object_index,public_export_hash
   FROM ue_uedb5_object_candidates WHERE file_id=? AND object_kind=? AND public_export_hash=?
   ORDER BY object_index');
 foreach($ids as $game=>$file){
  try{
   $registration->execute([$game,$file]);$reg=$registration->fetch(PDO::FETCH_ASSOC);
   if(!is_array($reg))throw new RuntimeException('Registration not found');
   $snapshot=$reader->snapshot($game,$file);
   $projection=Builder::build($snapshot,$reg);
   $pub->publish($snapshot,$reg);
   $tested=['names'=>0,'objects'=>0,'public_exports'=>0];
   foreach(array_slice((array)$projection['search_keys'],0,16) as $k){
     $name=(string)$k['normalized_text'];
     $oldName->execute([$file,$k['hash'],$k['length'],$k['fingerprint']]);
     $original=$normalize($oldName->fetchAll(PDO::FETCH_ASSOC),['file_id','first_name_index']);
     $compact=$normalize($lookup->exactNames($name,$file),['file_id','first_name_index']);
     if($original!==$compact)throw new RuntimeException('Name lookup mismatch');
     $tested['names']++;
   }
   $seen=[];
   foreach(array_slice((array)$projection['object_candidates'],0,16) as $o){
     $kind=(int)$o['object_kind'];$index=(int)$o['object_index'];
     $section=$kind===2?'cell_exports':'exports';
     $source=null;
     foreach((array)($snapshot['sections'][$section]??[]) as $position=>$row){
       if((int)($row['index']??$position)===$index){$source=(array)$row;break;}
     }
     if($source===null)throw new RuntimeException('Missing V5 object position');
     $value=$source['object_name']??$source['objectName']??null;
     $name=is_array($value)?trim((string)($value['text']??$value['base_text']??'')):
       trim((string)($source['objectNameText']??$value??''));
     $key=H::nameKey($name);
     if($key==='')throw new RuntimeException('Empty V5 object name');
     $oldObject->execute([$file,md5($key,true),strlen($key)]);
     $fields=['file_id','object_kind','object_index','public_export_hash'];
     $a=$normalize($oldObject->fetchAll(PDO::FETCH_ASSOC),$fields);
     $b=$normalize($lookup->exactObjects($name,$file),$fields);
     if($a!==$b)throw new RuntimeException('Object lookup mismatch');
     $tested['objects']++;
     $hash=$o['public_export_hash']??null;
     if(is_string($hash)&&strlen($hash)===8){
       $dedup=$kind.':'.bin2hex($hash);
       if(!isset($seen[$dedup])){
        $seen[$dedup]=true;
        $oldPublic->execute([$file,$kind,$hash]);
        $a=$normalize($oldPublic->fetchAll(PDO::FETCH_ASSOC),$fields);
        $b=$normalize($lookup->publicExports($file,$kind,$hash),$fields);
        if($a!==$b)throw new RuntimeException('Public export lookup mismatch');
        $tested['public_exports']++;
       }
     }
   }
   foreach($tested as $k=>$value)$checks[$k]+=$value;
   $results[]=['game_id'=>$game,'file_id'=>$file,'ok'=>true,'comparisons'=>$tested];
  }catch(Throwable $e){$fail[]=['game_id'=>$game,'file_id'=>$file,'error'=>$e->getMessage()];}
  finally{$reader->clearCache($game,$file);}
 }
}catch(Throwable $e){$fail[]=['setup_error'=>$e->getMessage()];}
finally{
 if($db->inTransaction())$db->rollBack();
 foreach(array_reverse($created) as $t)try{$db->exec('DROP TEMPORARY TABLE IF EXISTS '.$t);}
 catch(Throwable $e){$fail[]=['cleanup_error'=>$e->getMessage()];}
}
echo json_encode(['ok'=>!$fail&&count($results)===7,'temporary_tables_only'=>true,
 'production_tables_read_only'=>true,'checks'=>$checks,'results'=>$results,'failures'=>$fail],
 JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit(!$fail&&count($results)===7?0:2);
