#!/usr/bin/env php
<?php
/**
 * Bounded V5 candidate compact-schema parity benchmark over distributed game files.
 * Creates/drops SESSION TEMPORARY tables only. No permanent SQL/metadata writes.
 * Does not claim full-catalogue parity or measured InnoDB physical size.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);}
require_once dirname(__DIR__).'/bootstrap.php';
$opts=getopt('', ['rows-per-game::','lookup-keys::']);
$perGame=max(200,min(10000,(int)($opts['rows-per-game']??3000)));
$keyCount=max(10,min(200,(int)($opts['lookup-keys']??60)));
$tables=[];
$db=null;
$result=['ok'=>false,'temporary_tables_only'=>true,'live_tables_read_only'=>true,
 'per_game_row_cap'=>$perGame,'lookup_keys_per_table'=>$keyCount];
try{
 $db=catalog_bootstrap(false)->db;
 $games=[2,3,4,5,6,7,12];
 $db->exec('CREATE TEMPORARY TABLE u5_broad_name_old(
  file_id BIGINT UNSIGNED NOT NULL,name_key_hash BINARY(16) NOT NULL,
  name_key_length INT UNSIGNED NOT NULL,name_key_fingerprint BINARY(32) NOT NULL,
  first_name_index INT UNSIGNED NOT NULL,
  PRIMARY KEY(file_id,name_key_fingerprint),
  KEY lookup(name_key_hash,name_key_length,name_key_fingerprint,file_id)) ENGINE=InnoDB');
 $tables[]='u5_broad_name_old';
 $db->exec('CREATE TEMPORARY TABLE u5_broad_object_old(
  file_id BIGINT UNSIGNED NOT NULL,object_kind TINYINT UNSIGNED NOT NULL,
  object_index INT UNSIGNED NOT NULL,object_name_hash BINARY(16) NOT NULL,
  object_name_length INT UNSIGNED NOT NULL,public_export_hash BINARY(8) NULL,
  PRIMARY KEY(file_id,object_kind,object_index),
  KEY lookup(object_name_hash,object_name_length,file_id,object_kind,object_index)) ENGINE=InnoDB');
 $tables[]='u5_broad_object_old';
 $db->exec('CREATE TEMPORARY TABLE u5_broad_dict(
  name_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name_key_hash BINARY(16) NOT NULL,name_key_length INT UNSIGNED NOT NULL,
  name_key_fingerprint BINARY(32) NOT NULL,
  PRIMARY KEY(name_id),UNIQUE KEY fingerprint(name_key_fingerprint),
  KEY lookup(name_key_hash,name_key_length,name_key_fingerprint)) ENGINE=InnoDB');
 $tables[]='u5_broad_dict';
 $db->exec('CREATE TEMPORARY TABLE u5_broad_name_new(
  file_id BIGINT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,
  first_name_index INT UNSIGNED NOT NULL,
  PRIMARY KEY(file_id,name_id),KEY lookup(name_id,file_id)) ENGINE=InnoDB');
 $tables[]='u5_broad_name_new';
 $db->exec('CREATE TEMPORARY TABLE u5_broad_object_new(
  file_id BIGINT UNSIGNED NOT NULL,object_kind TINYINT UNSIGNED NOT NULL,
  object_index INT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,
  public_export_hash BINARY(8) NULL,
  PRIMARY KEY(file_id,object_kind,object_index),KEY lookup(name_id,file_id,object_kind,object_index)) ENGINE=InnoDB');
 $tables[]='u5_broad_object_new';
 $select=$db->prepare('SELECT file_id FROM ue_uedb5_files WHERE game_id=? ORDER BY file_id LIMIT 5 OFFSET ?');
 $total=$db->prepare('SELECT COUNT(*) FROM ue_uedb5_files WHERE game_id=?');
 $coverage=[];$allIds=[];
 foreach($games as $game){
  $total->execute([$game]);$n=(int)$total->fetchColumn();
  $ids=[];
  foreach([0,0.25,0.5,0.75,0.99] as $fraction){
   $offset=max(0,min($n-1,(int)floor($n*$fraction)));
   $select->bindValue(1,$game,PDO::PARAM_INT);
   $select->bindValue(2,$offset,PDO::PARAM_INT);
   $select->execute();
   foreach($select->fetchAll(PDO::FETCH_COLUMN) as $id)$ids[(int)$id]=true;
  }
  $idList=implode(',',array_keys($ids));
  if($idList==='')continue;
  $db->exec('INSERT INTO u5_broad_name_old SELECT file_id,name_key_hash,name_key_length,name_key_fingerprint,first_name_index
   FROM ue_uedb5_name_candidates WHERE file_id IN ('.$idList.')
   ORDER BY file_id,name_key_fingerprint LIMIT '.$perGame);
  $db->exec('INSERT INTO u5_broad_object_old SELECT file_id,object_kind,object_index,object_name_hash,object_name_length,public_export_hash
   FROM ue_uedb5_object_candidates WHERE file_id IN ('.$idList.')
   ORDER BY file_id,object_kind,object_index LIMIT '.$perGame);
  $coverage[$game]=['sample_files'=>count($ids)];
  foreach($ids as $id=>$_)$allIds[$id]=1;
 }
 $count=static fn(string $table):int=>(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
 $nameRows=$count('u5_broad_name_old');
 $objectRows=$count('u5_broad_object_old');
 if($nameRows<100||$objectRows<100)throw new RuntimeException('Insufficient sampled candidate rows.');
 // Retain full SHA-256 identity in the shared sample dictionary. Object rows
 // can use it only when the live dictionary maps each hash+length to exactly one fingerprint.
 $db->exec('INSERT IGNORE INTO u5_broad_dict(name_key_hash,name_key_length,name_key_fingerprint)
   SELECT name_key_hash,name_key_length,name_key_fingerprint FROM u5_broad_name_old');
 $db->exec('INSERT IGNORE INTO u5_broad_dict(name_key_hash,name_key_length,name_key_fingerprint)
   SELECT s.key_hash,s.key_length,s.key_fingerprint FROM ue_uedb5_search_keys s
   JOIN (SELECT DISTINCT object_name_hash,object_name_length FROM u5_broad_object_old) o
   ON s.key_hash=o.object_name_hash AND s.key_length=o.object_name_length');
 $missing=(int)$db->query('SELECT COUNT(*) FROM
   (SELECT DISTINCT o.object_name_hash,o.object_name_length FROM u5_broad_object_old o
    LEFT JOIN u5_broad_dict d ON d.name_key_hash=o.object_name_hash AND d.name_key_length=o.object_name_length
    WHERE d.name_id IS NULL) z')->fetchColumn();
 $ambiguous=(int)$db->query('SELECT COUNT(*) FROM
   (SELECT o.object_name_hash,o.object_name_length FROM
     (SELECT DISTINCT object_name_hash,object_name_length FROM u5_broad_object_old) o
     JOIN u5_broad_dict d ON d.name_key_hash=o.object_name_hash AND d.name_key_length=o.object_name_length
     GROUP BY o.object_name_hash,o.object_name_length HAVING COUNT(DISTINCT d.name_key_fingerprint)>1) z')->fetchColumn();
 if($missing||$ambiguous)throw new RuntimeException('Fail-closed object identities: missing='.$missing.' ambiguous='.$ambiguous);
 $db->exec('INSERT INTO u5_broad_name_new
   SELECT n.file_id,d.name_id,n.first_name_index FROM u5_broad_name_old n
   JOIN u5_broad_dict d ON d.name_key_fingerprint=n.name_key_fingerprint
   AND d.name_key_hash=n.name_key_hash AND d.name_key_length=n.name_key_length');
 $db->exec('INSERT INTO u5_broad_object_new
   SELECT o.file_id,o.object_kind,o.object_index,d.name_id,o.public_export_hash
   FROM u5_broad_object_old o JOIN u5_broad_dict d
   ON d.name_key_hash=o.object_name_hash AND d.name_key_length=o.object_name_length');
 $nameCompact=$count('u5_broad_name_new');$objectCompact=$count('u5_broad_object_new');
 $nameDiff=(int)$db->query('SELECT COUNT(*) FROM u5_broad_name_old n
   LEFT JOIN u5_broad_name_new c ON c.file_id=n.file_id AND c.first_name_index=n.first_name_index
   LEFT JOIN u5_broad_dict d ON d.name_id=c.name_id AND d.name_key_fingerprint=n.name_key_fingerprint
   WHERE d.name_id IS NULL')->fetchColumn();
 $objDiff=(int)$db->query('SELECT COUNT(*) FROM u5_broad_object_old o
   LEFT JOIN u5_broad_object_new c ON c.file_id=o.file_id AND c.object_kind=o.object_kind
   AND c.object_index=o.object_index AND c.public_export_hash<=>o.public_export_hash
   LEFT JOIN u5_broad_dict d ON d.name_id=c.name_id
   AND d.name_key_hash=o.object_name_hash AND d.name_key_length=o.object_name_length
   WHERE d.name_id IS NULL')->fetchColumn();
 $names=$db->query('SELECT name_key_hash,name_key_length,name_key_fingerprint FROM u5_broad_dict ORDER BY name_id LIMIT '.$keyCount)->fetchAll(PDO::FETCH_NUM);
 $old=$db->prepare('SELECT file_id,first_name_index FROM u5_broad_name_old
   WHERE name_key_hash=? AND name_key_length=? AND name_key_fingerprint=? ORDER BY file_id,first_name_index');
 $new=$db->prepare('SELECT c.file_id,c.first_name_index FROM u5_broad_dict d
   JOIN u5_broad_name_new c ON c.name_id=d.name_id
   WHERE d.name_key_hash=? AND d.name_key_length=? AND d.name_key_fingerprint=? ORDER BY c.file_id,c.first_name_index');
 $failures=[];$times=[0.,0.];
 foreach($names as $i=>$args){
   $t=microtime(true);$old->execute($args);$a=$old->fetchAll(PDO::FETCH_NUM);$times[0]+=microtime(true)-$t;
   $t=microtime(true);$new->execute($args);$b=$new->fetchAll(PDO::FETCH_NUM);$times[1]+=microtime(true)-$t;
   if($a!==$b)$failures[]=$i;
 }
 $result+=['sample_files'=>count($allIds),'game_sampling'=>$coverage,
  'name_rows'=>$nameRows,'object_rows'=>$objectRows,'dictionary_rows'=>$count('u5_broad_dict'),
  'compact_name_rows'=>$nameCompact,'compact_object_rows'=>$objectCompact,
  'missing_dictionary_keys'=>$missing,'ambiguous_dictionary_keys'=>$ambiguous,
  'name_row_mismatches'=>$nameDiff,'object_row_mismatches'=>$objDiff,
  'exact_name_lookups'=>count($names),'name_lookup_mismatches'=>$failures,
  'old_lookup_seconds'=>round($times[0],6),'compact_lookup_seconds'=>round($times[1],6),
  'warning'=>'Sample-only evidence: not full corpus, not a production migration and not actual InnoDB disk-byte measurement.'];
 $result['ok']=$nameRows===$nameCompact&&$objectRows===$objectCompact
  &&$nameDiff===0&&$objDiff===0&&$failures===[];
}catch(Throwable $e){$result['error']=$e->getMessage();}
finally{if($db instanceof PDO)foreach(array_reverse($tables) as $table)
 try{$db->exec('DROP TEMPORARY TABLE IF EXISTS '.$table);}catch(Throwable){}}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit(($result['ok']??false)?0:2);
