#!/usr/bin/env php
<?php
/** One new G3 native evidence batch T037,T038,T040, no previously accepted native probe reruns. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);
$ev=$root.'/catalog/compliance/evidence/o06/';
$n=0;$fail=[];
$ck=static function(bool $ok,string $why)use(&$n,&$fail):void{++$n;if(!$ok)$fail[]=$why;};
$expected=[
 '20261030-G3-B05-NEST-V432-original-STRUCT-SAVE.u'=>'c65dd008e97a431b0b8166f1e80359303a2316f3e0df3b5a1b9b5556ee93c96f',
 '20261030-G3-B05-NEST-V432-original-STRUCT-LOAD.log'=>'607f914e8e797f23c08a11233ea1aab7f47d89af98988e722634d5f10e2a9226',
 '20261030-G3-B05-NEST-V432-original-BAD-STRING.u'=>'3572b10a95e11772726ad92f75448f26c1d2dc0c32c402aeec4ec3386d9a0114',
 '20261030-G3-B05-NEST-V432-original-BAD-STRING.log'=>'4d3cf87aef0127f995baf384866df7e5981b17101ee7557c45df94450626feb8',
 '20261030-G3-B05-NEST-V432-original-STRUCT-SAVE.cpp'=>'3203b091a14191a86ffbf41f3183b4ee4ac60e4f1e7d9a01ee0b68afb5f02fe3',
 '20261030-G3-B05-NEST-C227k-original-BAD-NAME.u'=>'85664998a635464788fe6479d86bbc992bfb34eabfb8a61ac6b5fe5d5b9b8d2b',
 '20261030-G3-B05-NEST-C227k-original-BAD-NAME.log'=>'f815170f2e241eee1eddb283171c0cf2027b28635481148a7899a16964d6a601',
 '20261030-G3-B06-AGG-C227i-original-compiler.u'=>'ddea6bb9110b1c061f24efadae4b5ce3d81eb4a8ba7294d170e1e320263287e5',
 '20261030-G3-B06-AGG-C227k-original-SAVE.u'=>'34a00b4f5bdf78dd56ef62a79f62684b6159fb0ecfc43e630eaa08c7ee09d8f7',
 '20261030-G3-B06-AGG-C227k-original-LOAD.log'=>'f48e72ab189703938f8c4b775d55046973569e1a7565163a7cf3aa5958cd6b78',
 '20261030-G3-B06-AGG-C227k-arrayZero.u'=>'7e93a957b6aad48775150da8b46e7d9471f52099a62952e63bbc9531a437c333',
 '20261030-G3-B06-AGG-C227k-arrayZero-native.log'=>'e3b5858aa37af3fac5b53571abd36fae819a66cfe50400cc272606e73f7e250f',
];
foreach($expected as $name=>$sha)$ck(is_file($ev.$name)&&hash_file('sha256',$ev.$name)===$sha,'original native/compiler SHA pinned '.$name);
$reader=$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php';
$ck(hash_file('sha256',$reader)==='90f161b06b08900e8171ca0ba597f4123f09097d1c0c4e127cc7291dbc57f4dd',
 'current actual source-linked reader byte identity after native 227k zero-count recovery');
$log=static function(string $name)use($ev):string{
 $s=(string)file_get_contents($ev.$name);
 return str_starts_with($s,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($s,2)):$s;
};
$direct='C:/Temp/UnrealDB/O06/Native-UCC/System/G5StructStringDirectPayload.u';
$bad='C:/Temp/UnrealDB/O06/Native-UCC/System/G5StructStringDirectBad.u';
foreach([
 $direct=>'20261030-G3-B05-NEST-V432-original-STRUCT-SAVE.u',
 $bad=>'20261030-G3-B05-NEST-V432-original-BAD-STRING.u'
] as $real=>$sealed)$ck(
 is_file($real)&&hash_file('sha256',$real)===$expected[$sealed],
 'real original 1999 loaded native package has identical bytes to immutable sealed evidence '.$sealed);
$p=new P($direct);
$ck(filesize($direct)===309&&$p->getHeader()['version']===69,'actual original compiled UT99 v432 309-byte .u with genuine nested direct struct');
$ck($p->validatePackage()===[],'actual v432 saved native .u fully valid package envelope');
$x=T::inspect($direct,$p,0,'ut99-v432');$t=$x['tags'][0]??[];$v=$t['value']??[];
$ck(($x['status']??null)==='tag_envelope_decoded'&&($x['native_tail_bytes']??null)===0
 &&count($x['tags'])===1,'v432 original genuine direct nested struct saved fully consumed');
$ck(($t['name']??null)==='OneRecord'&&($t['value_kind']??null)==='source_typed_struct'
 &&($t['serialized_size']??null)===19 &&($t['on_disk_value_bytes']??null)===19,
 'actual source-selected v432 UStruct::SerializeBin 19 native archive bytes, not opaque bytes');
$ck(($v['status']??null)==='struct_field_values_decoded'&&($v['serialized_bytes']??null)===19,
 'real native v432 direct struct two nested original field values decoded exactly19');
$m=$v['members']??[];
$ck(count($m)===2&&($m[0]['name']??null)==='Label'&&($m[1]['name']??null)==='Marker',
 'genuine UCC original nested UStruct source field order FString then INT, no fabricated offsets');
$sv=$m[0]['values'][0]??[];
$ck(($sv['signed_character_count']??null)===14&&($sv['ascii_text']??null)==='Direct-Record'
 &&($sv['archive_encoding']??null)==='ansi_8bit' &&($sv['nul_terminated']??false)===true,
 'T037 original native nonempty FString count14 including NUL and exact live loaded label');
$ck(($m[1]['values'][0]??null)===71,
 'T037 original native adjacent UStruct int member marker71 matches reader');
$originalLoad=$log('20261030-G3-B05-NEST-V432-original-STRUCT-LOAD.log');
$ck(str_contains($originalLoad,'G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=')&&str_contains($originalLoad,'native_error=0'),
 'original genuine retail UT99 v432 compiled Core.dll independently loaded direct nested struct successfully');
$ck(str_contains($originalLoad,'G5_NATIVE_TAG_VALUE  OneRecord=(Label="Direct-Record",Marker=71)'),
 'actual live native v432 object carries same nonempty nested FString Direct-Record and Marker71, not simply compiled archive');
$nestedSrc=$log('20261030-G3-B05-NEST-V432-original-STRUCT-SAVE.cpp');
$ck(str_contains($nestedSrc,'UObject::SavePackage(')&&str_contains($nestedSrc,'SetRecord(')
 &&str_contains($nestedSrc,'TEXT("Direct-Record"),71'),
 'authentic original native program produced that exact 309-byte direct struct with source nested string');
$good=(string)file_get_contents($direct);$corrupt=(string)file_get_contents($bad);
$diff=[];for($i=0;$i<strlen($good);$i++)if($good[$i]!==$corrupt[$i])$diff[]=$i;
$ck(strlen($good)===309&&strlen($corrupt)===309&&count($diff)===1,
 'T040 original genuine direct nested FString malformed by only 1 byte preserving original package structure');
$err='';
try{$bp=new P($bad);T::inspect($bad,$bp,0,'ut99-v432');}catch(Throwable $e){$err=$e->getMessage();}
$ck($err==='UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset 309 during direct struct FString<<',
 'T040 current source-selected nested UStrProperty reader errors at exact original 309 physical EOF method, no fabricated loaded nested struct');
$badLog=$log('20261030-G3-B05-NEST-V432-original-BAD-STRING.log');
$ck(str_contains($badLog,'ReadFile beyond EOF 309+1/309')
 &&str_contains($badLog,'UStruct::SerializeBin')
 &&str_contains($badLog,'FString<<')
 &&str_contains($badLog,'FPropertyTag::SerializeTaggedProperty'),
 'T040 actual original v432 compiled native nested FString error traceback+physical Tell309 matches reader');
$ck(str_contains($badLog,'native_error=1')
 &&str_contains($badLog,'ptr=00000000'),
 'T040 original v432 malformed nested FString produces no loaded object, not false success');
$orig='C:/Temp/UnrealDB/O06/Native-227k/System/G3Array227Runtime.u';
$zero='C:/Temp/UnrealDB/O06/Native-227k/System/G3Array227RuntimeZero.u';
$badName='C:/Temp/UnrealDB/O06/Native-227k/System/G3Array227RuntimeBadNestedName.u';
foreach([
 $orig=>'20261030-G3-B06-AGG-C227k-original-SAVE.u',
 $zero=>'20261030-G3-B06-AGG-C227k-arrayZero.u',
 $badName=>'20261030-G3-B05-NEST-C227k-original-BAD-NAME.u'
] as $real=>$sealed)$ck(
 is_file($real)&&hash_file('sha256',$real)===$expected[$sealed],
 'actual original live source-resolvable C227k binary exact sealed evidence '.$sealed);
$base=(string)file_get_contents($orig);$z=(string)file_get_contents($zero);$b=(string)file_get_contents($badName);
$ck(strlen($base)===308 &&strlen($z)===308&&strlen($b)===308,'authentic 2026 native SavePackage struct array original and two one-byte mutants all 308 bytes');
$diff=static function(string $a,string $b):array{
 $d=[];for($i=0;$i<strlen($a);$i++)if($a[$i]!==$b[$i])$d[]=$i;return $d;
};
$ck($diff($base,$z)===[234]&&ord($base[234])===2&&ord($z[234])===0,
 'T038 exact single original source count2 -> count0 at offset234, no extra archive modifications');
$ck($diff($base,$b)===[243]&&ord($base[243])===4&&ord($b[243])===63,
 'T040 exact one nested member FName reference4 -> invalid63 at archive offset243, original .u untouched');
$op=new P($orig);$zr=T::inspect($zero,new P($zero),0,'unreal-v227k');
$rr=T::inspect($orig,$op,0,'unreal-v227k');
$ck(count($rr['tags'])===2 &&count($zr['tags'])===2
 &&($rr['native_tail_bytes']??null)===0&&($zr['native_tail_bytes']??null)===0
 &&($rr['tag_end_offset']??null)===280&&($zr['tag_end_offset']??null)===280,
 'T038 source-selected original nonempty and zero-count .u rejoin identical native export end280, no fake duplicate tags');
$pos=$rr['tags'][0]??[];$empty=$zr['tags'][0]??[];
$ck(($pos['value']['element_count']??null)===2
 &&($pos['value']['serialized_element_bytes']??null)===19
 &&($pos['on_disk_value_bytes']??null)===19,
 'T038 real original native saved two UStruct element count2 and 19 total source bytes');
$ck(($empty['value']['element_count']??null)===0
 &&($empty['value']['serialized_element_bytes']??null)===1
 &&($empty['value']['native_227k_tag_size_recovery']['recovered_archive_byte_count']??null)===18
 &&($empty['on_disk_value_bytes']??null)===19,
 'T038 source-defined 227k zero count original got1 expected19 recovery skipped18 and did not invent members');
$ck(($zr['tags'][1]['value']['preview_first_32'][0]['ascii_text']??null)==='Alpha-227'
 &&($zr['tags'][1]['value']['preview_first_32'][1]['ascii_text']??null)==='Gamma-227',
 'T038 original native 227k recovers zero count and next two real FString fields, source reader same values/cursor');
$members=$pos['value']['preview_first_32']??[];
$ck(count($members)===2
 &&($members[0]['members'][0]['name']??null)==='Number'
 &&($members[0]['members'][1]['name']??null)==='Fraction'
 &&($members[0]['members'][2]['name']??null)==='Label',
 'T038 original two member struct array real UStruct field order Number/Fraction/FName');
$ck(($members[0]['members'][0]['values'][0]??null)===101
 &&($members[0]['members'][1]['values'][0]??null)===1.25
 &&($members[0]['members'][2]['values'][0]??null)===4,
 'T038 first source-linked struct element original native 101,1.25, FName link4 Alpha');
$ck(($members[1]['members'][0]['values'][0]??null)===-202
 &&($members[1]['members'][1]['values'][0]??null)===-2.75
 &&($members[1]['members'][2]['values'][0]??null)===3,
 'T038 second original native -202,-2.75,FName3 Gamma; no duplicate first member');
$loaded=$log('20261030-G3-B06-AGG-C227k-original-LOAD.log');
foreach([
 'G3_B06_NATIVE_RELOADED_ARRAY name=Pairs count=2 innerSize=12',
 'G3_B06_NATIVE_RELOADED_PAIR index=0 field=Number value=101',
 'G3_B06_NATIVE_RELOADED_PAIR index=0 field=Fraction value=1.250000',
 'G3_B06_NATIVE_RELOADED_PAIR index=0 field=Label value=Alpha',
 'G3_B06_NATIVE_RELOADED_PAIR index=1 field=Number value=-202',
 'G3_B06_NATIVE_RELOADED_PAIR index=1 field=Fraction value=-2.750000',
 'G3_B06_NATIVE_RELOADED_PAIR index=1 field=Label value=Gamma'
] as $frag)$ck(str_contains($loaded,$frag),'T038 original 227k second independent compiled native loader actual two struct member values '.$frag);
$zeroLog=$log('20261030-G3-B06-AGG-C227k-arrayZero-native.log');
$ck(str_contains($zeroLog,'size mismatch (got 1 expected 19 bytes)')
 &&str_contains($zeroLog,'G3_B06_NATIVE_RELOADED_ARRAY name=Pairs count=0 innerSize=12')
 &&str_contains($zeroLog,'G3_B06_227_RELOADED_COUNT 1'),
 'T038 original 2026 compiled native recovered from zero-count array mismatch, one object and no invented array members');
$err='';
try{T::inspect($badName,new P($badName),0,'unreal-v227k');}catch(Throwable $e){$err=$e->getMessage();}
$ck($err==='UE1 v227 ULinkerLoad<<FName invalid name index 63 at archive offset 244',
 'T040 original invalid nonzero nested UStruct FName reference fails source-linked reader at precise physical cursor244');
$nameLog=$log('20261030-G3-B05-NEST-C227k-original-BAD-NAME.log');
$ck(str_contains($nameLog,'Bad name index 63/12')&&str_contains($nameLog,'244==244/308'),
 'T040 original compiled Core.dll invalid nested FName index63 of 12 failed at original physical offset244');
$ck(str_contains($nameLog,'ULinkerLoad<<FName')
 &&str_contains($nameLog,'UStruct::SerializeBin')
 &&str_contains($nameLog,'FPropertyTag::SerializeTaggedProperty'),
 'T040 original nested FName exception belongs to UStruct SerializeBin rather than fabricated outer tag error');
$ck(str_contains($nameLog,'bad=1')&&str_contains($nameLog,'runtime=00000000'),
 'T040 original 227k invalid member produces actual null runtime, no false native value');
$ok=$fail===[];
echo json_encode([
 'ok'=>$ok,'checks'=>$n,'failures'=>$fail,
 'full_frozen_g3_ids'=>['T037'=>'PASS','T038'=>'PASS','T040'=>'PASS'],
 'T037'=>['original_v432_nested_nonempty_FString'=>'Direct-Record','original_live_loaded_marker'=>71,'physical_value_bytes'=>19],
 'T038'=>['original_227k_nonempty_struct_count'=>2,'original_227k_zero_struct_count'=>0,'zero_count_recovery_bytes'=>18],
 'T040'=>['original_227k_invalid_nested_FName_physical_error'=>244,'original_v432_nested_FString_physical_EOF'=>309],
 'previously_accepted_native_probes_reexecuted'=>0,
 'G3_overall_accepted'=>false
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
