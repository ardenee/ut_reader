#!/usr/bin/env php
<?php
/** Frozen T048: original v432 negative/zero and original C227k oversized/truncated array failure cursor. T068 partial only. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);$ev=$root.'/catalog/compliance/evidence/o06/';
$readLog=static function(string $file):string{
 $b=(string)file_get_contents($file);
 if(str_starts_with($b,"\xFF\xFE"))return (string)iconv('UTF-16LE','UTF-8',substr($b,2));
 return $b;
};

$n=0;$fail=[];$ck=static function(bool $ok,string $why)use(&$n,&$fail):void{++$n;if(!$ok)$fail[]=$why;};
$hashes=[
 '20261030-G3-COUNT-C227k-original-CountThree.u'=>'6c993b943a76a2729cf525abe58f79f5eaf685d31e764012d965485dc461c94c',
 '20261030-G3-COUNT-C227k-original-CountHuge.u'=>'14cb3bc6f680ed736d84a1ead059b45b6bfccbf3cc45ddcb96c2880a4a47e068',
 '20261030-G3-COUNT-C227k-original-CountThree.log'=>'247281e85e55776564dbd37b7c6d12ef6479407978af868b7b18951f57dc6018',
 '20261030-G3-COUNT-C227k-original-CountHuge.log'=>'16e4a52a9e99fe7b5d6f5d125ebffc654001fa82ee9b439dce1684359be33cf3',
 '20261030-G3-COUNT-original-current-compare.php'=>'2049c80a294f21e7bcd3d016e20f2ab16ac020917dedeb36e9d03cedd5a5548a',
 '20261030-G3-COUNT-v432-original-Count31-EOF.log'=>'5b22df2a609d87803a857c047397a4f9bf6885fddf87cac449c0b63a3889c869',
 '20261030-G3-COUNT-v432-original-Count31-manifest.json'=>'587798b3318e0455b713f5b1ba2c0057be667426b71b97a870647d75c0b176c4',
 '20261030-G3-COUNT-C227k-original-SignedUnicode.log'=>'4fcda84026672f2adcefe6d3b9861c9d91c42499da414241c9352aa8d77f90a2',
 '20261030-G3-B06-AGG-C227k-original-SAVE.u'=>'34a00b4f5bdf78dd56ef62a79f62684b6159fb0ecfc43e630eaa08c7ee09d8f7',
 '20261030-G3-B06-AGG-C227k-arrayZero.u'=>'7e93a957b6aad48775150da8b46e7d9471f52099a62952e63bbc9531a437c333',
 '20261030-G3-B06-AGG-C227k-arrayZero-native.log'=>'e3b5858aa37af3fac5b53571abd36fae819a66cfe50400cc272606e73f7e250f',
 '20261030-G3-B06-AGG-C227k-arrayNegOne.u'=>'23f29615f4086c7b70ca6df96b9b3a4e437f846bfff85d1843b5032b652719ee',
 '20261030-G3-B06-AGG-C227k-arrayNegOne-native.log'=>'ba8025fd13d955ebd86d84a5859deee6f5f211e3d449bfb3391bd7ee347bf23d',
 '20261029-G3-T048-T068-original-v432-native-count38pass.json'=>''
];
foreach($hashes as $name=>$expected){
 $ok=is_file($ev.$name)&&($expected===''||hash_file('sha256',$ev.$name)===$expected);
 $ck($ok,'original Core.dll binary/positive negative evidence SHA '.$name);
}
$nativeV432=json_decode((string)file_get_contents($ev.'20261029-G3-T048-T068-original-v432-native-count38pass.json'),true);
$ck(($nativeV432['ok']??false)===true&&($nativeV432['checks']??null)===38&&count($nativeV432['failures']??[])===0,
 'existing original compiled stock UT99 v432 native negative and zero array count original source/current 38/38');
$cases=$nativeV432['cases']??[];
$ck(count($cases)===2&&$cases[0]['archive_only_count']===0
 &&$cases[1]['archive_only_count']===-1,
 'actual UT99 v432 native altered source legal dynamic count three to zero and negative one');
foreach($cases as $case){
 $ck($case['native_true_physical_Tell']===259
 &&$case['reader_on_disk_tag_cursor']===259
 &&$case['original_native_rejected_object']===true
 &&$case['reader_claims_native_loaded_value']===false,
 'original v432 source-defined size mismatch while invalid signed array count, cursor259, no fabricated native loaded array '.$case['case']);
}
$orig=(string)file_get_contents($ev.'20261030-G3-B06-AGG-C227k-original-SAVE.u');
$ck(strlen($orig)===308&&ord($orig[234])===2,'original unchanged 2026 C227k Core.dll SavePackage exactly 308 bytes, count2 at native offset234');
foreach([['CountThree',3],['CountHuge',63]] as [$case,$count]){
 $name='20261030-G3-COUNT-C227k-original-'.$case.'.u';
 $mutant=(string)file_get_contents($ev.$name);
 $diff=[];for($i=0;$i<strlen($orig);$i++)if($orig[$i]!==$mutant[$i])$diff[]=$i;
 $ck(strlen($mutant)===308&&$diff===[234]&&ord($mutant[234])===$count,
 'original genuine C227k saved .u only AR_INDEX array count2->'.$count.' mutated at physical234');
 $live='C:/Temp/UnrealDB/O06/Native-227k/System/G3Array227Runtime'.$case.'.u';
 $ck(hash_file('sha256',$live)===$hashes[$name],
 'active original C227k linker source package bytes match sealed one-byte mutation '.$case);
 $log=(string)file_get_contents($ev.'20261030-G3-COUNT-C227k-original-'.$case.'.log');
 if(str_starts_with($log,"\xFF\xFE"))$log=(string)iconv('UTF-16LE','UTF-8',substr($log,2));
 $ck(str_contains($log,'G3_B06_NEGATIVE_ORIGINAL_BEGIN package=G3Array227Runtime'.$case.'.u')
   &&str_contains($log,'Bad name index 6248/12'),'genuine compiled native original loader selected correctly mutated package, rejected illegal nested index 6248/12 '.$case);
 $ck(str_contains($log,'ULinkerLoad<<FName')&&str_contains($log,'UStruct::SerializeBin')
   &&str_contains($log,'263))')&&str_contains($log,'263==263/308'),
 'real original compiled Core.dll native method, nested type and exact FArchive physical Tell263 '.$case);
 $ck(str_contains($log,'runtime=00000000 bad=1'),
 'original 2026 compiled Core.dll did not return any loaded actor for truncated/oversized source count '.$case);
 $readerPath='C:/Temp/UnrealDB/O06/Native-227k/System/G3Array227Runtime'.$case.'.u';
 $error=null;try{T::inspect($readerPath,new P($readerPath),0,'unreal-v227k');}catch(Throwable $e){$error=$e->getMessage();}
 $ck($error==='UE1 v227 ULinkerLoad<<FName invalid name index 6248 at archive offset 263',
 'production source-selected original v227 array serialized count through original struct nested FName EXACT Tell263 and name6248 '.$case);
}
$zero=(string)file_get_contents($ev.'20261030-G3-B06-AGG-C227k-arrayZero.u');
$negative=(string)file_get_contents($ev.'20261030-G3-B06-AGG-C227k-arrayNegOne.u');
$ck(strlen($zero)===308&&ord($zero[234])===0&&strlen($negative)===308&&ord($negative[234])===0x81,
 'original C227k native zero and -1 separate one-byte negative probes remain source pinned, not silently dropped');
$zeroNative=$readLog($ev.'20261030-G3-B06-AGG-C227k-arrayZero-native.log');
$ck(str_contains($zeroNative,'size mismatch (got 1 expected 19 bytes)')
 &&str_contains($zeroNative,'G3_B06_NATIVE_RELOADED_ARRAY name=Pairs count=0')
 &&str_contains($zeroNative,'G3_B06_227_RELOADED_COUNT 1'),
 'original native v227 count0 mismatch warning and recovered actual empty array rather than false fatal error');
$nz='C:/Temp/UnrealDB/O06/Native-227k/System/G3Array227RuntimeZero.u';
$r=T::inspect($nz,new P($nz),0,'unreal-v227k');$first=$r['tags'][0];
$ck(($first['value']['element_count']??null)===0
 &&($first['value']['native_227k_tag_size_recovery']['recovered_archive_byte_count']??null)===18
 &&($r['tag_end_offset']??null)===280,
 'current original-v227 source-specific count0 native tag-size correction skips18, exact archive Tell280');
$negativeNative=$readLog($ev.'20261030-G3-B06-AGG-C227k-arrayNegOne-native.log');
$ck(str_contains($negativeNative,'<?int?Core.Errors.SerialSize?>')&&!str_contains($negativeNative,'G3_B06_227_RELOADED_COUNT 1'),
 'original v227 negative allocation C++ native access violation recognized as undefined; no physical cursor or success invented');
$huge432=(string)file_get_contents($ev.'20261030-G3-COUNT-v432-original-Count31-EOF.log');
$ck(str_contains($huge432,'ReadFile beyond EOF 308+3/308')
 &&str_contains($huge432,'FPropertyTag::SerializeTaggedProperty')
 &&str_contains($huge432,'native_error=1'),
 'separately original v432 compiled nonnegative array count31 genuine physical EOF308, fatal no loaded object');
$manifest=json_decode((string)file_get_contents($ev.'20261030-G3-COUNT-v432-original-Count31-manifest.json'),true);
$ck(($manifest['one_byte_count_mutation']['offset']??null)===254
 &&($manifest['one_byte_count_mutation']['original']??null)===3
 &&($manifest['one_byte_count_mutation']['new']??null)===31
 &&($manifest['pristine_restored']??null)===true,
 'v432 original count31 scoped one-byte mutation from real original compiled 3-element package, original restored');
$unicode=$readLog($ev.'20261030-G3-COUNT-C227k-original-SignedUnicode.log');
$ck(str_contains($unicode,'label=nonBMP_surrogate_pair')
 &&str_contains($unicode,'archivedHex=833DD880DE0000')
 &&str_contains($unicode,'readerPos=7'),
 'T068 partial source-applicable genuine v227 signed compact negative3 UTF16 FString is LEGAL and source loaded; not invented invalid name/string exception');
$name=json_decode((string)file_get_contents($ev.'20261028-G3-batch-T069-v432-227k-original-name-18pass-PARTIAL.json'),true);
$ck(($name['ok']??false)===true&&($name['checks']??null)===18&&count($name['details']??[])===2,
 'T068 partial two source-specific real original negative FName compact index -63 error/cursor proofs intact, does not imply C98 name oracle');
$ok=$fail===[];
echo json_encode([
 'ok'=>$ok,'checks'=>$n,'failures'=>$fail,
 'full_frozen_g3_ids'=>['T048'=>'PASS'],
 'T048'=>'original v432 count0/-1 true size mismatch archive Tell259, count31 fatal physical EOF308; v227k real count0 tolerated + restored final tag cursor280, original oversized/extra count native rejects exact index6248 at Tell263, negative C++ allocation UB explicitly excluded',
 'T068'=>'BLOCKED_PARTIAL_only_v432_227k_array_name_string_proofs_C98_and_2012_source_proven_bounds_missing',
 'previously_accepted_original_native_probes_rerun'=>0,
 'G3_overall_complete'=>false
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
