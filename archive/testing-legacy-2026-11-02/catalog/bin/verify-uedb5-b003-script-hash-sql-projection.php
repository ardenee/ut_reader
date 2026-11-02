<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyProjectionBuilder as Builder;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5DependencyProjectionPublisher as Publisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract as Sql;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader as Toc;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenScriptObjectsReader as Cooked;
$root=dirname(__DIR__,2);
$checks=[];$fail=[];
$check=static function(string $id,bool $pass)use(&$checks,&$fail):void{
 $checks[$id]=$pass;if(!$pass)$fail[]=$id;
};
$sourceRoot=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\');
$cpp=$sourceRoot.'/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$header=$sourceRoot.'/Engine/Source/Runtime/CoreUObject/Public/Serialization/AsyncLoading2.h';
$src=is_file($cpp)?(string)file_get_contents($cpp):'';
$head=is_file($header)?(string)file_get_contents($header):'';
$check('d027_epic_runtime_script_source_pinned',hash('sha256',$src)==='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9');
$check('d027_epic_package_object_index_pinned',hash('sha256',$head)==='088f00a8c970458e4bb717490ac4d50361d635ddf8cb0b7b1a5c93ff4d9feef2');
$check('d027_epic_script_identity_is_distinct_62_bit_hash',
 str_contains($src,'ScriptObjects.FindRef(GlobalIndex)')
 &&str_contains($src,'FPackageObjectIndex::FromScriptPath(FullName)')
 &&str_contains($src,'Hash &= ~(3ull << 62ull)')
 &&str_contains($head,'FPackageObjectIndex(ScriptImport, GenerateImportHashFromObjectPath(ScriptObjectPath))'));
$check('d027_sql_key_contract_distinct_from_public_export',
 Sql::OBJECT_KEY_SCRIPT_IMPORT_HASH!==Sql::OBJECT_KEY_PUBLIC_EXPORT_HASH
 &&Sql::OBJECT_KEY_SCRIPT_IMPORT_HASH!==Sql::OBJECT_KEY_NAME
 &&Sql::OBJECT_KEY_SCRIPT_IMPORT_HASH===3);
$git='git -c '.escapeshellarg('safe.directory='.$root).' -C '.escapeshellarg($root)
 .' show '.escapeshellarg('2422faec:catalog/src/Infrastructure/Metadata/Uedb5DependencyProjectionBuilder.php');
