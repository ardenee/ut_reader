#!/usr/bin/env php
<?php
/**
 * Bounded V5 compact backfill rehearsal.
 * Reads authoritative V5 containers and live candidate rows, and publishes
 * ONLY to session TEMPORARY v2 tables. No live writes or schema creation.
 * Stops at first error; returns the last VERIFIED cursor, never a skipped ID.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader as Reader;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5CompactCandidatePublisher as Publisher;
$opts=getopt('', ['game-id:','after-id::','limit::']);
$game=(int)($opts['game-id']??0);
$after=(int)($opts['after-id']??0);
$limit=max(1,min(25,(int)($opts['limit']??10)));
if(!in_array($game,[2,3,4,5,6,7,12],true)||$after<0){
 fwrite(STDERR,"Usage: --game-id=2|3|4|5|6|7|12 [--after-id=0] [--limit=10] (max 25)\n");
 exit(2);
}
$db=null;$tables=[];$cursor=$after;$verified=0;$rows=[];$failure=null;$hasMore=false;
try{
 $db=catalog_bootstrap(false)->db;
 $reader=new Reader(catalog_config()['storage_path']);
 // Session-only DDL; all names are private to this connection.
 $ddl=[
 'u5_v2_test_dictionary'=>'CREATE TEMPORARY TABLE u5_v2_test_dictionary(name_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name_key_hash BINARY(16) NOT NULL,name_key_length INT UNSIGNED NOT NULL,name_key_fingerprint BINARY(32) NOT NULL,UNIQUE KEY fingerprint(name_key_fingerprint),KEY lookup(name_key_hash,name_key_length,name_key_fingerprint)) ENGINE=InnoDB',
 'u5_v2_test_names'=>'CREATE TEMPORARY TABLE u5_v2_test_names(file_id BIGINT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,first_name_index INT UNSIGNED NOT NULL,PRIMARY KEY(file_id,name_id),KEY lookup(name_id,file_id)) ENGINE=InnoDB',
 'u5_v2_test_objects'=>'CREATE TEMPORARY TABLE u5_v2_test_objects(file_id BIGINT UNSIGNED NOT NULL,object_kind TINYINT UNSIGNED NOT NULL,object_index INT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,public_export_hash BINARY(8) NULL,PRIMARY KEY(file_id,object_kind,object_index),KEY lookup(name_id,file_id,object_kind,object_index)) ENGINE=InnoDB'
 ];
 foreach($ddl as $name=>$statement){$db->exec($statement);$tables[]=$name;}
 $select=$db->prepare('SELECT * FROM ue_uedb5_files WHERE game_id=? AND file_id>? ORDER BY file_id LIMIT '.($limit+1));
 $select->execute([$game,$after]);
 $registrations=$select->fetchAll(PDO::FETCH_ASSOC);
 $hasMore=count($registrations)>$limit;
 $registrations=array_slice($registrations,0,$limit);
 $publisher=new Publisher($db,true);
 $oldNames=$db->prepare('SELECT name_key_fingerprint,first_name_index FROM ue_uedb5_name_candidates WHERE file_id=? ORDER BY name_key_fingerprint');
 $oldObjects=$db->prepare('SELECT object_kind,object_index,object_name_hash,object_name_length,public_export_hash FROM ue_uedb5_object_candidates WHERE file_id=? ORDER BY object_kind,object_index');
 $compactNames=$db->prepare('SELECT k.name_key_fingerprint,n.first_name_index FROM u5_v2_test_names n JOIN u5_v2_test_dictionary k ON k.name_id=n.name_id WHERE n.file_id=? ORDER BY k.name_key_fingerprint');
 $compactObjects=$db->prepare('SELECT o.object_kind,o.object_index,k.name_key_hash AS object_name_hash,k.name_key_length AS object_name_length,o.public_export_hash FROM u5_v2_test_objects o JOIN u5_v2_test_dictionary k ON k.name_id=o.name_id WHERE o.file_id=? ORDER BY o.object_kind,o.object_index');
 foreach($registrations as $registration){
  $file=(int)$registration['file_id'];
  try{
   $snapshot=$reader->snapshot($game,$file);
   $published=$publisher->publish($snapshot,$registration);
   $oldNames->execute([$file]);$a=$oldNames->fetchAll(PDO::FETCH_NUM);
   $compactNames->execute([$file]);$b=$compactNames->fetchAll(PDO::FETCH_NUM);
   if($a!==$b)throw new RuntimeException('Name candidate rows do not match existing production SQL');
   $oldObjects->execute([$file]);$aObj=$oldObjects->fetchAll(PDO::FETCH_NUM);
   $compactObjects->execute([$file]);$bObj=$compactObjects->fetchAll(PDO::FETCH_NUM);
   if($aObj!==$bObj)throw new RuntimeException('Object candidate rows do not match existing production SQL');
   if(count($a)!==$published['names'] || count($aObj)!==$published['objects'])
    throw new RuntimeException('Published row count differs from live candidate count');
   $rows[]=['file_id'=>$file,'verified'=>true,'names'=>count($a),'objects'=>count($aObj)];
   $cursor=$file;$verified++;
  }catch(Throwable $e){
   $failure=['file_id'=>$file,'error'=>$e->getMessage()];
   break;
  }finally{$reader->clearCache($game,$file);}
 }
}catch(Throwable $e){$failure=['error'=>$e->getMessage()];}
finally{
 if($db instanceof PDO){
  if($db->inTransaction())$db->rollBack();
  foreach(array_reverse($tables) as $table)try{$db->exec('DROP TEMPORARY TABLE IF EXISTS '.$table);}
  catch(Throwable $e){$failure=['cleanup_error'=>$e->getMessage()];}
 }
}
$ok=$failure===null;
echo json_encode(['ok'=>$ok,'mode'=>'temporary_rehearsal_only','production_tables_unchanged'=>true,
 'game_id'=>$game,'start_after_id'=>$after,'verified_files'=>$verified,
 'next_after_id'=>$cursor,'has_more'=>$hasMore,'files'=>$rows,'failure'=>$failure,
 'checkpoint_policy'=>'Advance cursor only after exact row parity. Rerun from next_after_id.'],
 JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
