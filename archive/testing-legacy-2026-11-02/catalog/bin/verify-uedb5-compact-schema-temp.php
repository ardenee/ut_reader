#!/usr/bin/env php
<?php
/**
 * Validate compact-schema SQL shape in session-only MySQL TEMPORARY tables.
 * FOREIGN KEY constraints are excluded: MySQL TEMPORARY tables do not support FKs.
 * No permanent DDL, no production row writes.
 */
declare(strict_types=1);
if (PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
$file=dirname(__DIR__).'/sql/uedb5-compact-candidate-v2-schema.sql';
$sql=file_get_contents($file);
if(!is_string($sql))throw new RuntimeException('Missing compact schema file.');
$definitions=[];
foreach(explode(';',$sql) as $statement){
 $statement=trim(preg_replace('/^\s*--[^\n]*\n/m','',$statement));
 if($statement==='')continue;
 if(!preg_match('/^CREATE TABLE (ue_uedb5_compact_[a-z_]+)\s*\(/i',$statement,$m))
   throw new RuntimeException('Unexpected DDL statement.');
 $name=$m[1];
 if(isset($definitions[$name]))throw new RuntimeException('Duplicate DDL table.');
 // Foreign keys are not supported on TEMPORARY tables; remove complete FK blocks.
 $statement=preg_replace('/,\s*CONSTRAINT\s+\w+\s+FOREIGN KEY\s*\([^)]*\)\s+REFERENCES\s+\w+\s*\([^)]*\)(?:\s+ON DELETE CASCADE)?/i','',$statement);
 $tempName='u5_schema_probe_'.substr($name,strlen('ue_uedb5_compact_'));
 $statement=str_replace('CREATE TABLE '.$name,'CREATE TEMPORARY TABLE '.$tempName,$statement);
 $definitions[$tempName]=$statement;
}
if(count($definitions)!==3)throw new RuntimeException('Expected three compact DDL declarations.');
$db=catalog_bootstrap(false)->db;
$created=[];$result=['ok'=>false,'temporary_tables_only'=>true,'schema_file'=>$file];
try{
 foreach($definitions as $name=>$ddl){
  $db->exec($ddl);
  $created[]=$name;
 }
 $indexes=[];
 foreach($created as $table){
  $rows=$db->query('SHOW INDEX FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
  if(!$rows)throw new RuntimeException('No indexes for '.$table);
  $indexes[$table]=array_values(array_unique(array_column($rows,'Key_name')));
 }
 $dict='u5_schema_probe_name_dictionary';
 $names='u5_schema_probe_name_candidates';
 $objects='u5_schema_probe_object_candidates';
 if(!in_array('uq_uedb5_compact_name_fingerprint',$indexes[$dict],true)
    || !in_array('idx_uedb5_compact_names_lookup',$indexes[$names],true)
    || !in_array('idx_uedb5_compact_objects_lookup',$indexes[$objects],true)
    || !in_array('idx_uedb5_compact_public_export',$indexes[$objects],true))
   throw new RuntimeException('Missing required unique or lookup index.');
 $result=['ok'=>true,'temporary_tables_only'=>true,'ddl_tables_created'=>count($created),
    'indexes'=>$indexes,'foreign_keys'=>'Not validated: MySQL TEMPORARY tables do not support foreign keys',
    'production_schema_unchanged'=>true];
}catch(Throwable $e){$result['error']=$e->getMessage();}
finally{foreach(array_reverse($created) as $table)
 try{$db->exec('DROP TEMPORARY TABLE IF EXISTS '.$table);}catch(Throwable){}}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($result['ok']?0:2);
