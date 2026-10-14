#!/usr/bin/env php
<?php
/** Fault injection: reject an object row AFTER dictionary/name inserts.
 * All writes are session-only MySQL TEMPORARY tables. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5CompactCandidatePublisher as Publisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader as Reader;
$db=catalog_bootstrap(false)->db;
$reader=new Reader(catalog_config()['storage_path']);
$created=[];$results=[];$errors=[];
try {
 $ddl=[
  'CREATE TEMPORARY TABLE u5_v2_test_dictionary(name_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name_key_hash BINARY(16) NOT NULL,name_key_length INT UNSIGNED NOT NULL,name_key_fingerprint BINARY(32) NOT NULL,UNIQUE KEY fingerprint(name_key_fingerprint)) ENGINE=InnoDB',
  'CREATE TEMPORARY TABLE u5_v2_test_names(file_id BIGINT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,first_name_index INT UNSIGNED NOT NULL,PRIMARY KEY(file_id,name_id)) ENGINE=InnoDB',
  'CREATE TEMPORARY TABLE u5_v2_test_objects(file_id BIGINT UNSIGNED NOT NULL,object_kind TINYINT UNSIGNED NOT NULL,object_index INT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,public_export_hash BINARY(8) NULL,PRIMARY KEY(file_id,object_kind,object_index), CONSTRAINT reject_all_objects CHECK(object_index <> object_index)) ENGINE=InnoDB'
 ];
 $names=['u5_v2_test_dictionary','u5_v2_test_names','u5_v2_test_objects'];
 foreach($ddl as $i=>$sql){$db->exec($sql);$created[]=$names[$i];}
 $publisher=new Publisher($db,true);
 $select=$db->prepare('SELECT * FROM ue_uedb5_files WHERE game_id=? AND file_id=?');
 $count=static fn(string $table):int=>(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
 foreach([2=>111139,3=>113253,4=>148919,5=>111460,6=>112300,7=>113526,12=>110902] as $game=>$file){
  try{
   $select->execute([$game,$file]);$registration=$select->fetch(PDO::FETCH_ASSOC);
   if(!is_array($registration))throw new RuntimeException('Missing V5 registration');
   $snapshot=$reader->snapshot($game,$file);
   $rejected=false;
   try{$publisher->publish($snapshot,$registration);}
   catch(\PDOException){$rejected=true;}
   if(!$rejected)throw new RuntimeException('Injected constraint did not reject publishing');
   if($db->inTransaction())throw new RuntimeException('Publisher left its own transaction open');
   foreach($names as $name)if($count($name)!==0)
     throw new RuntimeException('Partial rows survived rollback in '.$name);
   $results[]=['game_id'=>$game,'file_id'=>$file,'ok'=>true];
  }catch(Throwable $e){$errors[]=['game_id'=>$game,'error'=>$e->getMessage()];}
  finally{$reader->clearCache($game,$file);}
 }
}catch(Throwable $e){$errors[]=['setup_error'=>$e->getMessage()];}
finally{
 if($db->inTransaction())$db->rollBack();
 foreach(array_reverse($created) as $table)
  try{$db->exec('DROP TEMPORARY TABLE IF EXISTS '.$table);}
  catch(Throwable $e){$errors[]=['cleanup_error'=>$e->getMessage()];}
}
echo json_encode(['ok'=>count($results)===7&&!$errors,'rollback_faults_passed'=>count($results),
 'temporary_tables_only'=>true,'production_tables_read_only'=>true,
 'fault'=>'Injected CHECK rejects all object inserts after dictionary/name inserts',
 'results'=>$results,'errors'=>$errors],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit(count($results)===7&&!$errors?0:2);
