#!/usr/bin/env php
<?php
/** T035: UT99 v432 + 469f original compiled UClass HUD WhiteColor import Core.Struct Color.
 * Genuine native 469f CDO reused; 607/608 old v432 complete native defaults reused.
 * No original compiled engine rerun.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$root=dirname(__DIR__,2);
$ev=$root.'/catalog/compliance/evidence/o06/';
$checks=0;$fail=[];
$ck=static function(bool $ok,string $name)use(&$checks,&$fail):void{
 $checks++;if(!$ok)$fail[]=$name;
};
$files=[
 ['C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.u','d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695'],
 ['C:/Temp/UnrealDB/O06/Native-UCC/System/Core.dll','237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8'],
 ['C:/Temp/UnrealDB/O06/Native-469f/System/Engine.u','6466cc189183b4eaf36d80339ced9dac8b0711d6487930d6b213feccfb26620b'],
 ['C:/Temp/UnrealDB/O06/Native-469f/System/Core.dll','80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2'],
 [$ev.'20261029-G3-T017-T059-original-469f-three-actor-native-defaults.log','c3c7fa3246e9ad41d6a03556852d0a0f726e94e1ea31c5aa1d442af1802acb99'],
 [$ev.'20261029-G3-T017-T059-original-469f-v432-two-closures-902pass.json','184d662a2ebdff10898993a4b9364e951a3a4d5fc7f674c0d34e28a5d2a8d3a2'],
 [$root.'/catalog/compliance/UE1-O06-G5-UCLASS-DEFAULTS-NATIVE-608.json','ae3e23e5e3b5ef7e7fab363fbd1ae7a75f80591212fac6bd2af7575638fa6e3b'],
 [$root.'/catalog/bin/verify-ue1-o06-g5-uclass-defaults-native-608.php','62d7f69c747a5f4c1b0fe430d76caab882cdb87e70839fb025cf27c69e8f1738'],
 [$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php','842cfbbd1a9df0ac5b97ab82a5ec3df0dd5e145e80c5ddcd21df4f384b4c5468']
];
foreach($files as [$path,$hash])$ck(is_file($path)&&hash_file('sha256',$path)===$hash,'original source/compiled session SHA256 '.$path);
$previous=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-UCLASS-DEFAULTS-NATIVE-608.json'),true,512,JSON_THROW_ON_ERROR);
$ck($previous['native_loaded_classes']===88&&$previous['original_class_defaults_tag_count']===608,
    'original v432 FULL compiled native 88 UClass, 608 tagged default loaded baseline');
$ck($previous['expected_type_counts']['StructProperty']===16
    &&$previous['expected_exact_serialized_to_native']===607
    &&$previous['expected_config_override']===1
    &&$previous['config_overridden_original']['type']==='StrProperty',
    'All original v432 16 struct bytes compared in compiled native Defaults, only known difference is config StrProperty');
$previousG3=json_decode((string)file_get_contents($ev.'20261029-G3-T017-T059-original-469f-v432-two-closures-902pass.json'),true,512,JSON_THROW_ON_ERROR);
$ck(($previousG3['ok']??false)===true&&$previousG3['checks']===902
    &&$previousG3['T017']==='PASS'&&$previousG3['T059']==='PASS',
    'Existing sealed original compiled 469f CDO acceptance native genuine Engine.u proof');
$log=str_replace("\r","",(string)file_get_contents($ev.'20261029-G3-T017-T059-original-469f-three-actor-native-defaults.log'));
$native=preg_match('~G3_ORIGINAL_469F_ACTOR_DEFAULT_BEGIN Class Engine\.HUD\|defaultPtr=[0-9A-Fa-f]+\n(.*?)G3_ORIGINAL_469F_ACTOR_DEFAULT_END Class Engine\.HUD~s',$log,$match);
$ck($native===1,'original 469f HUD real compiled loaded CDO delimited correctly');
$ck($native===1 && preg_match('/^G5_NATIVE_TAG_VALUE\s+WhiteColor=\(R=0,G=128,B=255,A=0\)$/m',$match[1])===1,
    'original compiled 469f real imported FColor member R=0 G=128 B=255 A=0');
$colors=[];$paths=[
 'v432'=>['C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.u','ut99-v432'],
 'v469f'=>['C:/Temp/UnrealDB/O06/Native-469f/System/Engine.u','ut99-v469f']
];
foreach($paths as $profile=>[$path,$family]){
 $pkg=new P($path);
 $ck((int)$pkg->getHeader()['version']===69,
   'genuine original '.$profile.' Engine package 69');
 $imported=array_values(array_filter($pkg->getImports(),static fn(array $i):bool=>
    ($i['objectNameText']??null)==='Color'
    &&($i['classPackageText']??null)==='Core'
    &&($i['classNameText']??null)==='Struct'
    &&(int)($i['outerIndex']??0)<0));
 $ck(count($imported)===1,'genuine original '.$profile.' UClass/UStruct Core.Struct Color import resolved by full identity');
 $classes=array_filter($pkg->getExports(),static fn(array $e):bool=>
     (int)$e['classIndex']===0 && $e['objectNameText']==='HUD');
 $ck(count($classes)===1,'genuine '.$profile.' original HUD serialized UClass export');
 foreach($classes as $id=>$class){
  $res=C::inspect($path,$pkg,$id,false,$family);
  $ck($res['source_file_archive_complete']===true,
     'genuine original '.$profile.' HUD UClass fully consumed original FArchive');
  $tag=array_values(array_filter($res['default_property_tags'],
       static fn(array $t):bool=>$t['name']==='WhiteColor'));
  $ck(count($tag)===1,'genuine '.$profile.' HUD saved WhiteColor FPropertyTag once');
  if(count($tag)!==1)continue;
  $t=$tag[0];
  $ck($t['type']==='StructProperty'&&($t['struct_name']['text']??'')==='Color',
      'original '.$profile.' tagged original Core.Struct Color resolved by FName');
  $ck((int)$t['serialized_size']===4 && (int)$t['on_disk_value_bytes']===4,
      'original '.$profile.' Core.Struct Color native source four bytes');
  $bytes=(string)file_get_contents($path,false,null,(int)$t['value_offset'],4);
  $ck(bin2hex($bytes)==='0080ff00'&&($t['value']['serialized_channel_bytes']??null)===[0,128,255,0],
      'original '.$profile.' tagged physical FColor R0 G128 B255 A0 read accurate');
  $ck(hash('sha256',$bytes)===$t['value_sha256'],
     'genuine original '.$profile.' original FColor physical four raw bytes hash matched');
  $colors[$profile]=['source_tag_offset'=>$t['tag_offset'],'physical_value_offset'=>$t['value_offset'],
    'raw_hex'=>bin2hex($bytes),'genuine_Color_import_table_index'=>$imported[0]['index'],
    'original_loaded'=>[0,128,255,0]];
 }
}
$ck(isset($colors['v432'],$colors['v469f'])
  &&$colors['v432']['raw_hex']===$colors['v469f']['raw_hex'],
  'Original v432/469f source-selected genuine imported Color exact bytes identical; no invented overlay delta');
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failures'=>$fail,'T035'=>$ok?'PASS':'BLOCKED',
 'source_profiles'=>array_keys($colors),'genuine_Engine_HUD_WhiteColor_import_Core_Struct_Color'=>$colors,
 'original_compiled_469f_loaded_FColor'=>'R=0,G=128,B=255,A=0',
 'original_v432_preaccepted_all_16_StructProperty_native_bytes'=>true,
 'original_engine_native_sessions_rerun_by_verifier'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
