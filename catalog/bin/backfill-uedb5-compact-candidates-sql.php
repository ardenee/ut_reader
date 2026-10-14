#!/usr/bin/env php
<?php
/** Fast compact V5 candidate backfill using already-validated SQL identities.
 * No UEDB5 containers opened. No changes to legacy candidates/readers.
 * Only use while all catalog writers, imports, repairs and schedulers are paused.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require_once dirname(__DIR__).'/bootstrap.php';
$opt=getopt('', ['game-id:','limit::','apply','maintenance-frozen']);
$game=(int)($opt['game-id']??0);
$limit=max(1,min(500,(int)($opt['limit']??50)));
if(!in_array($game,[2,3,4,5,6,7,12],true)||
   !isset($opt['apply'])||!isset($opt['maintenance-frozen'])){
 fwrite(STDERR,"Usage: php backfill-uedb5-compact-candidates-sql.php --game-id=6 --limit=50 --apply --maintenance-frozen\n");
 exit(2);
}
$errors=null;$completed=[];$cursor=0;$start=0;$hasMore=false;
try{
 $db=catalog_bootstrap(false)->db;
 $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $tableNames=['ue_uedb5_compact_name_dictionary','ue_uedb5_compact_name_candidates',
              'ue_uedb5_compact_object_candidates','ue_uedb5_compact_backfill_progress'];
 $preflight=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?,?)');
 $preflight->execute($tableNames);
 if((int)$preflight->fetchColumn()!==4)throw new RuntimeException('Compact schema not installed');
 $progress='ue_uedb5_compact_backfill_progress';
 $dict='ue_uedb5_compact_name_dictionary';
 $names='ue_uedb5_compact_name_candidates';
 $objects='ue_uedb5_compact_object_candidates';
 $db->prepare('INSERT IGNORE INTO '.$progress.' (game_id,updated_at) VALUES (?,NOW(6))')->execute([$game]);
 $getProgress=$db->prepare('SELECT last_verified_file_id FROM '.$progress.' WHERE game_id=?');
 $getProgress->execute([$game]);$start=(int)$getProgress->fetchColumn();
 $cursor=$start;
 $find=$db->prepare('SELECT file_id FROM ue_uedb5_files WHERE game_id=? AND file_id>? ORDER BY file_id LIMIT '.($limit+1));
 $find->execute([$game,$start]);$ids=array_map('intval',$find->fetchAll(PDO::FETCH_COLUMN));
 $hasMore=count($ids)>$limit;$ids=array_slice($ids,0,$limit);

 // Exact keys for names use SHA-256; objects have MD5+length only.
 // Ambiguous/missing object resolution is rejected, never guessed.
 $unresolvedNames=$db->prepare(
  'SELECT n.name_key_fingerprint FROM ue_uedb5_name_candidates n
   LEFT JOIN ue_uedb5_search_keys k ON k.key_fingerprint=n.name_key_fingerprint
   WHERE n.file_id=? AND (k.key_fingerprint IS NULL OR k.key_hash<>n.name_key_hash
      OR k.key_length<>n.name_key_length) LIMIT 1');
 $unresolvedObjects=$db->prepare(
  'SELECT o.object_kind,o.object_index FROM ue_uedb5_object_candidates o
   WHERE o.file_id=? AND
     (SELECT COUNT(*) FROM ue_uedb5_search_keys k
       WHERE k.key_hash=o.object_name_hash AND k.key_length=o.object_name_length)<>1 LIMIT 1');
 $addNameDict=$db->prepare(
  'INSERT IGNORE INTO '.$dict.' (name_key_hash,name_key_length,name_key_fingerprint)
   SELECT DISTINCT k.key_hash,k.key_length,k.key_fingerprint
   FROM ue_uedb5_name_candidates n JOIN ue_uedb5_search_keys k
     ON k.key_fingerprint=n.name_key_fingerprint WHERE n.file_id=?');
 $addObjectDict=$db->prepare(
  'INSERT IGNORE INTO '.$dict.' (name_key_hash,name_key_length,name_key_fingerprint)
   SELECT DISTINCT k.key_hash,k.key_length,k.key_fingerprint
   FROM ue_uedb5_object_candidates o JOIN ue_uedb5_search_keys k
     ON k.key_hash=o.object_name_hash AND k.key_length=o.object_name_length
   WHERE o.file_id=?');
 $addNames=$db->prepare(
  'INSERT INTO '.$names.' (file_id,name_id,first_name_index)
   SELECT n.file_id,d.name_id,n.first_name_index FROM ue_uedb5_name_candidates n
   JOIN '.$dict.' d ON d.name_key_fingerprint=n.name_key_fingerprint
   AND d.name_key_hash=n.name_key_hash AND d.name_key_length=n.name_key_length
   WHERE n.file_id=?');
 $addObjects=$db->prepare(
  'INSERT INTO '.$objects.' (file_id,object_kind,object_index,name_id,public_export_hash)
   SELECT o.file_id,o.object_kind,o.object_index,d.name_id,o.public_export_hash
   FROM ue_uedb5_object_candidates o JOIN '.$dict.' d
   ON d.name_key_hash=o.object_name_hash AND d.name_key_length=o.object_name_length
   WHERE o.file_id=?');
 $countLegacyNames=$db->prepare('SELECT COUNT(*) FROM ue_uedb5_name_candidates WHERE file_id=?');
 $countLegacyObjects=$db->prepare('SELECT COUNT(*) FROM ue_uedb5_object_candidates WHERE file_id=?');
 $countNewNames=$db->prepare('SELECT COUNT(*) FROM '.$names.' WHERE file_id=?');
 $countNewObjects=$db->prepare('SELECT COUNT(*) FROM '.$objects.' WHERE file_id=?');
 // Bidirectional coverage checks preserve every stored identity, object kind,
 // index and public export hash; count checks guarantee no extra rows.
 $nameMismatch=$db->prepare(
  'SELECT n.name_key_fingerprint FROM ue_uedb5_name_candidates n
   LEFT JOIN '.$dict.' d ON d.name_key_fingerprint=n.name_key_fingerprint
     AND d.name_key_hash=n.name_key_hash AND d.name_key_length=n.name_key_length
   LEFT JOIN '.$names.' cn ON cn.file_id=n.file_id AND cn.name_id=d.name_id
   WHERE n.file_id=? AND (cn.name_id IS NULL OR cn.first_name_index<>n.first_name_index) LIMIT 1');
 $objectMismatch=$db->prepare(
  'SELECT o.object_kind,o.object_index FROM ue_uedb5_object_candidates o
   LEFT JOIN '.$objects.' co ON co.file_id=o.file_id
      AND co.object_kind=o.object_kind AND co.object_index=o.object_index
   LEFT JOIN '.$dict.' d ON d.name_id=co.name_id
      AND d.name_key_hash=o.object_name_hash AND d.name_key_length=o.object_name_length
   WHERE o.file_id=? AND (d.name_id IS NULL OR NOT(co.public_export_hash <=> o.public_export_hash)) LIMIT 1');
 $lock=$db->prepare('SELECT last_verified_file_id FROM '.$progress.' WHERE game_id=? FOR UPDATE');
 $advance=$db->prepare('UPDATE '.$progress.' SET
   last_verified_file_id=?,verified_file_count=verified_file_count+1,
   verified_name_count=verified_name_count+?,verified_object_count=verified_object_count+?,
   updated_at=NOW(6) WHERE game_id=? AND last_verified_file_id=?');
 $delNames=$db->prepare('DELETE FROM '.$names.' WHERE file_id=?');
 $delObjects=$db->prepare('DELETE FROM '.$objects.' WHERE file_id=?');
 $num=static function(PDOStatement $s,int $id):int{$s->execute([$id]);return (int)$s->fetchColumn();};
 foreach($ids as $id){
  $db->beginTransaction();
  try{
   $lock->execute([$game]);if((int)$lock->fetchColumn()!==$cursor)
      throw new RuntimeException('Concurrent checkpoint change');
   $unresolvedNames->execute([$id]);if($unresolvedNames->fetchColumn()!==false)
      throw new RuntimeException('Missing or inconsistent exact name fingerprint');
   $unresolvedObjects->execute([$id]);if($unresolvedObjects->fetchColumn()!==false)
      throw new RuntimeException('Missing or ambiguous object search identity');
   // Idempotent file replacement, with old compact rows preserved on rollback.
   $delNames->execute([$id]);$delObjects->execute([$id]);
   $addNameDict->execute([$id]);$addObjectDict->execute([$id]);
   $addNames->execute([$id]);$addObjects->execute([$id]);
   $nc=$num($countLegacyNames,$id);$oc=$num($countLegacyObjects,$id);
   if($nc!==$num($countNewNames,$id)||$oc!==$num($countNewObjects,$id))
      throw new RuntimeException('Compact row count mismatch');
   $nameMismatch->execute([$id]);if($nameMismatch->fetchColumn()!==false)
      throw new RuntimeException('Compact name identity/index parity mismatch');
   $objectMismatch->execute([$id]);if($objectMismatch->fetchColumn()!==false)
      throw new RuntimeException('Compact object identity/hash parity mismatch');
   $advance->execute([$id,$nc,$oc,$game,$cursor]);
   if($advance->rowCount()!==1)throw new RuntimeException('Checkpoint advance rejected');
   $db->commit();
   $completed[]=['file_id'=>$id,'names'=>$nc,'objects'=>$oc];
   $cursor=$id;
  }catch(Throwable $e){
   if($db->inTransaction())$db->rollBack();
   $errors=['file_id'=>$id,'message'=>$e->getMessage()];
   break;
  }
 }
}catch(Throwable $e){$errors=['message'=>$e->getMessage()];}
finally{if(isset($db)&&$db instanceof PDO&&$db->inTransaction())$db->rollBack();}
echo json_encode(['ok'=>$errors===null,'method'=>'indexed_sql_to_sql','game_id'=>$game,
 'start_after_id'=>$start,'next_after_id'=>$cursor,'verified_files'=>count($completed),
 'names'=>array_sum(array_column($completed,'names')),
 'objects'=>array_sum(array_column($completed,'objects')),
 'has_more'=>$hasMore,'failure'=>$errors,
 'original_candidates_unchanged'=>true,'active_search_unchanged'=>true],
 JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($errors===null?0:2);
