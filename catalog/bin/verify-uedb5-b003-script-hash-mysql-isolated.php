<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
/** Real PDO/MySQL insertion and round-trip of script import identity.
 *  All writes use session temporary tables shadowing the real table names.
 */
require dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract as Sql;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5DependencyProjectionPublisher as Publisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyProjectionBuilder as Builder;
$checks=[];$fail=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fail):void{
 $checks[$name]=$ok;if(!$ok)$fail[]=$name;
};
$app=catalog_bootstrap(false);
$db=$app->db;
$tables=['ue_uedb5_dependency_edges','ue_uedb5_dependency_packages'];
$created=[];
$ddl=[
 'CREATE TEMPORARY TABLE ue_uedb5_dependency_edges (
  file_id BIGINT UNSIGNED NOT NULL,source_kind TINYINT UNSIGNED NOT NULL,
  source_index INT UNSIGNED NOT NULL,classification TINYINT UNSIGNED NOT NULL,
  outcome TINYINT UNSIGNED NOT NULL,required_package_key_kind TINYINT UNSIGNED NULL,
  required_package_key VARBINARY(16) NULL,required_object_key_kind TINYINT UNSIGNED NULL,
  required_object_key VARBINARY(16) NULL,resolved_file_id BIGINT UNSIGNED NULL,
  resolved_object_kind TINYINT UNSIGNED NULL,resolved_object_index INT UNSIGNED NULL,
  PRIMARY KEY(file_id,source_kind,source_index)) ENGINE=InnoDB',
 'CREATE TEMPORARY TABLE ue_uedb5_dependency_packages (
  game_id INT UNSIGNED NOT NULL,file_id BIGINT UNSIGNED NOT NULL,
  package_key_kind TINYINT UNSIGNED NOT NULL,package_key VARBINARY(16) NOT NULL,
  required_package_name VARCHAR(512) NOT NULL DEFAULT \'\',
  dependency_count INT UNSIGNED NOT NULL DEFAULT 0,
  resolved_count INT UNSIGNED NOT NULL DEFAULT 0,
  missing_count INT UNSIGNED NOT NULL DEFAULT 0,
  package_only_count INT UNSIGNED NOT NULL DEFAULT 0,
  common_count INT UNSIGNED NOT NULL DEFAULT 0,
  unresolved_count INT UNSIGNED NOT NULL DEFAULT 0,
  hard_missing_count INT UNSIGNED NOT NULL DEFAULT 0,
  nonhard_missing_count INT UNSIGNED NOT NULL DEFAULT 0,
  summary_outcome TINYINT UNSIGNED NOT NULL,provider_file_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY(file_id,package_key_kind,package_key)) ENGINE=InnoDB'
];
$exception=null;
try{
 $driver=(string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
 $check('d027_mysql_native_driver',$driver==='mysql');
 foreach($ddl as $i=>$sql){$db->exec($sql);$created[]=$tables[$i];}
 $check('d027_mysql_session_temp_tables_created',count($created)===2);
 $hash='167E29465232A0A9';$fileId=993105;
 $make=static fn(string $section,int $index):array=>[
  'source_section'=>$section,'source_index'=>$index,
  'dependency_kind'=>'ScriptImport','dependency_class'=>'script','hard'=>true,
  'outcome'=>'unresolved','required_package_id'=>null,
  'required_object_identity'=>$hash,'selected_provider_file_id'=>null,
  'selected_provider_object'=>null,
  'reason_code'=>'script_object_runtime_context_unavailable'
 ];
 $snapshot=[
  'file'=>['id'=>$fileId,'game_id'=>7,'package_name'=>'/Script/B003SQL'],
  'section_schemas'=>['imports'=>'ue5.zen.package-object-index.v1'],
  'sections'=>['dependency_results'=>[
    $make('imports',0),$make('optional_segment_imports',0),
    $make('dependency_bundle_entries',0)
  ]]
 ];
 $expected=Builder::build($snapshot);
 $check('d027_mysql_builder_three_distinct_source_rows',
  count($expected['dependency_edges'])===3
  &&count($expected['dependency_packages'])===0);
 $pub=new Publisher($db);
 $result=$pub->publish($snapshot);
 $check('d027_mysql_publication_exact_counts',
  $result['dependency_edges']===3&&$result['dependency_packages']===0);
 $statement=$db->prepare(
  'SELECT source_kind,source_index,required_object_key_kind,HEX(required_object_key) AS script_hash,'
  .' LENGTH(required_object_key) AS key_length,required_package_key,resolved_file_id,'
  .' resolved_object_kind,resolved_object_index,outcome'
  .' FROM ue_uedb5_dependency_edges WHERE file_id=? ORDER BY source_kind,source_index');
 $statement->execute([$fileId]);$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
 $check('d027_mysql_source_hash_round_trip_exact',
  count($rows)===3 && array_all($rows,static fn(array $e):bool=>
    (int)$e['required_object_key_kind']===Sql::OBJECT_KEY_SCRIPT_IMPORT_HASH
    &&strtoupper((string)$e['script_hash'])===$hash
    &&(int)$e['key_length']===8));
 $check('d027_mysql_null_provider_and_no_package_key',
  count($rows)===3 && array_all($rows,static fn(array $e):bool=>
    $e['required_package_key']===null
    &&$e['resolved_file_id']===null
    &&$e['resolved_object_kind']===null
    &&$e['resolved_object_index']===null
    &&(int)$e['outcome']===Sql::OUTCOME_UNRESOLVED));
 $check('d027_mysql_own_temp_package_table_unmodified',
  (int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_packages')->fetchColumn()===0);
 $pub->publish($snapshot);
 $check('d027_mysql_republish_idempotent',
  (int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_edges WHERE file_id='.$fileId)->fetchColumn()===3);
 $db->beginTransaction();
 $temporary=$snapshot;$temporary['sections']['dependency_results']=[];
 $pub->publish($temporary);
 $db->rollBack();
 $check('d027_mysql_transaction_rollback_preserves_source_hash',
  (int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_edges WHERE file_id='.$fileId)->fetchColumn()===3);
}catch(Throwable $e){$exception=$e->getMessage();$fail[]='d027_mysql_exception';}
finally{
 if($db->inTransaction())$db->rollBack();
 foreach(array_reverse($created) as $table){
  try{$db->exec('DROP TEMPORARY TABLE IF EXISTS '.$table);}
  catch(Throwable $e){$fail[]='d027_mysql_cleanup';}
 }
}
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail,
 'exception'=>$exception,'persistent_sql_mutations'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
