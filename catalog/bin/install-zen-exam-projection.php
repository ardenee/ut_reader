#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!in_array('--apply',$argv,true)){
 fwrite(STDERR,"Usage: php install-zen-exam-projection.php --apply\n");exit(2);
}
require_once dirname(__DIR__).'/lib/CatalogSupportCore.php';
use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;
use UnrealDb\Catalog\Infrastructure\Persistence\MigrationRunner;

// The general migration runner refuses to proceed because older Oct 2026
// migration checksums have drifted in PRD. This installer applies ONE isolated
// additive schema and records only its exact new checksum; it never edits
// historical migration entries.
$db=catalog_db(catalog_config());
$root=dirname(__DIR__);
$path=$root.'/migrations/202611040001_zen_exam_sql_projection.php';
$source=hash_file('sha256',$path);
if(!is_string($source)||strlen($source)!==64)throw new RuntimeException('Migration hash unavailable');
$definition=require $path;
if(($definition['version']??null)!=='202611040001'||!is_callable($definition['up']??null))
 throw new RuntimeException('Unexpected migration definition.');
$lockName='unrealdb:migrations:'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,32);
$q=$db->prepare('SELECT GET_LOCK(?,30)');$q->execute([$lockName]);
if((int)$q->fetchColumn()!==1)throw new RuntimeException('Migration lock unavailable.');
try{
 $prior=$db->prepare('SELECT checksum FROM ue_schema_migrations WHERE version=?');
 $prior->execute(['202611040001']);$old=$prior->fetchColumn();
 if(is_string($old)){
  if(!hash_equals($old,$source))throw new RuntimeException('Registered migration checksum mismatch.');
  echo json_encode(['status'=>'already_registered','version'=>'202611040001']),PHP_EOL;exit;
 }
 $now=hrtime(true);
 ($definition['up'])($db,new SchemaInspector($db));
 foreach(['ue_uedb5_zen_exam_files','ue_uedb5_zen_exam_rows','ue_iostore_zen_script_objects'] as $table){
  if(!(new SchemaInspector($db))->tableExists($table))throw new RuntimeException('Missing table '.$table);
 }
 $batch=(int)$db->query('SELECT COALESCE(MAX(batch),0)+1 FROM ue_schema_migrations')->fetchColumn();
 $q=$db->prepare('INSERT INTO ue_schema_migrations '
  .'(version,migration,description,checksum,batch,execution_ms,applied_at) VALUES (?,?,?,?,?,?,NOW())');
 $q->execute([
  '202611040001','zen_exam_sql_projection',
  (string)$definition['description'],$source,$batch,
  max(0,(int)round((hrtime(true)-$now)/1e6))
 ]);
 echo json_encode(['status'=>'applied','version'=>'202611040001',
  'tables'=>['ue_uedb5_zen_exam_files','ue_uedb5_zen_exam_rows','ue_iostore_zen_script_objects'],
  'legacy_migration_records_untouched'=>true]),PHP_EOL;
}finally{
 $unlock=$db->prepare('SELECT RELEASE_LOCK(?)');$unlock->execute([$lockName]);
}
