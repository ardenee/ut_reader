#!/usr/bin/env php
<?php
/** Fresh authoritative source parsing to V5 compact SQL publisher. Uses session TEMPORARY tables only; no persistent SQL writes. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/lib/GameProfiles.php';
require_once dirname(__DIR__).'/lib/CatalogUE4ParserProfile.php';
require_once dirname(__DIR__).'/lib/CatalogUE5ParserProfile.php';
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5CompactCandidatePublisher as P;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SourceSnapshotFactory;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceRegistry;
$db=catalog_bootstrap(false)->db;
$config=catalog_config();
$sourceFactory=new Uedb5SourceSnapshotFactory($db,$config);
$sourceRoot=rtrim((string)$config['storage_path'], '\\/');
$fileQuery=$db->prepare('SELECT * FROM ue_files WHERE id=? AND game_id=? AND scan_status="verified" LIMIT 1');
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
     $fileQuery->execute([$file,$game]);$sourceRow=$fileQuery->fetch(PDO::FETCH_ASSOC);
     if(!is_array($sourceRow))throw new RuntimeException('Verified source row missing');
     $stored=(string)($sourceRow['stored_name']??'');
     if($stored==='' || basename($stored)!==$stored)throw new RuntimeException('Unsafe stored file name');
     $sourcePath=$sourceRoot.DIRECTORY_SEPARATOR.'games'.DIRECTORY_SEPARATOR
       .Uedb5GameSourceRegistry::storageKey($game).DIRECTORY_SEPARATOR.'verified'.DIRECTORY_SEPARATOR.$stored;
     if(!is_file($sourcePath))throw new RuntimeException('Source package missing');
     clearstatcache(true,$sourcePath);
     if((int)filesize($sourcePath)!==(int)$sourceRow['file_size']
       || !hash_equals(strtolower((string)$sourceRow['md5']),(string)md5_file($sourcePath))
       || !hash_equals(strtolower((string)$sourceRow['sha1']),(string)sha1_file($sourcePath)))
       throw new RuntimeException('Source package identity mismatch');
     $snapshot=$sourceFactory->buildForGameId($game,$sourcePath,$sourceRow);
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
   }
 }
}catch(Throwable $e){$fail[]=['setup_error'=>$e->getMessage()];}
finally{
 if($db->inTransaction())$db->rollBack();
 foreach(array_reverse($created) as $table){
   try{$db->exec('DROP TEMPORARY TABLE IF EXISTS '.$table);}
   catch(Throwable $e){$fail[]=['cleanup_error'=>$e->getMessage()];}
 }
}
echo json_encode(['ok'=>$fail===[]&&$passed===7,'session_temporary_tables_only'=>true,'fresh_source_parsed'=>true,
 'games_passed'=>$passed,'rollback_passed'=>$rollback,'failures'=>$fail],
 JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($fail===[]&&$passed===7?0:2);
