#!/usr/bin/env php
<?php
/** One original C227i UCC 0-warning class + original C227k compiled true UFixedArrayProperty direct SAVE/LOAD: two frozen full IDs. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicStructArrayValueReader as S;
$root=dirname(__DIR__,2);
$ev=$root.'/catalog/compliance/evidence/o06/';
$n=0;$fails=[];
$ck=static function(bool $ok,string $label)use(&$n,&$fails):void{++$n;if(!$ok)$fails[]=$label;};
$hashes=[
 '20261030-G3-B06-FIXED-C227i-original-source.uc'=>'caad681105c071ee7a991caa57998fb2c6a7a5cb1081fcfd5edbcbb8d5b56ead',
 '20261030-G3-B06-FIXED-C227i-original-compiled.u'=>'38252904ed4ec9c805f166feb3b6e408e8095ac93bbd22754a2a85448ff238b4',
 '20261030-G3-B06-FIXED-C227i-original-compiler.log'=>'421c1828a0944abe20279b0e19e4e099a5c61a79b9530db4c11b55a4dbc19540',
 '20261030-G3-B06-FIXED-C227k-original-SerializeItem.cpp'=>'c0573faf2eaa2f1099acdd56e32643c5f34ef3bc77b230be9fa599e721865e4b',
 '20261030-G3-B06-FIXED-C227k-original-SerializeItem.log'=>'ac4ddf1b9b84c6488db12278434430e96b9580aa4b90e27c109b16f5bb8f4b32',
 '20261030-G3-B06-FIXED-current-source-comparison.php'=>'7dbd55b3eba7a32421650642f4cf10de06541037cc33e8aca53d87d9a2c499d0',
 '20261030-G3-B06-FIXED-V432-original-T047-partial-MAP.cpp'=>'ecb272520831d822dfcb68a53381b9c90e583d131a97b62d66b2a01176c67af3',
 '20261030-G3-B06-FIXED-V432-original-T047-partial-MAP.log'=>'e137d776744395d9581dbe317bc3540deeea600223d9076f5427f4b3b01f40cf',
 '20261030-G3-B06-FIXED-C227k-original-T047-partial-MAP.cpp'=>'a432235ca7d988c637c4600067c821a32855b1b3dfedd94d4acc5f3e63d14dbd',
 '20261030-G3-B06-FIXED-C227k-original-T047-partial-MAP.log'=>'82911deb40aef6797ff76901e40530f0d174944f89d94aebed6f392e1f585051'
];
foreach($hashes as $name=>$sha)$ck(is_file($ev.$name)&&hash_file('sha256',$ev.$name)===$sha,'immutable original compiled proof SHA '.$name);
$cf='C:/Temp/UnrealDB/O06/Native-C227i-G304/System/G3Fixed227.u';
$ck(is_file($cf)&&hash_file('sha256',$cf)===$hashes['20261030-G3-B06-FIXED-C227i-original-compiled.u'],
 'real original 2012 UCC package exact identity with immutable sealed real binary');
$source=(string)file_get_contents($ev.'20261030-G3-B06-FIXED-C227i-original-source.uc');
foreach([
 'class G3Fixed227Actor extends Actor;',
 'struct G3PlainPair','var() int Number;','var() float Fraction;',
 'var() array<G3PlainPair> PlainPairs;','var() int TwoInts[2];',
 'PlainPairs(0)=(Number=101,Fraction=1.25)',
 'PlainPairs(1)=(Number=-202,Fraction=-2.75)',
 'TwoInts(0)=123','TwoInts(1)=-456'
] as $needle)$ck(str_contains($source,$needle),'real 2012 UCC source compiled inner type or default '.$needle);
$log=static function(string $name)use($ev):string{
 $s=(string)file_get_contents($ev.$name);
 return str_starts_with($s,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($s,2)):$s;
};
$compiler=$log('20261030-G3-B06-FIXED-C227i-original-compiler.log');
$ck(str_contains($compiler,'Parsing G3Fixed227Actor')&&str_contains($compiler,'Compiling G3Fixed227Actor')&&str_contains($compiler,'0 warning(s)'),
 'genuine unmodified 2012 UnrealScript compiler accepts real original source 0 warnings');
$p=new P($cf);
$ck(filesize($cf)===954&&$p->getHeader()['version']===69&&$p->getHeader()['licensee']===0,
 'original compiled C227i 954-byte v69 licensee0 package metadata');
$layout=S::resolve($cf,$p,1,'unreal-v227k');
$ck(($layout['status']??null)==='struct_local_field_layout_resolved'&&($layout['struct_reference']??null)===1,
 'real compiled 2012 UCC original UStruct G3PlainPair reference1 real local native field layout');
$ck(array_column($layout['fields'],'name')===['Number','Fraction'],
 'native UStruct::SerializeBin real source TFieldIterator exact two field names/order');
$ck(array_column($layout['fields'],'kind')===['intproperty','floatproperty'],
 'native real C227 field serializers exact primitive types4+4 bytes, no invented name reference');
$ck($layout['source_field_order_verified']===true&&$layout['runtime_offsets_verified']===false,
 'source layouts resolved from original native export; no fabricated in-memory offsets');
$c=C::inspect($cf,$p,7,false,'unreal-v227i');
$ck(($c['source_file_archive_complete']??false)===true,
 'original C227i compiled class has fully consumed tagged default source-backed archive');
$tags=$c['default_property_tags']??[];
$ck(count(array_filter($tags,static fn($t)=>$t['name']==='TwoInts'))===2,
 'original UCC declared TWO elements of standard UProperty ArrayDim2, distinct from real native UFixedArrayProperty');
$cpp=(string)file_get_contents($ev.'20261030-G3-B06-FIXED-C227k-original-SerializeItem.cpp');
$ck(str_contains($cpp,'UFixedArrayProperty* fixedInt=new(')&&str_contains($cpp,'UFixedArrayProperty* fixedStruct=new('),
 'actually compiled 2026 original Core.dll instantiated BOTH original real UFixedArrayProperty native UObject classes');
$ck(str_contains($cpp,'fixedInt->AddCppProperty(intP,2)')&&str_contains($cpp,'fixedStruct->AddCppProperty(structP,2)'),
 'source-defined original UFixedArrayProperty::AddCppProperty actual Count2 and real loaded genuine UIntProperty/UStructProperty child descriptors');
$ck(str_contains($cpp,'fixedInt->SerializeItem(ar,original)')
 &&str_contains($cpp,'fixedInt->SerializeItem(reader,loadedValues)'),
 'real genuine original C227k native SerializeItem executes on both saved and second loaded primitive buffer');
$ck(str_contains($cpp,'fixedStruct->SerializeItem(buf,loadedSource->GetData())')
 &&str_contains($cpp,'fixedStruct->SerializeItem(rd,restored)'),
 'real original C227k native fixed UStruct Serializer SAVE and LOAD on actual C227i native CDO data');
$nativeText=$log('20261030-G3-B06-FIXED-C227k-original-SerializeItem.log');
$ck(str_contains($nativeText,'G3_SCALAR_NATIVE_PACKAGE ')&&str_contains($nativeText,'bad=0')&&str_contains($nativeText,'G3_SCALAR_NATIVE_CLASS '),
 'original 2026 compiled Core.dll loaded genuine original 2012 script class, no unsupported fake class');
$ck(str_contains($nativeText,'G3_B06_REAL_ORIGINAL_INNER_POINTERS int=')&&str_contains($nativeText,'struct='),
 'original C227k live reflected UProperty fields source-resolved and nonzero');
$ck(preg_match('/G3_B06_REAL_ORIGINAL_FIXEDINT saved=8 cursor=8 input=123,-456 loaded=123,-456 hex=([0-9a-f]+)/i',$nativeText,$m)===1,
 'actual original true UFixedArrayProperty primitive SAVE 8, LOAD cursor8 and exact loaded123,-456');
$primitiveHex=$m[1]??'';
$ck(strtolower($primitiveHex)==='7b00000038feffff','exact original native 2x4-byte signed INT, no signed compact count or surplus bytes');
$ck(preg_match('/G3_B06_REAL_ORIGINAL_FIXEDSTRUCT_ALLOC \S+ count=2 stride=8/',$nativeText)===1,
 'actual original native source UStruct memory inner stride8 and fixed Count2');
$ck(preg_match('/G3_B06_REAL_ORIGINAL_FIXEDSTRUCT saved=16 cursor=16 hex=([0-9a-f]+)/i',$nativeText,$m)===1,
 'original compiled true UFixedArrayProperty nested UStruct SAVE16 LOAD cursor16 no dynamic count');
$structHex=$m[1]??'';
$ck(strtolower($structHex)==='650000000000a03f36ffffff000030c0',
 'genuine original Core.dll fixed nested 2x8 int/float little-endian bytes exactly 16');
foreach([
 'pair=0 Number=101','pair=0 Fraction=1.250000',
 'pair=1 Number=-202','pair=1 Fraction=-2.750000'
] as $fragment)$ck(str_contains($nativeText,'G3_B06_REAL_FIXEDSTRUCT_LOADED '.$fragment),
 'actual original native independently deserialized nested fixed struct source field '.$fragment);
$intInner=[
 'name'=>'OriginalNativeUInt','kind'=>'intproperty','array_dim'=>1,
 'skip_persistent_archive'=>false,'nested_layout'=>null
];
$outerStatic=['struct_reference'=>null,'fields'=>[[
 'name'=>'OriginalNativeUFixedArrayInt','kind'=>'fixedarrayproperty',
 'array_dim'=>1,'skip_persistent_archive'=>false,'fixed_array_count'=>2,
 'array_inner_layout'=>$intInner
]]];
$raw=hex2bin($primitiveHex);$pos=0;
$decoded=S::readAt($raw,$pos,$outerStatic,true,$p);
$inner=$decoded['members'][0]['values'][0]??[];
$ck($pos===8&&($decoded['serialized_bytes']??null)===8,
 'current UnrealDB real source fixed primitive native bytes fully consumed at Tell8');
$ck(($inner['source_archive_count_prefix']??true)===false
 &&($inner['source_element_count']??null)===2
 &&($inner['items'][0]['values']??null)===[123,-456],
 'source-linked reader real primitive exactly Count2, no dynamic prefix, both native values match');
$outerNested=['struct_reference'=>null,'fields'=>[[
 'name'=>'OriginalNativeUFixedArrayStruct','kind'=>'fixedarrayproperty',
 'array_dim'=>1,'skip_persistent_archive'=>false,'fixed_array_count'=>2,
 'array_inner_layout'=>[
  'name'=>'OriginalC227iG3PlainPair','kind'=>'structproperty',
  'array_dim'=>1,'skip_persistent_archive'=>false,'nested_layout'=>$layout
 ]
]]];
$raw=hex2bin($structHex);$pos=0;
$decoded=S::readAt($raw,$pos,$outerNested,true,$p);
$v=$decoded['members'][0]['values'][0]??[];
$ck($pos===16&&($decoded['status']??null)==='struct_field_values_decoded'
 &&($decoded['serialized_bytes']??null)===16,
 'production source-linked original native fixed struct2 consumes exactly16 original bytes');
$ck(($v['source_archive_count_prefix']??true)===false&&($v['source_element_count']??null)===2,
 'actual UFixedArrayProperty fixed Count2 no dynamic AR_INDEX in production source-linked struct reader');
$items=$v['items'][0]['values']??[];
$ck(count($items)===2,'true original source-linked nested two structs not just first array element');
$ck(($items[0]['members'][0]['values'][0]??null)===101
 &&($items[0]['members'][1]['values'][0]??null)===1.25
 &&($items[1]['members'][0]['values'][0]??null)===-202
 &&($items[1]['members'][1]['values'][0]??null)===-2.75,
 'production UStructSerializer source int/float nested2 exact four values match actual original Core.dll native loaded');
$map432=$log('20261030-G3-B06-FIXED-V432-original-T047-partial-MAP.log');
$map227=$log('20261030-G3-B06-FIXED-C227k-original-T047-partial-MAP.log');
$ck(str_contains($map432,'idx=0 originalElements=0 archived=0 readerPos=0 remaining=0')
 &&str_contains($map432,'idx=1 originalElements=2 archived=0 readerPos=0 remaining=2'),
 'separately original UT99 v432 real map zero / nonzero has 0/0 bytes, NOT guessed no-data');
$ck(str_contains($map227,'idx=0 bytes=1 hex=00')
 &&str_contains($map227,'idx=1 bytes=17 hex=020C000000B004000022000000480D0000')
 &&str_contains($map227,'idx=1 count=2 tell=17'),
 'separately genuine original 227k TScriptMap two nonzero integer pairs writes 17/17 NOT v432 no-data; release-specific T047 deliberately still BLOCKED pending 2012/reader map gate');
$ok=$fails===[];
echo json_encode([
 'ok'=>$ok,'checks'=>$n,'failures'=>$fails,
 'full_frozen_g3_ids'=>['T045'=>'PASS','T046'=>'PASS'],
 'original_C227i_compiler_warnings'=>0,
 'original_C227k_native_fixed_primitive'=>['members'=>[123,-456],'bytes'=>8,'read_cursor'=>8],
 'original_C227k_native_fixed_nested'=>['members'=>[[101,1.25],[-202,-2.75]],'bytes'=>16,'read_cursor'=>16],
 'T047_partial_v432_nonempty_original_map_bytes'=>0,
 'T047_partial_227k_nonempty_original_map_bytes'=>17,
 'T047_partial_status'=>'BLOCKED_2012_native_profile_and_production_family_gate_incomplete',
 'G3_full_accepted'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
