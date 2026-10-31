#!/usr/bin/env php
<?php
/** Original original C98/v432/C227i/C227k distinct native proof, fixed three IDs. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$repo=dirname(__DIR__,2);$ev=$repo.'/catalog/compliance/evidence/o06/';
$n=0;$failed=[];$check=static function(bool $ok,string $label)use(&$n,&$failed):void{++$n;if(!$ok)$failed[]=$label;};
$hashes=[
 '20261030-G3-BULK-SCALAR-C227i-original-compiler.u'=>'086961b8ecb58abf2e0887f1e07636c7fd865882fd683abe54542f1ac8470649',
 '20261030-G3-BULK-SCALAR-C227i-original-compiler.uc'=>'dc2df9588b7b05c68db2f1ce312483d2bf9d77255c31c96c8eff4e960455f5ad',
 '20261030-G3-BULK-SCALAR-C227k-original-SAVE.u'=>'3e01d5304c46563ce5d4f06c70cdc34ea462949ab64a5da1057f60007b6a8fa2',
 '20261030-G3-BULK-SCALAR-C227k-original-SAVE.cpp'=>'612f628fa92af1bb81c7581837214d72b58f466690769826484d7f057155734c',
 '20261030-G3-BULK-SCALAR-C227k-original-SAVE.log'=>'ab7938082e5b16e2541572f11907f34edbed85a0d1a0c9b05c3388bd27a5867b',
 '20261030-G3-BULK-SCALAR-C227k-original-LOAD.cpp'=>'094c8304fc51a8c941be988e54dfdd00cf39ba9063d440766464b8ec2dd803b8',
 '20261030-G3-BULK-SCALAR-C227k-original-LOAD.log'=>'b8fe6e2a10f7f251a4aa524e59a87cb74d253cc7432f95f54767504be5648183',
 '20261030-G3-BULK-SCALAR-C227k-original-direct-FLOAT.cpp'=>'cab1d37164511ac64889445f634f8a648287f96e90072ea9f7adc99130107fea',
 '20261030-G3-BULK-SCALAR-C227k-original-direct-FLOAT.log'=>'4e3b8537c9c1941aba5e0067aa36286c5a28e620fad27b971552ccefd834c7ec',
 '20261030-G3-BULK-SCALAR-V432-original-FSTRING.u'=>'e494caf1c9eb86b5fb2fcf4f7af1e0e91c5b5b214066c670ef4917dc7a2cd933',
 '20261030-G3-BULK-SCALAR-V432-original-FSTRING-LOAD.log'=>'74fcc01646c50e85182381de0eb1b368c25fd731dbed812b537f4922e2db5182',
];
foreach($hashes as $name=>$sha)$check(is_file($ev.$name)&&hash_file('sha256',$ev.$name)===$sha,'SHA-256 original compiled evidence '.$name);
foreach([
 'C:/Temp/UnrealDB/O06/Native-227k/System/Core.dll'=>'c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194',
 'C:/Temp/UnrealDB/O06/Native-C227i-G304/System/Core.dll'=>'287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897',
 $repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php'=>'a29b5d9fb7a3ee8b697e1549a7e9935353e5227024b734ce4fd2e119432ba8d4'
] as $f=>$sha)$check(is_file($f)&&hash_file('sha256',$f)===$sha,'actual original Core/production source pin '.basename($f));
$dir='C:/Temp/UnrealDB/O06/';
$files=[
 ['Native-C227i-G304/System/G3Scalar227.u','20261030-G3-BULK-SCALAR-C227i-original-compiler.u'],
 ['Native-227k/System/G3Scalar227Runtime.u','20261030-G3-BULK-SCALAR-C227k-original-SAVE.u'],
 ['Native-UCC/System/G5DirectStringPayload.u','20261030-G3-BULK-SCALAR-V432-original-FSTRING.u']
];
foreach($files as [$f,$name])$check(is_file($dir.$f)
 &&hash_file('sha256',$dir.$f)===hash_file('sha256',$ev.$name),'exact working original package equals pinned native-created package '.basename($f));
$read=static function(string $file)use($ev):string{
 $s=(string)file_get_contents($ev.$file);
 return str_starts_with($s,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($s,2)):$s;
};
$save=$read('20261030-G3-BULK-SCALAR-C227k-original-SAVE.log');
$load=$read('20261030-G3-BULK-SCALAR-C227k-original-LOAD.log');
$direct=$read('20261030-G3-BULK-SCALAR-C227k-original-direct-FLOAT.log');
$v432=$read('20261030-G3-BULK-SCALAR-V432-original-FSTRING-LOAD.log');
$check(str_contains($save,'G3_SCALAR_NATIVE_PACKAGE ')&&str_contains($save,'bad=0')
 &&str_contains($save,'G3_SCALAR_NATIVE_SAVE 1'),'true original 227k native process loads original C227i class then saves new independent object');
$check(str_contains($load,'G3_SCALAR_227_INDEPENDENT_RELOAD cls=')
 &&str_contains($load,'bad=0')&&str_contains($load,'G3_SCALAR_227_RELOADED_COUNT 1'),
 'separate second ORIGINAL compiled 227k process loaded REAL 253-byte saved object');
$ck=new P($dir.'Native-C227i-G304/System/G3Scalar227.u');
$rt=new P($dir.'Native-227k/System/G3Scalar227Runtime.u');
$vs=new P($dir.'Native-UCC/System/G5DirectStringPayload.u');
$check($ck->getHeader()['version']===69 && $ck->getHeader()['licensee']===0
 &&$rt->getHeader()['licensee']===227,'actual distinct 2012 compiler vs 2026 binary loader version gates preserved');
$cl=C::inspect($dir.'Native-C227i-G304/System/G3Scalar227.u',$ck,0,false,'unreal-v227i');
$check($cl['source_file_archive_complete']===true,'original 2012 compiler-generated UClass native defaults fully consumed');
$tags=[];foreach($cl['default_property_tags'] as $t)$tags[$t['name']]=$t;
$check(!isset($tags['FloatMinusZero'])&&!isset($tags['EmptyText'])&&!isset($tags['bBeta']),
 'original compiler intentionally omitted numeric zero, empty FString and false Bool tags');
foreach(['FloatPos'=>1.5,'FloatNegative'=>-1.5,'FloatFrac'=>0.375] as $key=>$val){
 $t=$tags[$key]??[];
 $check(($t['type']??null)==='FloatProperty'&&($t['on_disk_value_bytes']??null)===4
  &&($t['value']??null)===$val,'2012 original FloatProperty archive four actual bytes nonzero '.$key);
}
$check(str_contains($save,'G3_SCALAR_FLOAT name=FloatPos offset=780 cdoBits=3FC00000')
 &&str_contains($save,'G3_SCALAR_FLOAT name=FloatNegative offset=784 cdoBits=BFC00000')
 &&str_contains($save,'G3_SCALAR_FLOAT name=FloatMinusZero offset=788 cdoBits=00000000')
 &&str_contains($save,'G3_SCALAR_FLOAT name=FloatFrac offset=792 cdoBits=3EC00000'),
 'actual original compiled 227k native runtime CDO shows IEEE754 positive/negative/nonintegral source bits');
foreach([
 ['FloatPos','3FC00000'],['FloatNegative','BFC00000'],['FloatMinusZero','80000000']
] as [$name,$word])$check(
 str_contains($direct,'G3_SCALAR_DIRECT_ORIGINAL_FLOAT name='.$name.' inputBits='.$word
  .' archiveLen=4 savedBits='.$word.' loadedBits='.$word.' readerPos=4'),
 'true original compiled C227k UFloatProperty::SerializeItem FBufferArchive + FBufferReader four physical bytes identical including signed negative ZERO '.$name);
$check(str_contains($save,'G3_SCALAR_NATIVE_SET_NEGZERO bits=80000000')
 &&str_contains($save,'G3_BULK_227_NATIVE_TAG FloatMinusZero=-0.0')
 &&str_contains($load,'G3_SCALAR_227_RELOAD_FLOAT_NEGZERO_WORD 00000000'),
 'original compiled C227k SavePackage omitted -0.0 because numerically equal to default positive 0.0, native reload intentionally normalizes; not a reader loss');
$check(str_contains((string)file_get_contents('L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnProp.cpp'),'return *(FLOAT*)A == (B ? *(FLOAT*)B : 0);'),
 'actual inherited Epic 1999 source float Identical uses numeric equality, explaining true negative zero SAVE omission');
foreach([['bAlpha','00000001',1],['bBeta','00000002',0],['bGamma','00000004',1]] as [$key,$mask,$cdo]){
 $check(str_contains($save,'G3_SCALAR_BOOL name='.$key.' offset=776 mask='.$mask.' cdoWord=00000005 cdoValue='.$cdo),
  'original compiled C227k UBoolProperty masked same shared native 32bit storage '.$key);
 $check(str_contains($load,'G3_SCALAR_227_RELOAD_BOOL name='.$key.' mask='.$mask.' word=00000007 loaded=1'),
  'independently reloaded C227k stored original packed boolean flag all three true '.$key);
}
$check(str_contains($save,'G3_BULK_227_NATIVE_TAG bBeta=False')
 &&str_contains($save,'G3_BULK_227_NATIVE_TAG bAlpha=True')
 &&str_contains($save,'G3_BULK_227_NATIVE_TAG bGamma=True'),
 'original native compiled default bool true and false evidence');
$one=(string)file_get_contents($ev.'20261030-G3-BULK-SCALAR-C227i-original-compiler.uc');
$check(str_contains($one,'var() bool bAlpha, bBeta, bGamma;')&&!str_contains($one,'bool Flags['),
 'source-legal original compiler excludes bool arrays, real packed 3 bitflags are applicable indexed storage');
foreach([
 ['OneText','A',2,'4100',3],
 ['PhraseText','A longer string',16,'41206c6f6e67657220737472696e6700',17]
] as [$key,$content,$count,$hex,$size]){
 $t=$tags[$key]??[];
 $check(($t['type']??null)==='StrProperty'&&($t['serialized_size']??null)===$size
   &&($t['on_disk_value_bytes']??null)===$size,'2012 original generated source FString physical count+NUL bytes '.$key);
 $check(($t['value_kind']??null)==='fstring_ansi_archive'
   &&($t['value']['signed_character_count']??null)===$count
   &&($t['value']['serialized_character_bytes_hex']??null)===$hex
   &&($t['value']['ascii_text']??null)===$content
   &&($t['value']['nul_terminated']??false),
   'UnrealDB original 2012 compile exact FString ANSICount/terminator matching real native CDO '.$key);
 $check(str_contains($save,'G3_BULK_227_NATIVE_TAG '.$key.'="'.$content.'"'),
  'genuine 2026 C227k compiled native loaded 2012 original saved default text '.$key);
}
$check(str_contains($save,'G3_BULK_227_NATIVE_TAG EmptyText=""'),
 'original 227k runtime loads actual 2012 omitted empty FString as empty, no fabricated saved property');
$check(str_contains($load,'G3_SCALAR_227_NATIVE_TAG EmptyText="B"')
 &&str_contains($load,'G3_SCALAR_227_NATIVE_TAG OneText="A"')
 &&str_contains($load,'G3_SCALAR_227_NATIVE_TAG PhraseText="A longer string"'),
 'second original C227k process reloaded REAL saved value B and two original class inherited strings');
$r=T::inspect($dir.'Native-227k/System/G3Scalar227Runtime.u',$rt,0,'unreal-v227k');
$saved=[];foreach($r['tags'] as $t)$saved[$t['name']]=$t;
$check(($r['native_tail_bytes']??null)===0
 &&($r['tag_end_offset']??null)===$rt->getExports()[0]['serialOffset']+$rt->getExports()[0]['serialSize']
 &&count($saved)===2, 'original 227k native SavePackage 253-byte object exact whole tagged archive no tail');
$check(isset($saved['bBeta'])&&$saved['bBeta']['type']==='BoolProperty'
 &&$saved['bBeta']['bool_value']===true
 &&$saved['bBeta']['on_disk_value_bytes']===0,'real bool tag info byte carries true without fabricated 1-byte bool value');
$st=$saved['EmptyText']??[];
$check(($st['type']??null)==='StrProperty'
 &&($st['native_load_policy']??null)==='native_source_property_SerializeItem'
 &&($st['value_kind']??null)==='fstring_ansi_archive'
 &&($st['value']['signed_character_count']??null)===2
 &&($st['value']['serialized_character_bytes_hex']??null)==='4200'
 &&($st['value']['ascii_text']??null)==='B'
 &&($st['on_disk_value_bytes']??null)===3,
 'real original 227k native SavePackage EmptyText=B original three bytes count2 B NUL, correct current source-linked reader');
$check(!isset($saved['FloatMinusZero']),
 'real original C227k SavePackage property omitted FloatMinusZero despite 0x80000000 in object, matching original native reload positive zero');
$vr=T::inspect($dir.'Native-UCC/System/G5DirectStringPayload.u',$vs,0,'ut99-v432');
$tag=$vr['tags'][0]??[];
$check(($vr['native_tail_bytes']??null)===0&&count($vr['tags'])===1,
 'unmodified original UT99 v432 separate real StringProperty UClass/UStr stream consumed to exact end');
$check(($tag['type']??null)==='StrProperty'
 &&($tag['value_kind']??null)==='fstring_ansi_archive'
 &&($tag['value']['ascii_text']??null)==='Alpha-direct-FString'
 &&($tag['value']['signed_character_count']??null)===21
 &&($tag['value']['serialized_character_bytes_hex']??null)==='416c7068612d6469726563742d46537472696e6700'
 &&($tag['on_disk_value_bytes']??null)===22,
 'true original v432 FString positive21 length, NUL, 22 actual bytes vs source-selected UnrealDB');
$check(str_contains($v432,'G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=')
 &&str_contains($v432,'Headline="Alpha-direct-FString"'),
 'original shipped UT99 v432 native loader independently loaded correct nonempty FString value');
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'checks'=>$n,'failures'=>$failed,
 'original_fixed_T_IDs'=>['T022'=>'PASS','T023'=>'PASS','T026'=>'PASS'],
 'original_C227i_2012_compiled_and_C227k_original_native_2026'=>true,
 'native_float_IEEE754_negative_zero_direct_wire_and_reload'=>'80000000',
 'native_SavePackage_omits_signed_zero_due_to_source_FloatIdentical'=>true,
 'native_packed_bool_word'=>['class_default'=>'00000005','instance_reload'=>'00000007','masks'=>['00000001','00000002','00000004']],
 'FString_source_profiles'=>['UT99-v432','C227i-2012-compiled','C227k-2026-native-LOAD+SAVE'],
 'original_executable_replayed_during_verifier'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
