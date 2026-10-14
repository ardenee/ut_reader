#!/usr/bin/env php
<?php
/** v2 publisher regression: production reads only, session TEMPORARY tables. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5CompactCandidatePublisher as P;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader as R;
$db=catalog_bootstrap(false)->db;
$reader=new R(catalog_config()['storage_path']);
$tables=['u5_v2_test_dictionary','u5_v2_test_names','u5_v2_test_objects'];
$ddl=[
'CREATE TEMPORARY TABLE u5_v2_test_dictionary(name_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name_key_hash BINARY(16) NOT NULL,name_key_length INT UNSIGNED NOT NULL,name_key_fingerprint BINARY(32) NOT NULL,UNIQUE KEY fingerprint(name_key_fingerprint)) ENGINE=InnoDB',
'CREATE TEMPORARY TABLE u5_v2_test_names(file_id BIGINT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,first_name_index INT UNSIGNED NOT NULL,PRIMARY KEY(file_id,name_id),KEY lookup(name_id,file_id)) ENGINE=InnoDB',
'CREATE TEMPORARY TABLE u5_v2_test_objects(file_id BIGINT UNSIGNED NOT NULL,object_kind TINYINT UNSIGNED NOT NULL,object_index INT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,public_export_hash BINARY(8) NULL,PRIMARY KEY(file_id,object_kind,object_index),KEY lookup(name_id,file_id,object_kind,object_index)) ENGINE=InnoDB'];
$created=[];$fail=[];$passed=0;$rollback=0;
try{
 foreach($ddl as $i=>$sql){$db->exec($sql);$created[]=$tables[$i];}
 $pub=new P($db,true);
 $reg=$db->prepare('SELECT * FROM ue_uedb5_files WHERE game_id=? AND file_id=?');
 foreach([2=>111139,3=>113253,4=>148919,5=>111460,6=>112300,7=>113526,12=>110902] as $game=>$file){
   try{
     $reg->execute([$game,$file]);$row=$reg->fetch(PDO::FETCH_ASSOC);
     if(!is_array($row))throw new RuntimeException('Registration missing');
     $snapshot=$reader->snapshot($game,$file);
     $first=$pub->publish($snapshot,$row);
     $second=$pub->publish($snapshot,$row);
     $expected=['names'=>$first['names'],'objects'=>$first['objects']];
     if($pub->counts($file)!==$expected || $second['names']!==$first['names'])
       throw new RuntimeException('Republish mismatch');
     $db->beginTransaction();
     try{
       $pub->deleteFile($file);
       if($pub->counts($file)!==['names'=>0,'objects'=>0])
         throw new RuntimeException('Delete in transaction failed');
     }finally{if($db->inTransaction())$db->rollBack();}
     if($pub->counts($file)!==$expected)throw new RuntimeException('Rollback mismatch');
     $rollback++;
     $pub->deleteFile($file);
     $pub->publish($snapshot,$row);
     if($pub->counts($file)!==$expected)throw new RuntimeException('Rebuild mismatch');
     $passed++;
   }catch(Throwable $e){
     if($db->inTransaction())$db->rollBack();
     $fail[]=['game_id'=>$game,'error'=>$e->getMessage()];
   }finally{$reader->clearCache($game,$file);}
 }
}catch(Throwable $e){$fail[]=['setup_error'=>$e->getMessage()];}
finally{
 if($db->inTransaction())$db->rollBack();
 foreach(array_reverse($created) as $table){
   try{$db->exec('DROP TEMPORARY TABLE IF EXISTS '.$table);}
   catch(Throwable $e){$fail[]=['cleanup_error'=>$e->getMessage()];}
 }
}
echo json_encode(['ok'=>$fail===[]&&$passed===7,'session_temporary_tables_only'=>true,
 'games_passed'=>$passed,'rollback_passed'=>$rollback,'failures'=>$fail],
 JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($fail===[]&&$passed===7?0:2);

[executed on device: Lelly-pc (1e6f9b87-60aa-4fe3-bf48-4166e6555d43)]