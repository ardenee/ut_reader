#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogLegacyNameMapPreprocessor;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogParsedPackageMetadataSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut2004SnapshotBuilder;

$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/lib/CatalogSupportCore.php';
require_once $root.'/bootstrap/autoload.php';
if(!extension_loaded('pdo_sqlite')){fwrite(STDERR,"pdo_sqlite is required.\n");exit(2);}

$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,engine_key TEXT,is_active INTEGER)');
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,profile_id INTEGER)');
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,package_version INTEGER,licensee_version INTEGER)');
$db->exec("INSERT INTO ue_game_profiles VALUES(1,'UE2',1)");$db->exec('INSERT INTO ue_games VALUES(5,1)');
$db->exec('INSERT INTO ue_files VALUES(3001,5,63,29),(3002,5,64,29),(3003,5,129,29),(3004,5,130,29)');

$long=str_repeat('Ut2004LongName',6);$long63=mb_substr($long,0,63,'UTF-8');
$fname=static fn(int$i,string$t):array=>['index'=>$i,'number'=>0,'text'=>$t];
$names=[
 ['name'=>'Core','flags'=>CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS],
 ['name'=>'Package','flags'=>CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS],
 ['name'=>$long,'flags'=>CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS],
 ['name'=>'FilteredRoot','flags'=>0],
];
$imports=[[
 'classPackage'=>$fname(0,'Core'),'ClassPackage'=>$fname(0,'Core'),'classPackageText'=>'Core',
 'className'=>$fname(1,'Package'),'ClassName'=>$fname(1,'Package'),'classNameText'=>'Package','outerIndex'=>0,'OuterIndex'=>0,
 'objectName'=>$fname(2,$long),'ObjectName'=>$fname(2,$long),'objectNameText'=>$long,
],[
 'classPackage'=>$fname(0,'Core'),'ClassPackage'=>$fname(0,'Core'),'classPackageText'=>'Core',
 'className'=>$fname(1,'Package'),'ClassName'=>$fname(1,'Package'),'classNameText'=>'Package','outerIndex'=>0,'OuterIndex'=>0,
 'objectName'=>$fname(3,'FilteredRoot'),'ObjectName'=>$fname(3,'FilteredRoot'),'objectNameText'=>'FilteredRoot',
]];
$b=new CatalogParsedPackageMetadataSnapshotBuilder($db,['common_packages'=>[]]);
$v63=$b->buildParsedSections(3001,5,'C63','C63.ut2',$names,$imports,[]);
$v64=$b->buildParsedSections(3002,5,'C64','C64.ut2',$names,$imports,[]);
$v129=$b->buildParsedSections(3003,5,'C129','C129.ut2',$names,$imports,[]);
$v130=$b->buildParsedSections(3004,5,'C130','C130.ut2',$names,$imports,[]);

$checks=[];$fail=[];$check=static function(string$n,bool$ok)use(&$checks,&$fail){$checks[$n]=$ok;if(!$ok)$fail[]=$n;};
$check('v63_effective_long_name_not_truncated',($v63['imports'][0]['object_name']??null)===$long);
$check('v63_zero_context_name_maps_to_none',($v63['imports'][1]['object_name']??null)==='None');
$check('v64_effective_long_name_truncated',($v64['imports'][0]['object_name']??null)===$long63);
$check('v129_effective_long_name_truncated',($v129['imports'][0]['object_name']??null)===$long63);
$check('v129_serialized_long_name_preserved',($v129['names'][2]['name_text']??null)===$long);
$check('v130_does_not_inherit_context_filter',($v130['imports'][1]['object_name']??null)==='FilteredRoot');
$check('v130_does_not_inherit_v129_truncation',($v130['imports'][0]['object_name']??null)===$long);

$uf=static fn(int$i,string$t):array=>['name_index'=>$i,'number'=>0,'text'=>$t];
$snap=static function(int$v,string$p,string$text,string$flags)use($uf):array{return[
 'file'=>['id'=>7000+$v,'game_id'=>5,'package_name'=>'C'.$v,'original_name'=>'C'.$v.'.ut2'],'package_family'=>'classic-linkerload','source_policy'=>$p,
 'section_schemas'=>['summary'=>'ue2.ut2004.package-summary.v1','names'=>'ue2.ut2004.name-entry.v1','imports'=>'ue2.ut2004.object-import.v1','exports'=>'ue2.ut2004.object-export.v1'],
 'sections'=>['summary'=>[['package_version'=>$v,'licensee_version'=>29]],'names'=>[['index'=>0,'text'=>'Core','flags'=>'00070000'],['index'=>1,'text'=>'Package','flags'=>'00070000'],['index'=>2,'text'=>$text,'flags'=>$flags]],'imports'=>[['index'=>0,'class_package'=>$uf(0,'Core'),'class_name'=>$uf(1,'Package'),'outer_index'=>0,'object_name'=>$uf(2,$text)]],'exports'=>[]],
];};
$r129=Uedb5ClassicDependencyResolver::resolve($snap(129,Uedb5Ut2004SnapshotBuilder::POLICY_V129,$long,'00070000'),[]);
$check('v5_v129_uses_63_character_effective_name',($r129[0]['provider_package']??null)===$long63);
$r130=Uedb5ClassicDependencyResolver::resolve($snap(130,Uedb5Ut2004SnapshotBuilder::POLICY_POST_V129_UNRESOLVED,$long,'00000000'),[]);
$check('v5_v130_keeps_raw_post_source_name',($r130[0]['provider_package']??null)===$long);

echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
