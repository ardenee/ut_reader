#!/usr/bin/env php
<?php
/** Bounded object candidate dictionary shadow benchmark. No persistent SQL writes. */
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
$opts=getopt('', ['rows::','repetitions::']);
$limit=max(1,min(20000,(int)($opts['rows']??10000)));
$reps=max(1,min(20,(int)($opts['repetitions']??3)));
$ids=[111139,1363209,113253,1362900,148919,1360665,111460,1363138,112300,1362977,113526,1359117,110902,1362870];
$pdo=null;$out=['ok'=>false,'read_only_base_tables'=>true,'session_temporary_tables_only'=>true,'row_limit'=>$limit];
try {
 $pdo=catalog_bootstrap(false)->db;
 $pdo->exec('CREATE TEMPORARY TABLE u5_obj_old(
 file_id BIGINT UNSIGNED NOT NULL, object_kind TINYINT UNSIGNED NOT NULL, object_index INT UNSIGNED NOT NULL,
 object_name_hash BINARY(16) NOT NULL,object_name_length INT UNSIGNED NOT NULL,public_export_hash BINARY(8) DEFAULT NULL,
 PRIMARY KEY(file_id,object_kind,object_index),
 KEY by_object_name(object_name_hash,object_name_length,file_id,object_kind,object_index),
 KEY by_public_export(file_id,object_kind,public_export_hash,object_index)) ENGINE=InnoDB');
 $pdo->exec('CREATE TEMPORARY TABLE u5_obj_dict(
 name_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,object_name_hash BINARY(16) NOT NULL,object_name_length INT UNSIGNED NOT NULL,
 PRIMARY KEY(name_id),UNIQUE KEY identity_key(object_name_hash,object_name_length)) ENGINE=InnoDB');
 $pdo->exec('CREATE TEMPORARY TABLE u5_obj_new(
 file_id BIGINT UNSIGNED NOT NULL,object_kind TINYINT UNSIGNED NOT NULL,object_index INT UNSIGNED NOT NULL,
 name_id BIGINT UNSIGNED NOT NULL,public_export_hash BINARY(8) DEFAULT NULL,
 PRIMARY KEY(file_id,object_kind,object_index),KEY by_object_name(name_id,file_id,object_kind,object_index),
 KEY by_public_export(file_id,object_kind,public_export_hash,object_index)) ENGINE=InnoDB');
 $pdo->exec('INSERT INTO u5_obj_old SELECT file_id,object_kind,object_index,object_name_hash,object_name_length,public_export_hash
 FROM ue_uedb5_object_candidates WHERE file_id IN ('.implode(',',$ids).')
 ORDER BY file_id,object_kind,object_index LIMIT '.$limit);
 $pdo->exec('INSERT INTO u5_obj_dict(object_name_hash,object_name_length)
 SELECT object_name_hash,object_name_length FROM u5_obj_old GROUP BY object_name_hash,object_name_length');
 $pdo->exec('INSERT INTO u5_obj_new(file_id,object_kind,object_index,name_id,public_export_hash)
 SELECT o.file_id,o.object_kind,o.object_index,d.name_id,o.public_export_hash FROM u5_obj_old o
 JOIN u5_obj_dict d ON d.object_name_hash=o.object_name_hash AND d.object_name_length=o.object_name_length');
 $count=static fn(string $table)=>(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
 $rows=$count('u5_obj_old');$newRows=$count('u5_obj_new');$keys=$count('u5_obj_dict');
 if(!$rows||$rows!==$newRows)throw new RuntimeException('Source/compact cardinality mismatch');
 $lookups=$pdo->query('SELECT object_name_hash,object_name_length,name_id FROM u5_obj_dict ORDER BY name_id LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
 $old=$pdo->prepare('SELECT file_id,object_kind,object_index FROM u5_obj_old
 WHERE object_name_hash=? AND object_name_length=? ORDER BY file_id,object_kind,object_index');
 $new=$pdo->prepare('SELECT file_id,object_kind,object_index FROM u5_obj_new
 WHERE name_id=? ORDER BY file_id,object_kind,object_index');
 $fails=[];$oldSec=0.;$newSec=0.;$checks=0;
 foreach($lookups as $key){for($i=0;$i<$reps;$i++){
 $start=microtime(true);$old->execute([$key['object_name_hash'],$key['object_name_length']]);$a=$old->fetchAll(PDO::FETCH_NUM);$oldSec+=microtime(true)-$start;
 $start=microtime(true);$new->execute([$key['name_id']]);$b=$new->fetchAll(PDO::FETCH_NUM);$newSec+=microtime(true)-$start;
 if($a!==$b)$fails[]=['key'=>bin2hex($key['object_name_hash']),'round'=>$i,'old'=>count($a),'new'=>count($b)];
 $checks++;
 }}
 $out+=['sample_rows'=>$rows,'dictionary_keys'=>$keys,'compact_rows'=>$newRows,'exact_lookup_comparisons'=>$checks,
 'mismatches'=>$fails,'old_query_seconds'=>round($oldSec,6),'new_query_seconds'=>round($newSec,6),
 'warning'=>'Object candidate table lacks SHA-256 fingerprint: hash+length dictionary alone is not collision-safe. This is a feasibility benchmark, NOT a source-equivalent production replacement. Temporary-table bytes are not reliably exposed by information_schema.'];
 $out['ok']=!$fails;
}catch(Throwable $e){$out['error']=$e->getMessage();}
finally{if($pdo instanceof PDO)foreach(['u5_obj_new','u5_obj_dict','u5_obj_old'] as $t)try{$pdo->exec('DROP TEMPORARY TABLE IF EXISTS '.$t);}catch(Throwable){}}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($out['ok']?0:2);
