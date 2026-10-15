#!/usr/bin/env php
<?php
/** Fresh source V5 registration INSERT/UPSERT/DELETE against MySQL session shadow table, with compact publication. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/lib/GameProfiles.php';
require_once dirname(__DIR__).'/lib/CatalogUE4ParserProfile.php';
require_once dirname(__DIR__).'/lib/CatalogUE5ParserProfile.php';
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5CompactCandidatePublisher as P;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SourceSnapshotFactory;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceRegistry;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5StagingRegistrationRepository;
$db=catalog_bootstrap(false)->db;
$config=catalog_config();
$sourceFactory=new Uedb5SourceSnapshotFactory($db,$config);
$sourceRoot=rtrim((string)$config['storage_path'], '\\/');
$fileQuery=$db->prepare('SELECT * FROM ue_files WHERE id=? AND game_id=? AND scan_status="verified" LIMIT 1');
$tempStorage=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uedb5-source-sql-'.bin2hex(random_bytes(8));
$writer=new Uedb5MetadataSnapshotWriter($tempStorage);
$inspector=new PdoUedb5StagingRegistrationRepository($db,$tempStorage);
$tables=['ue_uedb5_files','u5_v2_test_dictionary','u5_v2_test_names','u5_v2_test_objects'];
$ddl=[
'CREATE TEMPORARY TABLE ue_uedb5_files(file_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,game_id INT UNSIGNED NOT NULL,format_version SMALLINT UNSIGNED NOT NULL,codec TINYINT UNSIGNED NOT NULL,compressed_size BIGINT UNSIGNED NOT NULL,uncompressed_size BIGINT UNSIGNED NOT NULL,payload_sha256 BINARY(32) NOT NULL,block_count INT UNSIGNED NOT NULL,package_family VARCHAR(32) NOT NULL,source_policy VARCHAR(96) NOT NULL,package_key_kind TINYINT UNSIGNED NOT NULL,package_key VARBINARY(16) NOT NULL,package_name VARCHAR(512) NOT NULL DEFAULT \'\',section_counts_json JSON NOT NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,KEY idx_ue_uedb5_files_package(game_id,package_key_kind,package_key,file_id)) ENGINE=InnoDB',
'CREATE TEMPORARY TABLE u5_v2_test_dictionary(name_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name_key_hash BINARY(16) NOT NULL,name_key_length INT UNSIGNED NOT NULL,name_key_fingerprint BINARY(32) NOT NULL,UNIQUE KEY fingerprint(name_key_fingerprint)) ENGINE=InnoDB',
'CREATE TEMPORARY TABLE u5_v2_test_names(file_id BIGINT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,first_name_index INT UNSIGNED NOT NULL,PRIMARY KEY(file_id,name_id),KEY lookup(name_id,file_id)) ENGINE=InnoDB',
'CREATE TEMPORARY TABLE u5_v2_test_objects(file_id BIGINT UNSIGNED NOT NULL,object_kind TINYINT UNSIGNED NOT NULL,object_index INT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,public_export_hash BINARY(8) NULL,PRIMARY KEY(file_id,object_kind,object_index),KEY lookup(name_id,file_id,object_kind,object_index)) ENGINE=InnoDB'];
$created=[];$fail=[];$passed=0;$rollback=0;$registrations=0;
try{
 foreach($ddl as $i=>$sql){$db->exec($sql);$created[]=$tables[$i];}
 $pub=new P($db,true);

 foreach([2=>111139,3=>113253,4=>148919,5=>111460,6=>112300,7=>113526,12=>110902] as $game=>$file){
   try{

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
     $written=$writer->write($snapshot);
     $row=$inspector->register($game,$file);
     $persisted=$inspector->find($file);
     if(!is_array($persisted) || !hash_equals((string)$written['payload_sha256'],(string)$persisted['payload_sha256'])
       || (int)$persisted['game_id']!==$game || (int)$persisted['format_version']!==5)
       throw new RuntimeException('V5 registration SQL insert/read mismatch');
     $inspector->refreshExisting($game,$file);
     $refreshed=$inspector->find($file);
     if(!is_array($refreshed) || !hash_equals((string)$persisted['payload_sha256'],(string)$refreshed['payload_sha256']))
       throw new RuntimeException('V5 registration SQL upsert mismatch');
     $registrations++;
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
     $inspector->remove($file);
     if($inspector->find($file)!==null)throw new RuntimeException('V5 registration removal mismatch');
     $inspector->register($game,$file);
     if(!is_array($inspector->find($file)))throw new RuntimeException('V5 re-registration mismatch');
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
 if(is_dir($tempStorage)){
   $walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempStorage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
   foreach($walk as $entry){if($entry->isDir()){@rmdir($entry->getPathname());}else{@unlink($entry->getPathname());}}
   @rmdir($tempStorage);
 }
}
echo json_encode(['ok'=>$fail===[]&&$passed===7,'session_temporary_tables_only'=>true,'fresh_source_parsed'=>true,'fresh_v5_registration_inspected'=>true,
 'games_passed'=>$passed,'rollback_passed'=>$rollback,'sql_registrations_passed'=>$registrations,'failures'=>$fail],
 JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($fail===[]&&$passed===7?0:2);
