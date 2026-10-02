#!/usr/bin/env php
<?php
declare(strict_types=1);
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';
require_once $root . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyProjectionBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MigrationStatus;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MigrationValidator;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ValidationException;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut99SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;

$checks=[];$failures=[];
$check=static function(string $n,bool $ok)use(&$checks,&$failures):void{$checks[$n]=$ok;if(!$ok)$failures[]=$n;};
$temp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'unrealdb-uedb5-validation-'.bin2hex(random_bytes(5));
@mkdir($temp.DIRECTORY_SEPARATOR.'games'.DIRECTORY_SEPARATOR.'ut99'.DIRECTORY_SEPARATOR.'verified',0775,true);
$sourcePath=$temp.DIRECTORY_SEPARATOR.'games'.DIRECTORY_SEPARATOR.'ut99'.DIRECTORY_SEPARATOR.'verified'.DIRECTORY_SEPARATOR.'source.unr';
$u32=static fn(int $v):string=>pack('V',$v&0xFFFFFFFF);
$compact=static function(int $value):string{$neg=$value<0;$m=abs($value);$f=$m&0x3F;$m>>=6;if($neg)$f|=0x80;if($m!==0)$f|=0x40;$b=chr($f);while($m!==0){$n=$m&0x7F;$m>>=7;if($m!==0)$n|=0x80;$b.=chr($n);}return $b;};
$nameEntry=static fn(string $name):string=>$compact(strlen($name)+1).$name."\0".$u32(0);
$names=$nameEntry('Core').$nameEntry('Package').$nameEntry('Object');
$importOffset=64+strlen($names);
$header=$u32(0x9E2A83C1).$u32(68).$u32(0)
    .$u32(3).$u32(64).$u32(0).$u32(0).$u32(1).$u32($importOffset)
    .$u32(0x11111111).$u32(0x22222222).$u32(0x33333333).$u32(0x44444444)
    .$u32(1).$u32(0).$u32(3);
