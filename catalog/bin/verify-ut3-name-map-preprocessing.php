#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogLegacyNameMapPreprocessor;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogParsedPackageMetadataSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut3SnapshotBuilder;

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
$db->exec("INSERT INTO ue_game_profiles VALUES(1,'UE3',1)");
$db->exec('INSERT INTO ue_games VALUES(6,1)');
$db->exec('INSERT INTO ue_files VALUES(4001,6,512,0),(4002,6,513,0)');

$ctxHigh=0x00070000;
$long=str_repeat('Ut3LongName',15);
$long127=mb_substr($long,0,127,'UTF-8');
$fname=static fn(int$i,int$n,string$t):array=>['index'=>$i,'number'=>$n,'text'=>$t];
$names=[
 ['name'=>'Core','flags'=>0,'objectFlagsHigh'=>$ctxHigh],
 ['name'=>'Package','flags'=>0,'objectFlagsHigh'=>$ctxHigh],
 ['name'=>$long,'flags'=>0,'objectFlagsHigh'=>$ctxHigh],
 ['name'=>'SequenceObjects','flags'=>0,'objectFlagsHigh'=>0],
 ['name'=>'Engine','flags'=>0,'objectFlagsHigh'=>$ctxHigh],
];
$imports=[[
 'classPackage'=>0,'ClassPackage'=>$fname(0,0,'Core'),'classPackageText'=>'Core',
 'className'=>1,'ClassName'=>$fname(1,0,'Package'),'classNameText'=>'Package',
 'outerIndex'=>0,'OuterIndex'=>0,
 'objectName'=>2,'ObjectName'=>$fname(2,3,$long.'_2'),'objectNameText'=>$long.'_2',
],[
 'classPackage'=>0,'ClassPackage'=>$fname(0,0,'Core'),'classPackageText'=>'Core',
 'className'=>1,'ClassName'=>$fname(1,0,'Package'),'classNameText'=>'Package',
 'outerIndex'=>0,'OuterIndex'=>0,
 'objectName'=>3,'ObjectName'=>$fname(3,0,'SequenceObjects'),'objectNameText'=>'SequenceObjects',
]];
$exports=[[
 'classIndex'=>0,'superIndex'=>0,'outerIndex'=>0,'nameIndex'=>2,'nameNumber'=>2,
 'objectNameText'=>$long.'_1','objectFlagsLow'=>0,'objectFlagsHigh'=>0x00000004,
 'serialSize'=>0,'serialOffset'=>0,
]];
$b=new CatalogParsedPackageMetadataSnapshotBuilder($db,['common_packages'=>[]]);
$v512=$b->buildParsedSections(4001,6,'Consumer512','Consumer512.upk',$names,$imports,$exports);
$v513=$b->buildParsedSections(4002,6,'Consumer513','Consumer513.upk',$names,$imports,$exports);

$checks=[];$fail=[];$check=static function(string$n,bool$ok)use(&$checks,&$fail):void{$checks[$n]=$ok;if(!$ok)$fail[]=$n;};
$check('v512_raw_long_name_preserved',($v512['names'][2]['name_text']??null)===$long);
$check('v512_qword_name_flags_preserved_as_integer',($v512['names'][0]['flags']??null)===CatalogLegacyNameMapPreprocessor::UE3_ALL_LOAD_CONTEXTS);
$check('v512_numbered_import_uses_truncated_effective_base',($v512['imports'][0]['object_name']??null)===$long127.'_2');
$check('v512_export_number_uses_truncated_effective_base',($v512['exports'][0]['object_name']??null)===$long127.'_1');
$check('v512_zero_context_sequenceobjects_becomes_none',($v512['imports'][1]['object_name']??null)==='None');
$fixed=CatalogCompactIdentityEnricher::ue3FixupImportMap(array_column($v512['imports'],null,'import_index'));
$check('name_none_is_not_sequenceobjects_remapped',($fixed[1]['object_name']??null)==='None');
$check('v513_does_not_inherit_v512_name_map',($v513['imports'][1]['object_name']??null)==='SequenceObjects');
$check('v513_does_not_inherit_v512_truncation',($v513['imports'][0]['object_name']??null)===$long.'_2');

$uf=static fn(int$i,int$n,string$t):array=>['name_index'=>$i,'number'=>$n,'text'=>$t];
$snapshot=static function(int$v,string$objFlags,string$objText,int$objNumber)use($uf,$long):array{return[
 'file'=>['id'=>8000+$v,'game_id'=>6,'package_name'=>'Consumer'.$v,'original_name'=>'Consumer'.$v.'.upk'],
 'package_family'=>'classic-linkerload','source_policy'=>Uedb5Ut3SnapshotBuilder::SOURCE_POLICY,
 'section_schemas'=>['summary'=>'ue3.ut3.package-summary.v1','names'=>'ue3.ut3.name-entry.v1','imports'=>'ue3.ut3.object-import.v1','exports'=>'ue3.ut3.object-export.v1'],
 'sections'=>[
  'summary'=>[['package_version'=>$v,'licensee_version'=>0]],
  'names'=>[
   ['index'=>0,'text'=>'Core','flags'=>'0007000000000000'],
   ['index'=>1,'text'=>'Package','flags'=>'0007000000000000'],
   ['index'=>2,'text'=>$long,'flags'=>'0007000000000000'],
   ['index'=>3,'text'=>'SequenceObjects','flags'=>$objFlags],
  ],
  'imports'=>[
   ['index'=>0,'class_package'=>$uf(0,0,'Core'),'class_name'=>$uf(1,0,'Package'),'outer_index'=>0,'object_name'=>$uf(2,3,$long.'_2')],
   ['index'=>1,'class_package'=>$uf(0,0,'Core'),'class_name'=>$uf(1,0,'Package'),'outer_index'=>0,'object_name'=>$uf(3,$objNumber,$objText)],
  ],
  'exports'=>[],
 ],
];};
$r512=Uedb5ClassicDependencyResolver::resolve($snapshot(512,'0000000000000000','SequenceObjects',0),[]);
$check('v5_v512_numbered_root_uses_truncated_base',($r512[0]['provider_package']??null)===$long127.'_2');
$check('v5_v512_filtered_sequenceobjects_is_source_irrelevant',($r512[1]['reason']??null)==='source_irrelevant_name_none');
$v513Snapshot=$snapshot(513,'0000000000000000','FilteredRoot',0);
$v513Snapshot['sections']['names'][3]['text']='FilteredRoot';
$r513=Uedb5ClassicDependencyResolver::resolve($v513Snapshot,[]);
$check('v5_v513_does_not_inherit_v512_filter',($r513[1]['provider_package']??null)==='FilteredRoot');

echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
