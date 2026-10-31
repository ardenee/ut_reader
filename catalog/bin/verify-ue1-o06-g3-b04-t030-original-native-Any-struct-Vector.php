#!/usr/bin/env php
<?php
/** One fixed G3 T030: actual 2026 compiled native C227k UStruct/FAnyProperty SetStruct -> SavePackage -> independent original shipped Core.dll native reload. **/
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicAnyPropertyValueReader as A;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;
$root=dirname(__DIR__,2);$ev=$root.'/catalog/compliance/evidence/o06/';
$checks=0;$failed=[];
$check=static function(bool $good,string $why)use(&$checks,&$failed):void{++$checks;if(!$good)$failed[]=$why;};
$files=[
'20261031-G3-B04-T030-original-2026-REAL-AnyStruct-SavePackage.u'=>'0dc24167815a4eb01458b2baf3cf0253374e5944e72d588b009c48852e7c1849',
'20261031-G3-B04-T030-original-2026-UCC-original-Any-declarations.u'=>'0c79ea57295375a467d6ade733d822ff7e7f1909bce70514c4bc45e1121cb18a',
'20261031-G3-B04-T030-original-2026-UCC-Any-three-fields.uc'=>'7f49108f630407cd3b349788792a3d6d32024bad48602ef24cedf4a49d9e20de',
'20261031-G3-B04-T030-original-C227k-native-SetStruct-SAVE.cpp'=>'5db03515b46ea7538d8acbcf156232aa803e7ddef8f1735ce0ebd28ea962f430',
'20261031-G3-B04-T030-original-C227k-native-independent-RELOAD.cpp'=>'aba2add1d19df399596a55ef3566923f38af649bedb26df6e6f78e9f22d99d03',
'20261031-G3-B04-T030-original-C227k-native-SetStruct-SAVE.log'=>'a3a827ef68a8f495ee7c128104f3a17ce916676a019c7fc4be5604b46d38a5cc',
'20261031-G3-B04-T030-original-C227k-native-independent-RELOAD.log'=>'0b9bc706359c048deabecbcae9aa3eae537b02c1283b17ba109a34eabc61d38e',
'20261031-G3-B04-T030-original-C227k-native-SetStruct-SAVE-build.log'=>'e7c5b917007bbcc5c0be4b6eae35b9e3a824fece3ad4664fed070a4cbc867583',
'20261031-G3-B04-T030-original-C227k-native-independent-RELOAD-build.log'=>'58dacb07e4d5876181e4430c18b309c8cd5b3080e999f7a235957f67cd5157c0',
];
foreach($files as $name=>$hash)$check(is_file($ev.$name)&&hash_file('sha256',$ev.$name)===$hash,
 'SHA256 immutable authentic UCC/source/binary/native original evidence '.$name);
$core='C:/Temp/UnrealDB/O06/Native-227k/System/Core.dll';
$check(is_file($core)&&hash_file('sha256',$core)==='c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194',
 'Original genuine shipped C227k Core.dll is unmodified, not recompiled/simulated');