$proc=proc_open($git,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$old='';$exit=127;
if(is_resource($proc)){
 $old=stream_get_contents($pipes[1]);fclose($pipes[1]);
 $error=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($proc);
}
$check('d027_original_repository_projection_is_pinned',
 $exit===0&&str_contains($old,"if ((string)($".'row[\'dependency_kind\'] ?? \'\') === \'ScriptImport\') {')
 &&str_contains($old,'return [null, null];'));
if($exit===0){
 $old=str_replace('final class Uedb5DependencyProjectionBuilder','final class Uedb5DependencyProjectionBuilderBeforeD027',$old);
 $tmp=sys_get_temp_dir().'/uedb5-d027-original-'.bin2hex(random_bytes(6)).'.php';
 file_put_contents($tmp,$old);try{require $tmp;}finally{@unlink($tmp);}
}
$oldClass='UnrealDb\\Catalog\\Infrastructure\\Metadata\\Uedb5DependencyProjectionBuilderBeforeD027';
$hash='167E29465232A0A9';
$make=static fn(string $source,int $index,string $value):array=>[
 'source_section'=>$source,'source_index'=>$index,
 'dependency_kind'=>'ScriptImport','dependency_class'=>'script','hard'=>true,
 'outcome'=>'unresolved','reason_code'=>'script_object_runtime_context_unavailable',
 'required_package_id'=>null,'required_object_identity'=>$value,
 'selected_provider_file_id'=>null,'selected_provider_object'=>null
];
$snapshot=[
 'file'=>['id'=>993105,'game_id'=>7,'package_name'=>'/Script/B003'],
 'section_schemas'=>['imports'=>'ue5.zen.package-object-index.v1'],
 'sections'=>['dependency_results'=>[
  $make('imports',0,$hash),
  $make('optional_segment_imports',0,$hash),
  $make('dependency_bundle_entries',1,$hash)
 ]]
];
$oldResult=class_exists($oldClass)?$oldClass::build($snapshot):['dependency_edges'=>[]];
$check('d027_original_projection_silently_dropped_source_hash',
 count($oldResult['dependency_edges'])===3
 &&($oldResult['dependency_edges'][0]['required_object_key_kind']??null)===null
 &&($oldResult['dependency_edges'][0]['required_object_key']??null)===null);
$new=Builder::build($snapshot);$edges=$new['dependency_edges'];
$check('d027_fixed_main_optional_and_bundle_preserve_script_key',
 count($edges)===3
 &&array_all($edges,static fn(array $e):bool=>
   $e['required_object_key_kind']===Sql::OBJECT_KEY_SCRIPT_IMPORT_HASH
   &&strtoupper(bin2hex((string)$e['required_object_key']))===$hash));
$check('d027_script_key_has_no_package_or_resolved_provider',
 count($new['dependency_packages'])===0
 &&array_all($edges,static fn(array $e):bool=>
   $e['required_package_key']===null && $e['resolved_file_id']===null
   &&$e['resolved_object_kind']===null &&$e['resolved_object_index']===null
   &&$e['outcome']===Sql::OUTCOME_UNRESOLVED));
$check('d027_stored_identity_exact_eight_bytes',
 strlen((string)$edges[0]['required_object_key'])===8
 &&strtoupper(bin2hex((string)$edges[0]['required_object_key']))===$hash);
$validSamples=['0000000000000000','3FFFFFFFFFFFFFFF'];
foreach($validSamples as $i=>$test){
 $a=$snapshot;$a['sections']['dependency_results']=[$make('imports',0,$test)];
 $got=Builder::build($a)['dependency_edges'][0];
 $check('d027_valid_62bit_boundary_'.$i,$got['required_object_key_kind']===Sql::OBJECT_KEY_SCRIPT_IMPORT_HASH
  &&strtoupper(bin2hex((string)$got['required_object_key']))===$test);
}
$invalid=['4000000000000000','FFFFFFFFFFFFFFFF','123','FFFFFFFFFFFFFFFFFFFF','xx00000000000000'];
foreach($invalid as $i=>$test){
 $a=$snapshot;$a['sections']['dependency_results']=[$make('imports',0,$test)];
 $rejected=false;
 try{Builder::build($a);}catch(Throwable){$rejected=true;}
 $check('d027_non_62bit_identity_rejected_'.$i,$rejected);
}
$empty=$snapshot;$empty['sections']['dependency_results']=[$make('imports',0,'')];
$nullEdge=Builder::build($empty)['dependency_edges'][0];
$check('d027_missing_hash_does_not_invent_identity',
 $nullEdge['required_object_key_kind']===null&&$nullEdge['required_object_key']===null);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_uedb5_dependency_edges(
 file_id INTEGER,source_kind INTEGER,source_index INTEGER,classification INTEGER,outcome INTEGER,
 required_package_key_kind INTEGER,required_package_key BLOB,required_object_key_kind INTEGER,
 required_object_key BLOB,resolved_file_id INTEGER,resolved_object_kind INTEGER,
 resolved_object_index INTEGER,PRIMARY KEY(file_id,source_kind,source_index))');
$db->exec('CREATE TABLE ue_uedb5_dependency_packages(
 game_id INTEGER,file_id INTEGER,package_key_kind INTEGER,package_key BLOB,
 required_package_name TEXT,dependency_count INTEGER,resolved_count INTEGER,
 missing_count INTEGER,package_only_count INTEGER,common_count INTEGER,
 unresolved_count INTEGER,hard_missing_count INTEGER,nonhard_missing_count INTEGER,
 summary_outcome INTEGER,provider_file_id INTEGER,updated_at TEXT,
 PRIMARY KEY(file_id,package_key_kind,package_key))');
$publisher=new Publisher($db);
$published=$publisher->publish($snapshot);
$stored=$db->query('SELECT source_kind,source_index,required_object_key_kind,hex(required_object_key) AS script_hash,resolved_file_id,required_package_key,outcome FROM ue_uedb5_dependency_edges ORDER BY source_kind,source_index')->fetchAll(PDO::FETCH_ASSOC);
$check('d027_sql_publisher_three_script_dependencies',
 $published['dependency_edges']===3&&$published['dependency_packages']===0
 &&count($stored)===3
 &&array_all($stored,static fn(array $e):bool=>
   (int)$e['required_object_key_kind']===Sql::OBJECT_KEY_SCRIPT_IMPORT_HASH
   &&(string)$e['script_hash']===$hash
   &&$e['resolved_file_id']===null&&$e['required_package_key']===null
   &&(int)$e['outcome']===Sql::OUTCOME_UNRESOLVED));
$again=$publisher->publish($snapshot);
$check('d027_sql_republication_is_idempotent',
 $again['dependency_edges']===3
 &&(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_edges')->fetchColumn()===3);
$fixture=(string)(getenv('UNREALDB_B001_REAL_GLOBAL_TOC')?:'//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/global.utoc');
try{
 $toc=new Toc($fixture);$cooked=Cooked::fromToc($toc);
 $index='567E29465232A0A9';
 $authentic=array_key_exists($index,$cooked['objects'])
  &&($cooked['objects'][$index]['name']??'')==='GetRecipes';
 $a=$snapshot;$a['sections']['dependency_results']=[$make('imports',0,'167E29465232A0A9')];
 $actual=Builder::build($a)['dependency_edges'][0];
 $check('d027_authentic_cooked_script_hash_index_payload',
 $authentic&&$toc->tocSha256()==='0f72c200169b09519ac727d0149ef4f3c35d6234f1326c7efe5ee0bcf04095e6'
 &&strtoupper(bin2hex((string)$actual['required_object_key']))==='167E29465232A0A9');
}catch(Throwable $e){$fail[]='d027_authentic_fixture_exception';$authError=$e->getMessage();}
echo json_encode(['ok'=>$fail===[],'difference'=>'UE5-D027',
 'checks'=>$checks,'failures'=>$fail,'live_epic_script_registry_executed'=>false,
 'source_cooked_authentication_only'=>true,'auth_error'=>$authError??null],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
