#!/usr/bin/env php
<?php
/** Frozen G3 T034 and grouped 9-ID original May 1998 C98 scalar/struct oracle.
 * Original source+compiler+actual Core.dll CDB LOAD proof; never replayed here. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicStructArrayValueReader as S;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$ev=$root.'/catalog/compliance/evidence/o06/';
$system='C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/';
$source='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/';
$checks=0;$failed=[];
$ck=static function(bool $cond,string $label)use(&$checks,&$failed):void{++$checks;if(!$cond)$failed[]=$label;};
$pins=[
 [$ev.'20261030-G3-BULK-C98-original-compiler-G3C98Bulk.u','40fa22b955451b489d13b8b19e478c55965efcfb569b497cf8a312e6c73521a1'],
 [$ev.'20261030-G3-BULK-C98-original-compiler-G3C98Bulk.uc','5b8e70c48cb6c9e5d97ef6cf5113f138adf7298878b2c9e06db8325c1224e8c1'],
 [$ev.'20261030-G3-BULK-C98-original-native-loaded-values.log','4e0bb7746aeaa33b6bc6f86419e1471748305085f7cd97db9acd388301cecd94'],
 [$ev.'20261030-G3-BULK-C98-original-native-CDB.cmd','b8c1c18120c2adbc2145b9f64d0f5d4475337211f948fd095db4c1f91334b6cd'],
 [$ev.'20261030-G3-BULK-C98-original-native-successful-Editor.log','5a6e3f620e2f62f6c9dc6b554b8822718f144f4c2e6d2a73e3dc2601b54ca9b7'],
 [$system.'Core.dll','9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92'],
 [$source.'Src/UnClass.cpp','ea4b7304d6cfe45f35929b59a10cefe51e5ee132f3603fc43e3f0e3d97aa1291'],
 [$source.'Inc/UnType.h','7f3092fe5025f077cd61cdc89598ae135867ad064a40a5fffbfaf9be057e86b8'],
 [$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicStructArrayValueReader.php','a55457d788c91a9b9021c5489b9b94d6b913a8049fe54c4abf02ec453bad58d9']
];
foreach($pins as [$file,$sha])$ck(is_file($file)&&hash_file('sha256',$file)===$sha,'SHA256 source-original/native '.basename($file));
$f=$system.'G3C98Bulk.u';$archive=$ev.'20261030-G3-BULK-C98-original-compiler-G3C98Bulk.u';
$ck(is_file($f)&&hash_file('sha256',$f)===hash_file('sha256',$archive),'original native-loaded package matches preserved compiler bytes');
$p=new P($f);$ck($p->getHeader()['version']===61,'real original May1998 C98 UE1 package v61');
$r=C::inspect($f,$p,5,true,'epic-ue1');
$ck($r['source_file_archive_complete']===true&&$r['default_property_declaration_validation']['status']==='all_available_declarations_match','source-selected C98 UClass fully consumed and all declaration matches');
$tags=[];foreach($r['default_property_tags'] as $tag)$tags[(string)$tag['name']][]=$tag;
$ck(count($r['default_property_tags'])===12,'original compiler saved exactly 12 nondefault property tags; defaults omitted');
$ck(count($tags['G3Struct']??[])===1&&$tags['G3Struct'][0]['serialized_size']===9&&($tags['G3Struct'][0]['struct_name']['text']??null)==='G3Child','real source-linked 9-byte nested G3Child tag');
$pos=(int)($tags['G3Struct'][0]['value_offset']??-1);
$wire=file_get_contents($f);
$ck($pos===1220&&bin2hex(substr((string)$wire,$pos,9))==='111503000000002040','original real compiler SerializeBin child CZ first, then parent BX and BY exact 9 bytes');
$sample=$r['default_property_declaration_validation']['source_struct_field_value_samples'][0]??[];
$ck($r['default_property_declaration_validation']['source_struct_field_values_decoded']===1
 &&$r['default_property_declaration_validation']['source_struct_field_values_unverified']===0
 &&$sample['status']==='struct_field_values_decoded'
 &&$sample['serialized_bytes']===9,'actual UnrealDB source-linked inherited struct SerializeBin decoder fully consumes wire');
$members=$sample['members']??[];
$ck(array_column($members,'name')===['CZ','BX','BY']
 &&array_column($members,'kind')===['byteproperty','intproperty','floatproperty']
 &&array_map(static fn($e)=>$e['values'][0],$members)===[17,789,2.5],
 'true original source child-first archive order and exact member values');
$t=T::inspect($f,$p,19,'epic-ue1');$native=N::inspect($f,$p,19,$t,'epic-ue1');
$ck($native['status']==='native_property_record_complete'&&$native['property_kind']==='structproperty'
 &&($native['subclass_fields']['struct_reference']??null)===1,'actual linker G3Struct property references local G3Child exported UStruct index1');
$layout=S::resolve($f,$p,1,'epic-ue1');
$ck($layout['status']==='struct_local_field_layout_resolved'
 &&$layout['source_field_order_verified']===true
 &&$layout['source_imported_struct_hops']===0
 &&array_column($layout['fields'],'name')===['CZ','BX','BY'],'real UField Children/Next/SuperField inheritance with source-defined order');
$sourceCpp=(string)file_get_contents($source.'Src/UnClass.cpp');
$sourceH=(string)file_get_contents($source.'Inc/UnType.h');
$ck(str_contains($sourceCpp,'void UStruct::SerializeBin( FArchive& Ar, BYTE* Data )')
 &&str_contains($sourceCpp,'It->SerializeItem( Ar, Data + It->Offset + Index*It->GetElementSize() );')
 &&str_contains($sourceH,'Struct->SerializeBin( Ar, (BYTE*)Value );'),'actual original Epic 1998 C98 source calls linked SerializeBin and each source member offset');
$uc=(string)file_get_contents($ev.'20261030-G3-BULK-C98-original-compiler-G3C98Bulk.uc');
$ck(str_contains($uc,'struct G3Child expands G3Base')
 &&str_contains($uc,'var int BX;')&&str_contains($uc,'var float BY;')
 &&str_contains($uc,'var byte CZ;'),'original source compiler fixture has inherited parent fields and child member');
$log=(string)file_get_contents($ev.'20261030-G3-BULK-C98-original-native-loaded-values.log');
preg_match('/G3_BULK_C98_ALL_CLASS_POSTLOAD\s*\n\s*[0-9a-f]{8}\s+00000005\s*\n\s*[0-9a-f]{8}\s+([0-9a-f]{8})\s+0000021c\s+0000021c[^\n]*\n\s*([0-9a-f]{8})\s+80000001\s+7fffffff\s+bfc00000\s+00000000[^\n]*\n\s*([0-9a-f]{8})\s+00000315\s+40200000\s+00000011/i',$log,$m);
$ck(count($m)===4,'original compiled C98 debugger actual UClass export5 native CDO size540 with loaded nonzero INT/FLOAT/struct');
$ck(count($m)===4 &&hexdec($m[3])-hexdec($m[1])===0x1e8
 &&hexdec($m[3])-hexdec($m[2])===0x20,'original native struct memory offset 488 and scalar link difference32 bytes');
$ck(count($m)===4 &&array_map('hexdec',explode(' ', '00000315 40200000 00000011'))===[789,1075838976,17],
 'compiled original native struct layout parent BX789 offset0, BY2.5 bits0x40200000 offset4, child CZ17 offset8');
$ck(str_contains((string)file_get_contents($ev.'20261030-G3-BULK-C98-original-native-successful-Editor.log'),'Loading: Package G3C98Bulk')
 &&str_contains((string)file_get_contents($ev.'20261030-G3-BULK-C98-original-native-successful-Editor.log'),'Object subsystem successfully closed.'),
 'real original compiler-generated package engine independent LOAD succeeds to proper exit');
$covered=[
 'T019'=>'BLOCKED: only C98 single native config profile, C227/v432 and isolated two-profile override absent',
 'T021'=>'BLOCKED: C98 byte/int positive, negative, max saved, UT99 and C227 native edge operators outstanding',
 'T022'=>'BLOCKED: C98 float -1.5/0.375/0 archived; negative zero and other original source families outstanding',
 'T023'=>'BLOCKED: C98 bool true plus omitted false, original runtime bool mask/index across families outstanding',
 'T024'=>'BLOCKED: original real named tag G3BulkName and omitted None, compiled global native FName identity across families outstanding',
 'T034'=>'PASS: original C98 inherited local struct compiler archive and compiled loaded native member offsets/values',
 'T041'=>'BLOCKED: C98 static int[2] is NOT UT99/C227 dynamic array<int> LOAD+SAVE; source class unavailable in C98',
 'T062'=>'BLOCKED: original compiled controlled INI/localized two-profile and all applicable build precedence outstanding',
 'T063'=>'BLOCKED: original C98 transient default omission observed, independent object native SAVE+LOAD per family outstanding'
];
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failures'=>$failed,'original_1998_compiled_shared_fixture'=>true,
 'native_loaded_struct_address'=>$m[3]??null,'original_native_array_bytes'=>[789,2.5,17],
 'source_struct_archived_sequence'=>['CZ'=>17,'BX'=>789,'BY'=>2.5],
 'fixed_T_IDs_grouped_status'=>$covered,'T034'=>$ok?'PASS':'BLOCKED',
 'original_compiled_executions_repeated_by_verifier'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