$import=$compact(0).$compact(1).$u32(0).$compact(2);
file_put_contents($sourcePath,$header.$names.$import);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach([
'CREATE TABLE ue_games(id INTEGER PRIMARY KEY,name TEXT,slug TEXT,profile_id INTEGER)',
'CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,is_active INTEGER,engine_key TEXT,package_version_min INTEGER,package_version_max INTEGER,compatibility_rules_json TEXT,profile_name TEXT)',
'CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,package_name TEXT,original_name TEXT,stored_name TEXT,file_size INTEGER,md5 TEXT,sha1 TEXT,package_version INTEGER,licensee_version INTEGER,name_count INTEGER,import_count INTEGER,export_count INTEGER,scan_status TEXT)',
'CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY,game_id INTEGER,format_version INTEGER,codec INTEGER,compressed_size INTEGER,uncompressed_size INTEGER,payload_sha256 BLOB,block_count INTEGER,package_family TEXT,source_policy TEXT,package_key_kind INTEGER,package_key BLOB,package_name TEXT,section_counts_json TEXT)',
'CREATE TABLE ue_file_package_aliases(id INTEGER PRIMARY KEY,file_id INTEGER,game_id INTEGER,package_name TEXT,original_name TEXT)',
'CREATE TABLE ue_uedb5_provider_keys(source_kind INTEGER,source_id INTEGER,game_id INTEGER,package_key_kind INTEGER,package_key BLOB,file_id INTEGER)',
'CREATE TABLE ue_uedb5_search_keys(key_hash BLOB,key_length INTEGER,key_fingerprint BLOB PRIMARY KEY,normalized_text BLOB)',
'CREATE TABLE ue_uedb5_name_candidates(file_id INTEGER,name_key_hash BLOB,name_key_length INTEGER,name_key_fingerprint BLOB,first_name_index INTEGER)',
'CREATE TABLE ue_uedb5_object_candidates(file_id INTEGER,object_kind INTEGER,object_index INTEGER,object_name_hash BLOB,object_name_length INTEGER,public_export_hash BLOB)',
'CREATE TABLE ue_uedb5_dependency_edges(file_id INTEGER,source_kind INTEGER,source_index INTEGER,classification INTEGER,outcome INTEGER,required_package_key_kind INTEGER,required_package_key BLOB,required_object_key_kind INTEGER,required_object_key BLOB,resolved_file_id INTEGER,resolved_object_kind INTEGER,resolved_object_index INTEGER)',
'CREATE TABLE ue_uedb5_dependency_packages(game_id INTEGER,file_id INTEGER,package_key_kind INTEGER,package_key BLOB,required_package_name TEXT,dependency_count INTEGER,resolved_count INTEGER,missing_count INTEGER,package_only_count INTEGER,common_count INTEGER,unresolved_count INTEGER,hard_missing_count INTEGER,nonhard_missing_count INTEGER,summary_outcome INTEGER,provider_file_id INTEGER)'
] as $sql){$db->exec($sql);}
$db->exec("INSERT INTO ue_games VALUES(3,'Unreal Tournament','ut99',2)");
$db->exec("INSERT INTO ue_game_profiles VALUES(2,1,'UE1',60,69,'[]','UT99 fixture')");
$db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
8001,3,'TestPkg','Test.unr','source.unr',filesize($sourcePath),md5_file($sourcePath),sha1_file($sourcePath),68,0,3,1,0,'verified'
]);
$package=new CatalogUE1PackageReader($sourcePath);
if($package->validatePackage()!==[]){throw new RuntimeException('UE1 validation fixture did not parse cleanly.');}
$snapshot=Uedb5Ut99SnapshotBuilder::build($package,['id'=>8001,'game_id'=>3,'package_name'=>'TestPkg','original_name'=>'Test.unr']);
$writer=new Uedb5MetadataSnapshotWriter($temp);$written=$writer->write($snapshot,16);
$packageKey=md5(CatalogUnrealIdentityHash::nameKey('TestPkg'),true);
$db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
8001,3,5,$written['codec'],$written['compressed_size'],$written['uncompressed_size'],$written['payload_sha256'],$written['block_count'],'classic-linkerload',$snapshot['source_policy'],1,$packageKey,'TestPkg',json_encode($written['manifest']['counts'])
]);
$registration=['file_id'=>8001,'game_id'=>3,'package_key_kind'=>1,'package_key'=>$packageKey];
$base=Uedb5SqlProjectionBuilder::build($snapshot,$registration);
$insertBase=static function(PDO $db,array $base):void{
$p=$db->prepare('INSERT INTO ue_uedb5_provider_keys VALUES(?,?,?,?,?,?)');foreach($base['provider_keys'] as $r)$p->execute([$r['source_kind'],$r['source_id'],$r['game_id'],$r['package_key_kind'],$r['package_key'],$r['file_id']]);
$s=$db->prepare('INSERT INTO ue_uedb5_search_keys VALUES(?,?,?,?)');foreach($base['search_keys'] as $r)$s->execute([$r['hash'],$r['length'],$r['fingerprint'],$r['normalized_text']]);
$n=$db->prepare('INSERT INTO ue_uedb5_name_candidates VALUES(?,?,?,?,?)');foreach($base['name_candidates'] as $r)$n->execute([$r['file_id'],$r['name_key_hash'],$r['name_key_length'],$r['name_key_fingerprint'],$r['first_name_index']]);
$o=$db->prepare('INSERT INTO ue_uedb5_object_candidates VALUES(?,?,?,?,?,?)');foreach($base['object_candidates'] as $r)$o->execute([$r['file_id'],$r['object_kind'],$r['object_index'],$r['object_name_hash'],$r['object_name_length'],$r['public_export_hash']]);
};
$insertBase($db,$base);
$validator=new Uedb5MigrationValidator($db,['storage_path'=>$temp]);
$stage=$validator->validate(8001);
$check('pass1_only_remains_staged',empty($stage['ready'])&&($stage['dependency']['reason']??'')==='dependency_results_not_built');
$check('source_counts_match',($stage['counts']??[])===['names'=>3,'imports'=>1,'exports'=>0]);
$check('base_projection_exact_match',($stage['base_projection']['name_candidates']??0)===3&&($stage['base_projection']['object_candidates']??-1)===0);
$snapshot['section_schemas']['dependency_results']='ue.test.dependency-result.v1';
$snapshot['sections']['dependency_results']=[[
'dependency_kind'=>'ClassicImport','source_section'=>'imports','source_index'=>0,
'required_package_identity'=>['kind'=>'package_name','value'=>'Core'],
'required_object_identity'=>['kind'=>'classic_import','object_name'=>'Object','class_package'=>'Core','class_name'=>'Object','outer_index'=>0],
'dependency_class'=>'hard','optional'=>false,'hard'=>true,'outcome'=>'missing','outcome_code'=>0,
'selected_provider_file_id'=>null,'selected_provider_package_identity'=>null,'selected_provider_object'=>null,
'resolver_policy'=>'test','source_policy'=>$snapshot['source_policy'],'reason_code'=>'test_missing','resolver_detail'=>[]
]];
$written2=$writer->write($snapshot,16);
$db->prepare('UPDATE ue_uedb5_files SET codec=?,compressed_size=?,uncompressed_size=?,payload_sha256=?,block_count=?,section_counts_json=? WHERE file_id=8001')->execute([$written2['codec'],$written2['compressed_size'],$written2['uncompressed_size'],$written2['payload_sha256'],$written2['block_count'],json_encode($written2['manifest']['counts'])]);
$dep=Uedb5DependencyProjectionBuilder::build($snapshot);
$e=$db->prepare('INSERT INTO ue_uedb5_dependency_edges VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
foreach($dep['dependency_edges'] as $r)$e->execute(array_values($r));
$pkg=$db->prepare('INSERT INTO ue_uedb5_dependency_packages VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach($dep['dependency_packages'] as $r)$pkg->execute(array_values($r));
$ready=$validator->validate(8001);
$check('dependency_complete_becomes_validation_ready',!empty($ready['ready'])&&($ready['dependency']['dependency_count']??0)===1);
$check('dependency_projection_exact_match',($ready['dependency']['dependency_edges']??0)===1&&($ready['dependency']['dependency_packages']??0)===1);
$db->exec('DELETE FROM ue_uedb5_name_candidates WHERE file_id=8001 AND first_name_index=2');
$caught=false;
try{$validator->validate(8001);}catch(Uedb5ValidationException $ex){$caught=$ex->reasonCode==='name_projection_mismatch';}
$check('projection_drift_fails_validation',$caught);
$scriptProjection=Uedb5DependencyProjectionBuilder::build([
'file'=>['id'=>9001,'game_id'=>8],
'sections'=>['dependency_results'=>[[
'dependency_kind'=>'ScriptImport','source_section'=>'imports','source_index'=>0,'dependency_class'=>'script',
'required_package_id'=>null,'required_object_identity'=>'0123456789ABCDEF','hard'=>false,'outcome'=>'unresolved',
'selected_provider_file_id'=>null,'selected_provider_object'=>null
]]]
]);
$scriptEdge=(array)($scriptProjection['dependency_edges'][0]??[]);
$check('script_import_hash_is_not_public_export_hash',($scriptEdge['required_object_key_kind']??null)===null&&($scriptEdge['required_object_key']??null)===null);
$validatorSource=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5MigrationValidator.php');
$check('validator_never_reads_uedb4',!str_contains($validatorSource,'.uedb4')&&!str_contains($validatorSource,'BlockedCompressedMetadataReader'));
$check('validator_never_reads_live_v4_registration',!str_contains($validatorSource,'ue_file_metadata'));
$check('ue4_unversioned_does_not_trust_legacy_catalogue_version',str_contains($validatorSource,'$ue4Unversioned')&&str_contains($validatorSource,'ue4_unversioned_effective_version'));
$check('durable_state_machine_has_four_states',Uedb5MigrationStatus::values()===['pending','staged','validated','failed']);
$migration=(string)file_get_contents($root.'/migrations/202609300002_uedb5_migration_status.php');
$check('status_schema_contains_four_durable_states',str_contains($migration,'pending')&&str_contains($migration,'staged')&&str_contains($migration,'validated')&&str_contains($migration,'failed'));

if(is_dir($temp)){
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($it as $item){$item->isDir()?@rmdir($item->getPathname()):@unlink($item->getPathname());}@rmdir($temp);
}
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
