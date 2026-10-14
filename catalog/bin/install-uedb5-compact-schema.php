#!/usr/bin/env php
<?php
/** Installs the four opt-in compact V5 tables. No legacy table changes.
 * Requires --apply. Safe to repeat after all four tables are present.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!in_array('--apply',$argv,true)){
 fwrite(STDERR,"Usage: php install-uedb5-compact-schema.php --apply\n");
 exit(2);
}
require_once dirname(__DIR__).'/bootstrap.php';
$definitions=[
 'ue_uedb5_compact_name_dictionary',
 'ue_uedb5_compact_name_candidates',
 'ue_uedb5_compact_object_candidates',
 'ue_uedb5_compact_backfill_progress'
];
$db=catalog_bootstrap(false)->db;
$check=$db->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
$executed=[];$error=null;
try{
 $sources=[
  dirname(__DIR__).'/sql/uedb5-compact-candidate-v2-schema.sql',
  dirname(__DIR__).'/sql/uedb5-compact-backfill-progress.sql'
 ];
 $statements=[];
 foreach($sources as $source){
  $sql=file_get_contents($source);
  if(!is_string($sql)||$sql==='')throw new RuntimeException('Missing schema input: '.$source);
  $sql=preg_replace('/^\s*--[^\n]*(?:\n|$)/m','',$sql);
  foreach(explode(';',$sql) as $statement){
   $statement=trim($statement);
   if($statement==='')continue;
   if(!preg_match('/^CREATE TABLE (ue_uedb5_compact_[a-z_]+)\s*\(/i',$statement,$matches))
    throw new RuntimeException('Unexpected schema statement');
   $statements[$matches[1]]=$statement;
  }
 }
 if(array_keys($statements)!==$definitions)throw new RuntimeException('Unexpected schema table order');
 foreach($definitions as $name){
  $check->execute([$name]);
  if($check->fetchColumn()!==false){
   // Existing tables may already contain migrated rows; never replace them.
   $executed[]=['name'=>$name,'status'=>'already_exists'];
   continue;
  }
  $db->exec($statements[$name]);
  $executed[]=['name'=>$name,'status'=>'created'];
 }
}catch(Throwable $e){$error=$e->getMessage();}
echo json_encode(['ok'=>$error===null,'created_or_found'=>$executed,
 'production_old_candidate_tables_unchanged'=>true,'error'=>$error],JSON_PRETTY_PRINT),PHP_EOL;
exit($error===null?0:2);
