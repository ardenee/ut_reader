#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogLegacyNameMapPreprocessor;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogParsedPackageMetadataSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut2003SnapshotBuilder;

$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/lib/CatalogSupportCore.php';
require_once $root.'/bootstrap/autoload.php';

if(!extension_loaded('pdo_sqlite')){fwrite(STDERR,"pdo_sqlite is required.\n");exit(2);}
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,engine_key TEXT,is_active INTEGER)');
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,profile_id INTEGER)');
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,package_version INTEGER,licensee_version INTEGER)');
$db->exec("INSERT INTO ue_game_profiles VALUES(1,'UE2',1)");
$db->exec('INSERT INTO ue_games VALUES(4,1)');
$db->exec('INSERT INTO ue_files VALUES(2001,4,120,28),(2002,4,121,29)');

$long=str_repeat('Ut2003LongName',6);
$fname=static fn(int $i,string $t):array=>['index'=>$i,'number'=>0,'text'=>$t];
$names=[
 ['name'=>'Core','flags'=>CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS],
 ['name'=>'Package','flags'=>CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS],
 ['name'=>$long,'flags'=>CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS],
 ['name'=>'FilteredRoot','flags'=>0],
];
$imports=[[
 'classPackage'=>$fname(0,'Core'),'ClassPackage'=>$fname(0,'Core'),'classPackageText'=>'Core',
 'className'=>$fname(1,'Package'),'ClassName'=>$fname(1,'Package'),'classNameText'=>'Package',
 'outerIndex'=>0,'OuterIndex'=>0,
 'objectName'=>$fname(2,$long),'ObjectName'=>$fname(2,$long),'objectNameText'=>$long,
],[
 'classPackage'=>$fname(0,'Core'),'ClassPackage'=>$fname(0,'Core'),'classPackageText'=>'Core',
 'className'=>$fname(1,'Package'),'ClassName'=>$fname(1,'Package'),'classNameText'=>'Package',
 'outerIndex'=>0,'OuterIndex'=>0,
 'objectName'=>$fname(3,'FilteredRoot'),'ObjectName'=>$fname(3,'FilteredRoot'),'objectNameText'=>'FilteredRoot',
]];
$builder=new CatalogParsedPackageMetadataSnapshotBuilder($db,['common_packages'=>[]]);
$v120=$builder->buildParsedSections(2001,4,'Consumer120','Consumer120.ut2',$names,$imports,[]);
$v121=$builder->buildParsedSections(2002,4,'Consumer121','Consumer121.ut2',$names,$imports,[]);

$checks=[];$fail=[];
$check=static function(string $n,bool $ok)use(&$checks,&$fail):void{$checks[$n]=$ok;if(!$ok)$fail[]=$n;};
$check('v120_serialized_long_name_preserved',($v120['names'][2]['name_text']??null)===$long);
$check('v120_effective_long_name_not_truncated',($v120['imports'][0]['object_name']??null)===$long);
$check('v120_zero_context_name_maps_to_none',($v120['imports'][1]['object_name']??null)==='None');
$check('v121_does_not_inherit_context_filter',($v121['imports'][1]['object_name']??null)==='FilteredRoot');

$uf=static fn(int $i,string $t):array=>['name_index'=>$i,'number'=>0,'text'=>$t];
$snapshot=static function(int $version,string $policy,string $text,string $flags)use($uf):array{return[
 'file'=>['id'=>6000+$version,'game_id'=>4,'package_name'=>'Consumer'.$version,'original_name'=>'Consumer'.$version.'.ut2'],
 'package_family'=>'classic-linkerload','source_policy'=>$policy,
 'section_schemas'=>['summary'=>'ue2.ut2003.package-summary.v1','names'=>'ue2.ut2003.name-entry.v1','imports'=>'ue2.ut2003.object-import.v1','exports'=>'ue2.ut2003.object-export.v1'],
 'sections'=>[
  'summary'=>[['package_version'=>$version,'licensee_version'=>28]],
  'names'=>[['index'=>0,'text'=>'Core','flags'=>'00070000'],['index'=>1,'text'=>'Package','flags'=>'00070000'],['index'=>2,'text'=>$text,'flags'=>$flags]],
  'imports'=>[['index'=>0,'class_package'=>$uf(0,'Core'),'class_name'=>$uf(1,'Package'),'outer_index'=>0,'object_name'=>$uf(2,$text)]],
  'exports'=>[],
 ],
];};
$v120None=$snapshot(120,Uedb5Ut2003SnapshotBuilder::SOURCE_POLICY,'FilteredRoot','00000000');
$r120=Uedb5ClassicDependencyResolver::resolve($v120None,[]);
$check('v5_v120_zero_context_name_is_source_irrelevant',($r120[0]['reason']??null)==='source_irrelevant_name_none');
$v121Raw=$snapshot(121,Uedb5Ut2003SnapshotBuilder::POLICY_POST_V120_UNRESOLVED,'FilteredRoot','00000000');
$r121=Uedb5ClassicDependencyResolver::resolve($v121Raw,[]);
$check('v5_v121_does_not_inherit_v120_name_filter',($r121[0]['provider_package']??null)==='FilteredRoot');

echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
