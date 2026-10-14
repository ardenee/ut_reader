#!/usr/bin/env php
<?php
/**
 * Resumable compact V5 candidate migration executor.
 * --test-temporary: writes session-local temporary tables only.
 * --apply --maintenance-frozen: persists compact rows and checkpoint per file.
 *
 * During --apply all catalogue upload/import/resync/repair/deletion activity
 * MUST be externally disabled, including background jobs and schedulers.
 * Does not change active reads or delete legacy candidate tables.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(2);}
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5CompactCandidatePublisher as Publisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader as Reader;

$opts=getopt('', ['game-id:','limit::','apply','maintenance-frozen','test-temporary']);
$game=(int)($opts['game-id']??0);
$limit=max(1,min(500,(int)($opts['limit']??50)));
$test=array_key_exists('test-temporary',$opts);
$apply=array_key_exists('apply',$opts);
$freeze=array_key_exists('maintenance-frozen',$opts);
if(!in_array($game,[2,3,4,5,6,7,12],true)||$test===$apply||($apply&&!$freeze)){
 fwrite(STDERR,"Usage: --game-id=7 --limit=10 --test-temporary OR --game-id=7 --limit=10 --apply --maintenance-frozen\n");
 exit(2);
}

$db=null;$created=[];$rows=[];$error=null;$cursor=0;$start=0;$more=false;
$prefix=$test?'u5_v2_test_':'ue_uedb5_compact_';
$dict=$test?'u5_v2_test_dictionary':'ue_uedb5_compact_name_dictionary';
$names=$test?'u5_v2_test_names':'ue_uedb5_compact_name_candidates';
$objects=$test?'u5_v2_test_objects':'ue_uedb5_compact_object_candidates';
$progress=$test?'u5_v2_test_progress':'ue_uedb5_compact_backfill_progress';

try{
 $db=catalog_bootstrap(false)->db;
 if($test){
  $ddl=[
   $dict=>'CREATE TEMPORARY TABLE '.$dict.' (name_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,name_key_hash BINARY(16) NOT NULL,name_key_length INT UNSIGNED NOT NULL,name_key_fingerprint BINARY(32) NOT NULL,UNIQUE KEY fingerprint(name_key_fingerprint)) ENGINE=InnoDB',
   $names=>'CREATE TEMPORARY TABLE '.$names.' (file_id BIGINT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,first_name_index INT UNSIGNED NOT NULL,PRIMARY KEY(file_id,name_id)) ENGINE=InnoDB',
   $objects=>'CREATE TEMPORARY TABLE '.$objects.' (file_id BIGINT UNSIGNED NOT NULL,object_kind TINYINT UNSIGNED NOT NULL,object_index INT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,public_export_hash BINARY(8) NULL,PRIMARY KEY(file_id,object_kind,object_index)) ENGINE=InnoDB',
   $progress=>'CREATE TEMPORARY TABLE '.$progress.' (game_id INT UNSIGNED NOT NULL PRIMARY KEY,last_verified_file_id BIGINT UNSIGNED NOT NULL DEFAULT 0,verified_file_count BIGINT UNSIGNED NOT NULL DEFAULT 0,verified_name_count BIGINT UNSIGNED NOT NULL DEFAULT 0,verified_object_count BIGINT UNSIGNED NOT NULL DEFAULT 0,updated_at DATETIME(6) NOT NULL) ENGINE=InnoDB'
  ];
  foreach($ddl as $name=>$sql){$db->exec($sql);$created[]=$name;}
 }else{
  // Fail closed if all permanent v2 tables and the ledger are not provisioned.
  $check=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?,?)');
  $check->execute([$dict,$names,$objects,$progress]);
  if((int)$check->fetchColumn()!==4)throw new RuntimeException('Permanent compact schema/ledger not installed; no writes made.');
 }
 $reader=new Reader(catalog_config()['storage_path']);
 $publisher=new Publisher($db,$test);
 $db->prepare('INSERT IGNORE INTO '.$progress.' (game_id,updated_at) VALUES (?,NOW(6))')->execute([$game]);
 $selectCheckpoint=$db->prepare('SELECT last_verified_file_id FROM '.$progress.' WHERE game_id=? FOR UPDATE');
 $readCheckpoint=$db->prepare('SELECT last_verified_file_id FROM '.$progress.' WHERE game_id=?');
 $readCheckpoint->execute([$game]);$start=(int)$readCheckpoint->fetchColumn();
 $cursor=$start;
 $select=$db->prepare('SELECT * FROM ue_uedb5_files WHERE game_id=? AND file_id>? ORDER BY file_id LIMIT '.($limit+1));
 $select->execute([$game,$start]);$candidates=$select->fetchAll(PDO::FETCH_ASSOC);
 $more=count($candidates)>$limit;$candidates=array_slice($candidates,0,$limit);
 $oldNames=$db->prepare('SELECT name_key_fingerprint,first_name_index FROM ue_uedb5_name_candidates WHERE file_id=? ORDER BY name_key_fingerprint');
 $newNames=$db->prepare('SELECT d.name_key_fingerprint,n.first_name_index FROM '.$names.' n JOIN '.$dict.' d ON d.name_id=n.name_id WHERE n.file_id=? ORDER BY d.name_key_fingerprint');
 $oldObjects=$db->prepare('SELECT object_kind,object_index,object_name_hash,object_name_length,public_export_hash FROM ue_uedb5_object_candidates WHERE file_id=? ORDER BY object_kind,object_index');
 $newObjects=$db->prepare('SELECT o.object_kind,o.object_index,d.name_key_hash,d.name_key_length,o.public_export_hash FROM '.$objects.' o JOIN '.$dict.' d ON d.name_id=o.name_id WHERE o.file_id=? ORDER BY o.object_kind,o.object_index');
 $update=$db->prepare('UPDATE '.$progress.' SET last_verified_file_id=?,verified_file_count=verified_file_count+1,verified_name_count=verified_name_count+?,verified_object_count=verified_object_count+?,updated_at=NOW(6) WHERE game_id=? AND last_verified_file_id=?');
 foreach($candidates as $registration){
  $file=(int)$registration['file_id'];
  $db->beginTransaction();
  try{
   $selectCheckpoint->execute([$game]);$locked=(int)$selectCheckpoint->fetchColumn();
   if($locked!==$cursor)throw new RuntimeException('Checkpoint advanced unexpectedly by another worker.');
   $snapshot=$reader->snapshot($game,$file);
   $pub=$publisher->publish($snapshot,$registration);
   $oldNames->execute([$file]);$a=$oldNames->fetchAll(PDO::FETCH_NUM);
   $newNames->execute([$file]);$b=$newNames->fetchAll(PDO::FETCH_NUM);
   if($a!==$b)throw new RuntimeException('Name full-row parity mismatch');
   $oldObjects->execute([$file]);$ao=$oldObjects->fetchAll(PDO::FETCH_NUM);
   $newObjects->execute([$file]);$bo=$newObjects->fetchAll(PDO::FETCH_NUM);
   if($ao!==$bo)throw new RuntimeException('Object full-row parity mismatch');
   if($pub['names']!==count($a)||$pub['objects']!==count($ao))
     throw new RuntimeException('Published counts do not match legacy candidate counts');
   $update->execute([$file,count($a),count($ao),$game,$cursor]);
   if($update->rowCount()!==1)throw new RuntimeException('Checkpoint compare-and-swap failed');
   $db->commit();
   $rows[]=['file_id'=>$file,'names'=>count($a),'objects'=>count($ao),'verified'=>true];
   $cursor=$file;
  }catch(Throwable $e){
   if($db->inTransaction())$db->rollBack();
   $error=['file_id'=>$file,'message'=>$e->getMessage()];
   break;
  }finally{$reader->clearCache($game,$file);}
 }
}catch(Throwable $e){$error=['message'=>$e->getMessage()];}
finally{
 if($db instanceof PDO){
  if($db->inTransaction())$db->rollBack();
  foreach(array_reverse($created) as $name)
   try{$db->exec('DROP TEMPORARY TABLE IF EXISTS '.$name);}
   catch(Throwable $e){$error=['cleanup_error'=>$e->getMessage()];}
 }
}
echo json_encode(['ok'=>$error===null,'mode'=>$test?'session_temporary':'persistent_compact',
 'game_id'=>$game,'start_after_id'=>$start,'next_after_id'=>$cursor,
 'verified_files'=>count($rows),'has_more'=>$more,'rows'=>$rows,'failure'=>$error,
 'old_candidate_tables_unchanged'=>true,
 'active_read_path_unchanged'=>true],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($error===null?0:2);
