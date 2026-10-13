#!/usr/bin/env php
<?php
/**
 * Bounded, read-only-base-table feasibility audit for collision-safe
 * object-name dictionary identity. Uses session TEMPORARY tables exclusively.
 * Ambiguous/missing dictionary hashes fail closed, never silently coalesce.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
$opt=getopt('', ['rows::']);
$limit=max(1,min(20000,(int)($opt['rows']??10000)));
$ids=[111139,1363209,113253,1362900,148919,1360665,111460,1363138,112300,1362977,113526,1359117,110902,1362870];
$db=null;
$report=['ok'=>false,'temporary_tables_only'=>true,'sample_limit'=>$limit,'sample_file_ids'=>$ids];
try{
 $db=catalog_bootstrap(false)->db;
 $db->exec('CREATE TEMPORARY TABLE u5_identity_sample (
 file_id BIGINT UNSIGNED NOT NULL, object_kind TINYINT UNSIGNED NOT NULL,
 object_index INT UNSIGNED NOT NULL, object_name_hash BINARY(16) NOT NULL,
 object_name_length INT UNSIGNED NOT NULL, public_export_hash BINARY(8) NULL,
 PRIMARY KEY(file_id,object_kind,object_index),
 KEY lookup_key(object_name_hash,object_name_length)) ENGINE=InnoDB');
 $db->exec('CREATE TEMPORARY TABLE u5_identity_keys (
 object_name_hash BINARY(16) NOT NULL,object_name_length INT UNSIGNED NOT NULL,
 matching_fingerprints BIGINT UNSIGNED NOT NULL,selected_fingerprint BINARY(32) NULL,
 PRIMARY KEY(object_name_hash,object_name_length)) ENGINE=InnoDB');
 $db->exec('INSERT INTO u5_identity_sample
 SELECT file_id,object_kind,object_index,object_name_hash,object_name_length,public_export_hash
 FROM ue_uedb5_object_candidates
 WHERE file_id IN ('.implode(',',$ids).')
 ORDER BY file_id,object_kind,object_index LIMIT '.$limit);
 $db->exec('INSERT INTO u5_identity_keys
 (object_name_hash,object_name_length,matching_fingerprints,selected_fingerprint)
 SELECT s.object_name_hash,s.object_name_length,COUNT(DISTINCT d.key_fingerprint),MIN(d.key_fingerprint)
 FROM (SELECT DISTINCT object_name_hash,object_name_length FROM u5_identity_sample) s
 LEFT JOIN ue_uedb5_search_keys d
 ON d.key_hash=s.object_name_hash AND d.key_length=s.object_name_length
 GROUP BY s.object_name_hash,s.object_name_length');
 $count=static fn(string $query):int=>(int)$db->query($query)->fetchColumn();
 $rows=$count('SELECT COUNT(*) FROM u5_identity_sample');
 $uniqueKeys=$count('SELECT COUNT(*) FROM u5_identity_keys');
 $missing=$count('SELECT COUNT(*) FROM u5_identity_keys WHERE matching_fingerprints=0');
 $ambiguous=$count('SELECT COUNT(*) FROM u5_identity_keys WHERE matching_fingerprints>1');
 $mapped=$count('SELECT COUNT(*) FROM u5_identity_sample s JOIN u5_identity_keys k
 ON k.object_name_hash=s.object_name_hash AND k.object_name_length=s.object_name_length
 WHERE k.matching_fingerprints=1');
 $equivalence=$count('SELECT COUNT(*) FROM u5_identity_sample s JOIN u5_identity_keys k
 ON k.object_name_hash=s.object_name_hash AND k.object_name_length=s.object_name_length
 JOIN ue_uedb5_search_keys d ON d.key_fingerprint=k.selected_fingerprint
 WHERE k.matching_fingerprints=1 AND d.key_hash=s.object_name_hash
 AND d.key_length=s.object_name_length');
 $report+=['sample_rows'=>$rows,'distinct_object_hash_length_pairs'=>$uniqueKeys,
 'missing_dictionary_pairs'=>$missing,'ambiguous_dictionary_pairs'=>$ambiguous,
 'unambiguously_mapped_rows'=>$mapped,'fingerprint_join_equivalent_rows'=>$equivalence,
 'unmappable_rows'=>$rows-$mapped,
 'identity_policy'=>'Fingerprint ID is only authoritative after exact normalized-name identification; hash+length collisions and missing keys require UEDB5 hydration, never arbitrary MIN selection.'];
 $report['ok']=$rows>0&&$missing===0&&$ambiguous===0&&$mapped===$rows&&$equivalence===$rows;
}catch(Throwable $e){$report['error']=$e->getMessage();}
finally{if($db instanceof PDO)foreach(['u5_identity_keys','u5_identity_sample'] as $t)
 try{$db->exec('DROP TEMPORARY TABLE IF EXISTS '.$t);}catch(Throwable){}}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($report['ok']?0:2);