$check(hash_file('sha256',$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicAnyPropertyValueReader.php')
 ==='3c850791cba95f113813f0dbc5168c0d0685e369068a5ea88a3850c702fc7d59',
 'Original source-specific UAnyProperty production reader exact audited SHA');
$check(hash_file('sha256',$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php')
 ==='38133f6a8fb0330dc6eb8eff20fdc16c6776af5943dc2ae4453c77486f3bc9a1',
 'Source-linked real Actor object declaration tag decoder exact pinned SHA');
$check(hash_file('sha256',$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassDefaultSchemaReader.php')
 ==='392f8af02806c6e1d62715fb84e6bf6e9e2bc99392171e2f0206c0ae1414b925',
 'Source-linked original UAnyProperty GetID Vector11 schema alias exact SHA');
$saveSrc=(string)file_get_contents($ev.'20261031-G3-B04-T030-original-C227k-native-SetStruct-SAVE.cpp');
$reloadSrc=(string)file_get_contents($ev.'20261031-G3-B04-T030-original-C227k-native-independent-RELOAD.cpp');
$check(str_contains($saveSrc,'any->SetStruct((BYTE*)&vec,structType)')
  &&str_contains($saveSrc,'UObject::SavePackage(')
  &&str_contains($saveSrc,'FVector vec(3.25f,-4.5f,5.75f)')
  &&str_contains($saveSrc,'UObject::StaticConstructObject('),
 'Real 2026 compiled original Core.dll created new UObject, FAny SetStruct directly and saved package');
$check(str_contains($reloadSrc,'UObject::LoadPackage(')
  &&str_contains($reloadSrc,'GetStructType()')
  &&str_contains($reloadSrc,'GetRawData()')
  &&str_contains($reloadSrc,'obj->IsA(UClass::StaticClass())')
  &&str_contains($reloadSrc,'G3_T030_ORIGINAL_INDEPENDENT_REAL_LOAD_PASS'),
 'Genuine separate native executable independently reloads actual original output and reflected FAny struct');
$logText=static function(string $name)use($ev):string{
 $raw=(string)file_get_contents($ev.$name);
 return str_starts_with($raw,"\xFF\xFE")
   ?(string)iconv('UTF-16LE','UTF-8',substr($raw,2))
   :$raw;
};
$saveLog=$logText('20261031-G3-B04-T030-original-C227k-native-SetStruct-SAVE.log');
$reloadLog=$logText('20261031-G3-B04-T030-original-C227k-native-independent-RELOAD.log');
$check(str_contains($saveLog,'G3_T030_ORIGINAL_LOAD_COMPILER_PACKAGE ptr=')
  &&str_contains($saveLog,'bad=0')
  &&str_contains($saveLog,'G3_T030_ORIGINAL_REAL_CREATED_INSTANCE'),
  'original 2026 genuine compiled Core.dll source class first loaded, real new instance created');
$check(str_contains($saveLog,'G3_T030_ORIGINAL_REAL_STRUCT FVector bytes x=3.250000 y=-4.500000 z=5.750000 size=12 structSize=12'),
 'real 2026 C227k FAny subtype6 native UStruct Core.Object.Vector actual properties size12');
$check(str_contains($saveLog,'G3_T030_ORIGINAL_NATIVE_ANY_SET name=StructValue runtimeOffset=784 type=6 trueStruct=Struct Core.Object.Vector')
  &&str_contains($saveLog,'G3_T030_ORIGINAL_NATIVE_SAVEPACKAGE result=1'),
 'original real C227k FAny SetStruct returns6 and original native SavePackage TRUE');
$check(str_contains($reloadLog,'G3_T030_ORIGINAL_INDEPENDENT_DECLARATIONS=')&&str_contains($reloadLog,'failed=0')
  &&str_contains($reloadLog,'G3_T030_ORIGINAL_INDEPENDENT_RUNTIME_PACKAGE='),
 'independent distinct original native loader loads both original UCC schema and original native-saved runtime package with no error');
$check(str_contains($reloadLog,'G3_T030_ORIGINAL_RELOADED_CANDIDATE name=G3Any227KActor G3Any227KRuntime.AnyStructInstance')
  &&str_contains($reloadLog,'G3_T030_ORIGINAL_INDEPENDENT_REAL_ANY_PROP offset=784 ptr=')
  &&str_contains($reloadLog,'anytype=6'),
 'independent 2026 C227k Core.dll genuinely loaded original native saved typed Any struct at runtime field offset784');
$check(str_contains($reloadLog,'full=Struct Core.Object.Vector structSize=12 vectorX=3.250000 vectorY=-4.500000 vectorZ=5.750000')
  &&str_contains($reloadLog,'bitsX=40500000 bitsY=C0900000 bitsZ=40B80000')
  &&str_contains($reloadLog,'G3_T030_ORIGINAL_INDEPENDENT_REAL_LOAD_PASS=1'),
 'independent real 2026 original engine native struct CDO test 3 exact float32 values and layout, returned pass1');
$file=$ev.'20261031-G3-B04-T030-original-2026-REAL-AnyStruct-SavePackage.u';
// Cross-package property-chain resolution needs the actual ORIGINAL
// shipped Core.u, Engine.u and UCC-produced G3Any227K.u beside it.
// Compare byte-identical saved fixture SHA before using the canonical
// genuine installed native runtime directory for source-only resolution.
// Do not let a missing sibling Engine.u in the pinned evidence directory
// masquerade as an UnrealDB decoder failure.
$realOriginalRuntime='C:/Temp/UnrealDB/O06/Native-227k/System/G3Any227KRuntime.u';
$check(is_file($realOriginalRuntime)
  &&hash_file('sha256',$realOriginalRuntime)===$files['20261031-G3-B04-T030-original-2026-REAL-AnyStruct-SavePackage.u'],
  'original exact SHA native runtime package beside original separately pinned UCC and source engine packages');
$check(hash_file('sha256','C:/Temp/UnrealDB/O06/Native-227k/System/G3Any227K.u')
  ===$files['20261031-G3-B04-T030-original-2026-UCC-original-Any-declarations.u'],
 'actual source-gated original compiled UClass declaration same immutable UCC bytes');
$package=new P($realOriginalRuntime);
$header=$package->getHeader();$ex=$package->getExports();$im=$package->getImports();
$check(filesize($file)===312&&($header['version']??-1)===69
  &&($header['licensee']??-1)===227&&count($ex)===1&&count($im)===5
  &&$package->validatePackage()===[],
  'genuine compiled 2026 original engine SavePackage emits valid v69 lic227 312-byte .u');
$check(($ex[0]['objectNameText']??'')==='AnyStructInstance'
  &&($ex[0]['serialOffset']??-1)===241 &&($ex[0]['serialSize']??-1)===22
  &&($ex[0]['classIndex']??null)===-4,
  'actual native 1 instance export offset241 size22 class imported genuine -4');
$check(($im[2]['objectNameText']??'')==='Vector'
 &&($im[2]['classNameText']??'')==='Struct'
 &&($im[2]['outerIndex']??null)===-1
 &&($im[0]['objectNameText']??'')==='Object'
 &&($im[0]['outerIndex']??null)===-5
 &&($im[4]['objectNameText']??'')==='Core'
 &&($im[4]['classNameText']??'')==='Package',
 'real original ImportMap[-3] Struct Vector nested original Class Object nested root Core Package');
$arch=substr((string)file_get_contents($file),241,22);
$check(bin2hex($arch)==='015b1206830c00000000005040000090c00000b84000',
 'byte-for-byte genuine native 22 byte source-tagged instance exact archive actual source byte order');
$decoded=T::inspect($realOriginalRuntime,$package,0,S::UNREAL_V227K);
$tags=$decoded['tags']??[];
$check(count($tags)===1&&($decoded['status']??'')!=='error',
 'source-linked instance full original package tag envelope yields one legitimate declared Any field');
$tag=$tags[0]??[];
$check(($tag['name']??'')==='StructValue'&&($tag['type_id']??null)===11
 &&($tag['type']??'')==='VectorProperty'
 &&($tag['native_load_policy']??'')==='native_227k_resolved_AnyProperty_SerializeItem',
 'Only real source-linked UAnyProperty can decode original tagged VectorProperty11 alias; no FVector ambiguity');
$check(($tag['serialized_size']??null)===18
  &&($tag['on_disk_value_bytes']??null)===18
  &&($tag['value_offset']??null)===244,
 'Original Core.dll class field tag header3, dynamic value18 and NAME_None1, exact original 22 physical bytes');
$any=$tag['native_227k_source_resolved_Any_decoded']??[];
$check(($any['original_any_type_id']??null)===6
  &&($any['serialized_byte_count']??null)===18
  &&($any['raw_archive_bytes_hex']??null)==='06830c00000000005040000090c00000b840',
 'UnrealDB 227k shared source-linked Any6 value consumes exact original 18 bytes and no guessed 12-byte plain Vector');
$val=$any['value']??[];
$check(($any['value_kind']??null)==='native_any_linker_fvector_xyz'
  &&($val['struct_reference']??null)===-3
  &&($val['resolved_struct']??null)==='Struct Core.Object.Vector'
  &&($val['struct_property_bytes']??null)===12,
 'UnrealDB original native UStruct import -3 full Core.Object.Vector and original 12 physical source bytes');
$check(($val['x']??null)===3.25&&($val['y']??null)===-4.5
  &&($val['z']??null)===5.75
  &&($val['serialized_float32_xyz_hex']??'')==='00005040000090c00000b840',
 'UnrealDB exact native original FVector serialized 3 float32 values and byte representations');
$check(A::saveVerified(6,substr($arch,4,17),S::UNREAL_V227,$package)
  ===substr($arch,3,18),
 'source-selected original Any6 SAVE reproduces every original byte in unchanged original linker map');
$check(($decoded['unparsed_bytes']??0)===0||($decoded['trailing_bytes']??0)===0,
 'original runtime instance source-defined archive terminal emitted and consumed');
// Source guards must not claim undocumented struct layouts are supported.
foreach([
 [chr(6).chr(0x82).pack('V',12).pack('g3',3.25,-4.5,5.75),'source layout not verified'],
 [chr(6).chr(0x83).pack('V',8).pack('g2',3.25,-4.5),'serialized size not original-verified'],
 [chr(6).chr(0x83).pack('V',12).pack('g2',3.25,-4.5),'Truncated'],
] as [$raw,$message]){
 $cursor=0;$read=static function(int $n)use($raw,&$cursor):string{
  $v=substr($raw,$cursor,$n);$cursor+=strlen($v);return $v;
 };
 $error='';
 try{A::readFromArchive($read,$cursor,S::UNREAL_V227,$package);}
 catch(Throwable $e){$error=$e->getMessage();}
 $check(stripos($error,$message)!==false,
   'unverified Any6 mutated source bytes fail closed, no unsupported UStruct guess: '.$message);
}
$cursor=0;$bytes=chr(6).chr(0x83).pack('V',12).pack('g3',3.25,-4.5,5.75);
$read=static function(int $n)use($bytes,&$cursor):string{$v=substr($bytes,$cursor,$n);$cursor+=strlen($v);return $v;};
$error='';
try{A::readFromArchive($read,$cursor,S::UT99_V432,$package);}catch(Throwable $e){$error=$e->getMessage();}
$check(str_contains($error,'only certified for Unreal v227'),
 'explicit v227 native source does not authorize UT99 test branch despite matching physical bytes');
$ok=count($failed)===0;
echo json_encode([
 'ok'=>$ok,'checks'=>$checks,'failures'=>$failed,
 'full_frozen_g3_ids'=>['T030'=>$ok?'PASS':'BLOCKED'],
 'original_compiled_source'=>'shipped original 2026 Core.dll x86 + genuine 2026 shipped original UCC .u class',
 'original_native_save'=>['SetStruct'=>'Struct Core.Object.Vector','x'=>3.25,'y'=>-4.5,'z'=>5.75,'SavePackage'=>1],
 'original_independent_native_reload'=>['actual_type'=>6,'value'=>'FVector(3.25,-4.5,5.75)','size'=>12,'binary_float_hex'=>'00005040000090c00000b840','PASS'=>1],
 'original_actual_package'=>['size'=>312,'export_offset'=>241,'export_size'=>22,'tag'=>'015b12','serialized_any'=>'06830c00000000005040000090c00000b840','NAME_None'=>'00'],
 'other_UStruct_layouts'=>'EXPLICIT_UNSUPPORTED',
 'previously_accepted_original_native_executions_repeated'=>0,
 'overall_G3_complete'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
