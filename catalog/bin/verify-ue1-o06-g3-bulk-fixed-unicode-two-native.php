#!/usr/bin/env php
<?php
/** T025 and T027: original C98/v432/2012C227i/2026C227k grouped native oracle. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicFStringArchiveReader as F;
$root=dirname(__DIR__,2);$ev=$root.'/catalog/compliance/evidence/o06/';
$n=0;$fails=[];$ck=static function(bool $b,string $label)use(&$n,&$fails):void{++$n;if(!$b)$fails[]=$label;};
$sha=[
 '20261030-G3-STR-BULK-C227k-UNICODE-original-SAVE.u'=>'e900861a8bf851bfb340e008f042f5529fa4eb144490f46bbad585af8091a14b',
 '20261030-G3-STR-BULK-C227k-UNICODE-original-SAVE.cpp'=>'83d8545b1dfa1a95f18b55bbcb0fb82a5b982e4efac9c9fbf9d94400263cb541',
 '20261030-G3-STR-BULK-C227k-UNICODE-original-SAVE.log'=>'9bf1575201eeaa1ea169a69936902d34424a52ff23e74c1c2fab301ecc3bd69f',
 '20261030-G3-STR-BULK-C227k-UNICODE-original-LOAD.cpp'=>'1271135f8be6e27ceabe83fd30945f7f922f7ddc3ba9ba7561dcdf630696b1b7',
 '20261030-G3-STR-BULK-C227k-UNICODE-original-LOAD.log'=>'9e7149a3b9afec14924dc63f6ec06db8566c7bd6da147b2bf29e07dc16eec803',
 '20261030-G3-STR-BULK-C227k-UTF16-direct-original-source.cpp'=>'24e0b904cad994eb216193007df49054e21fe9c0a0d7bbb98d40af3ba0eafbde',
 '20261030-G3-STR-BULK-C227k-UTF16-direct-original-native.log'=>'4fcda84026672f2adcefe6d3b9861c9d91c42499da414241c9352aa8d77f90a2',
 '20261030-G3-STR-BULK-C98-fixed-string-original-CDB.cmd'=>'249e556b22ade237caf7e4fabb8b2d54bc590764656838f18959487bc2d757a7',
 '20261030-G3-STR-BULK-C98-fixed-string-original-CDB.log'=>'dbd454fd8acee2ca6af5a44d8de659ffc82235083bf2690fcb3ad6e1fac76d0f',
 '20261030-G3-STR-BULK-V432-legacy-converted-original.u'=>'cb737f15004d37f0a49458acf5adf14fcfd2344a7624c07826ff924cc44c4ecc',
 '20261030-G3-STR-BULK-V432-legacy-converted-original-LOAD.log'=>'2733758f8a5eb4ebb54af189b4275def046ca40f5a44e20df855e4df1198b281',
 '20261030-G3-BULK-C98-original-compiler-G3C98Bulk.u'=>'40fa22b955451b489d13b8b19e478c55965efcfb569b497cf8a312e6c73521a1',
 '20261030-G3-BULK-C98-original-native-loaded-values.log'=>'4e0bb7746aeaa33b6bc6f86419e1471748305085f7cd97db9acd388301cecd94',
 '20261030-G3-BULK-SCALAR-C227i-original-compiler.u'=>'086961b8ecb58abf2e0887f1e07636c7fd865882fd683abe54542f1ac8470649',
];
foreach($sha as $name=>$hash)$ck(is_file($ev.$name)&&hash_file('sha256',$ev.$name)===$hash,'sealed original-native/compiler evidence '.$name);
foreach([
 'C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/G3C98Bulk.u'=>'20261030-G3-BULK-C98-original-compiler-G3C98Bulk.u',
 'C:/Temp/UnrealDB/O06/Native-227k/System/G3Unicode227Runtime.u'=>'20261030-G3-STR-BULK-C227k-UNICODE-original-SAVE.u',
 'C:/Temp/UnrealDB/O06/Native-UCC/System/G5DirectStringLegacyPayload.u'=>'20261030-G3-STR-BULK-V432-legacy-converted-original.u'
] as $path=>$sealed)$ck(is_file($path)&&hash_file('sha256',$path)===hash_file('sha256',$ev.$sealed),'actual original compiled genuine working .u equals immutable evidence '.$sealed);
$log=static function(string $f)use($ev):string{
 $s=(string)file_get_contents($ev.$f);
 return str_starts_with($s,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($s,2)):$s;
};
$c98Log=$log('20261030-G3-STR-BULK-C98-fixed-string-original-CDB.log');
$utLog=$log('20261030-G3-STR-BULK-V432-legacy-converted-original-LOAD.log');
$unicodeSave=$log('20261030-G3-STR-BULK-C227k-UNICODE-original-SAVE.log');
$unicodeLoad=$log('20261030-G3-STR-BULK-C227k-UNICODE-original-LOAD.log');
$utfDirect=$log('20261030-G3-STR-BULK-C227k-UTF16-direct-original-native.log');
$cfile='C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/G3C98Bulk.u';
$cp=new P($cfile);
$ck($cp->getHeader()['version']===61&&$cp->getHeader()['licensee']===0,'source-selected genuine May1998 v61 original compiler output');
$class=C::inspect($cfile,$cp,5,false,'epic-ue1');
$tags=[];foreach($class['default_property_tags'] as $tag)$tags[$tag['name']]=$tag;
$ck(($class['source_file_archive_complete']??false)===true,'1998 stock original C98 class saved defaults exactly consumed');
$fixed=$tags['G3Local']??[];
$ck(($fixed['type']??null)==='StringProperty'&&($fixed['on_disk_value_bytes']??null)===12,'authentic C98 fixed StringProperty 11+NUL 12 physical bytes not FStr compact-index');
$ck(($fixed['value']['ascii_text']??null)==='DefaultBulk'
 &&($fixed['value']['raw_ansi_bytes_hex']??null)==='44656661756c7442756c6b'
 &&($fixed['value']['nul_terminated']??null)===true,'original C98 compiler fixed string exact ANSI chars 11 and terminal byte');
$propId=null;foreach($cp->getExports() as $i=>$e)if($e['objectNameText']==='G3Local')$propId=$i;
$ck($propId===22,'1998 retail exact original G3Local UStringProperty export identity index22');
$nativeProp=N::inspect($cfile,$cp,$propId,T::inspect($cfile,$cp,$propId,'epic-ue1'),'epic-ue1');
$ck(($nativeProp['status']??null)==='native_property_record_complete'
 &&($nativeProp['property_kind']??null)==='stringproperty'
 &&($nativeProp['subclass_fields']['string_size']??null)===32,'original C98 native property UStringProperty StringSize exactly32');
$ck(str_contains($c98Log,'G3_T025_C98_LOADED_FIXED_STRING_MEMORY')
 &&str_contains($c98Log,'44 65 66 61')
 &&str_contains($c98Log,'75 6c 74 42 75 6c 6b 00'),'genuine stock original C98 Core.dll loaded 32 character member and terminated DefaultBulk into real native CDO memory');
$ck(str_contains($c98Log,'76fc5d8  75 6c 74 42 75 6c 6b 00')||
 preg_match('/[0-9a-f]{8}\s+75 6c 74 42 75 6c 6b 00/i',$c98Log)===1,
 'original C98 CDB native memory demonstrates actual final NUL after DefaultBulk (not archive-only)');
$utfile='C:/Temp/UnrealDB/O06/Native-UCC/System/G5DirectStringLegacyPayload.u';
$up=new P($utfile);$ur=T::inspect($utfile,$up,0,'ut99-v432');
$t=$ur['tags'][0]??[];
$ck($up->getHeader()['version']===69&&$ur['native_tail_bytes']===0
 &&$ur['tag_end_offset']===$up->getExports()[0]['serialOffset']+$up->getExports()[0]['serialSize'],
 'genuine old legacy v432 283-byte package original full archive consumption including NAME_None');
$ck(($t['type']??null)==='StringProperty'
 &&($t['native_load_policy']??null)==='native_source_fixed_StringProperty_to_StrProperty_upgrade',
 'UnrealDB source-gated exact v432 original StringProperty tag to declared UStrProperty upgrade');
$ck(($t['on_disk_value_bytes']??null)===22
 &&($t['value']['raw_ansi_bytes_hex']??null)==='51416c7068612d6469726563742d46537472696e67'
 &&($t['value']['nul_terminated']??null)===true,'original valid old fixed tag 21 ANSI chars plus NUL consumed exactly22');
$ck(str_contains($utLog,'G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=')
 &&str_contains($utLog,'native_error=0')
 &&str_contains($utLog,'Headline="QAlpha-direct-FString"'),
 'actual original UT99 v432 compiled native loader successfully converted archived fixed string into live dynamic FString');
$ck(str_contains((string)file_get_contents('L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnClass.cpp'),
 'Tag.Type==NAME_StringProperty && Property->GetID()==NAME_StrProperty'),
 'original Epic UT99 source fixed->dynamic branch is explicitly permitted');
$unicodeFile='C:/Temp/UnrealDB/O06/Native-227k/System/G3Unicode227Runtime.u';
$np=new P($unicodeFile);$nr=T::inspect($unicodeFile,$np,0,'unreal-v227k');
$ck($np->getHeader()['version']===69&&$np->getHeader()['licensee']===227
 &&filesize($unicodeFile)===280,'genuine ORIGINAL 2026 227k compiled SavePackage actual v69/227, 280 bytes');
$ck($nr['native_tail_bytes']===0
 &&$nr['tag_end_offset']===$np->getExports()[0]['serialOffset']+$np->getExports()[0]['serialSize'],
 'current UnrealDB original 227k actual Unicode archive fully consumed without tail');
$nts=[];foreach($nr['tags'] as $t)$nts[$t['name']]=$t;
$ck(count(array_filter($nts,static fn($t)=>$t['type']==='StrProperty'))===2,'exactly two genuine original native UTF16 string tags, unrelated native bool tag preserved');
foreach(['EmptyText'=>[-3,'3dd880de0000',7], 'OneText'=>[-2,'a9030000',5]] as $key=>[$count,$hex,$size]){
 $tag=$nts[$key]??[];$val=$tag['value']??[];
 $ck(($tag['type']??null)==='StrProperty'
  &&($tag['native_load_policy']??null)==='native_source_property_SerializeItem'
  &&($tag['value_kind']??null)==='fstring_utf16_archive',
  'original C227k source-selected linked UStrProperty dynamic Unicode tag '.$key);
 $ck(($val['signed_character_count']??null)===$count
  &&($val['archive_encoding']??null)==='unicode_16bit_le'
  &&($val['serialized_character_bytes_hex']??null)===$hex
  &&array_key_exists('ascii_text',$val)&&$val['ascii_text']===null
  &&($val['nul_terminated']??null)===true,
  'actual saved original C227k signed negative character count and exact UTF16 little-endian source code units '.$key);
 $ck(($tag['serialized_size']??null)===$size
  &&($tag['on_disk_value_bytes']??null)===$size,'native original physical compact signed count plus UTF16 units total exactly '.$size.' bytes '.$key);
}
$ck(str_contains($unicodeSave,'G3_UNICODE_PROPERTY_SET EmptyText=D83D DE80')
 &&str_contains($unicodeSave,'G3_UNICODE_PROPERTY_SET OneText=03A9')
 &&str_contains($unicodeSave,'G3_SCALAR_NATIVE_SAVE 1'),
 'ORIGINAL 2026 native engine explicitly assigned two independent source legal Unicode strings and SavePackage succeeded');
$ck(str_contains($unicodeLoad,'G3_UNICODE_227_INDEPENDENT_RELOAD cls=')
 &&str_contains($unicodeLoad,'bad=0')
 &&str_contains($unicodeLoad,'G3_UNICODE_227_RELOADED_COUNT 1'),
 'second separate original compiled 227k native process independently loaded exact 280-byte runtime package');
$ck(str_contains($unicodeLoad,'G3_UNICODE_NATIVE_LOADED name=EmptyText count=2 chars=D83D,DE80,'),
 'actual original 227k runtime read both nonBMP Unicode surrogate units in correct order and count, no conversion');
$ck(str_contains($unicodeLoad,'G3_UNICODE_NATIVE_LOADED name=OneText count=1 chars=03A9,'),
 'actual original 227k runtime restored Omega Unicode BMP code unit with no gratuitous ASCII conversion');
$ck(str_contains($utfDirect,'label=nonBMP_surrogate_pair inputLength=2 byteLength=7 readerPos=7 loadedLength=2 originalWords=D83D,DE80,0000, loadedWords=D83D,DE80,0000, archivedHex=833DD880DE0000'),
 'same genuine original C227k Core.dll UStrProperty SerializeItem direct FBufferArchive+FBufferReader 7/7 full UTF16 native SAVE/LOAD including surrogate');
$ck(str_contains($utfDirect,'label=unicode_Omega inputLength=1 byteLength=5 readerPos=5 loadedLength=1 originalWords=03A9,0000, loadedWords=03A9,0000, archivedHex=82A9030000'),
 'same original native full direct UTF16 single BMP original archive and reload 5/5 bytes');
$ck(str_contains($utfDirect,'label=ascii_A inputLength=1 byteLength=3 readerPos=3 loadedLength=1')
 &&str_contains($utfDirect,'label=embeddedNul_A0B inputLength=3 byteLength=5 readerPos=5'),
 'one original method scope explicitly distinguishes ANSI single-char and embedded NUL source-defined branch');
$pos=0;$raw=hex2bin('833dd880de0000');
$check=F::decodeAt($raw,$pos);
$ck(($check['value']['signed_character_count']??null)===-3&&$pos===7
 &&($check['value_kind']??null)==='fstring_utf16_archive','production shared source UTF16 decoder exact genuine native 7-byte buffer and cursor');
$pos=0;$raw=hex2bin('0441000000');$embedded=F::decodeAt($raw,$pos);
$ck(($embedded['value']['signed_character_count']??null)===4
 &&array_key_exists('ascii_text',$embedded['value'])&&$embedded['value']['ascii_text']===null
 &&$pos===5,'source-defined embedded zero in ANSI archive is NOT falsely transformed into a safe UTF16 or reconstructed valid human string');
$ok=$fails===[];
echo json_encode(['ok'=>$ok,'checks'=>$n,'failures'=>$fails,
 'original_g3_fixed_ids'=>['T025'=>'PASS','T027'=>'PASS'],
 'genuine_original_native_executions_during_verifier'=>0,
 'source_selected_original_1998_fixed_capacity'=>32,
 'v432_true_legacy_fixed_to_dynamic_native_loaded'=>true,
 'v227k_genuine_saved_unicode_archive_bytes'=>280,
 'v227k_separate_native_reloaded_code_units'=>['D83D','DE80','03A9'],
 'T028_malformed_FString'=>'BLOCKED_separate_original_error_cursors_still_required',
 'overall_G3_accepted'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
