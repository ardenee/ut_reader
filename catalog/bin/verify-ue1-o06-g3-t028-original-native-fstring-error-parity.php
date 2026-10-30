#!/usr/bin/env php
<?php
/** Frozen G3 T028: original shipped UT99 v432 fatal EOF versus C227k recoverable malformed FString. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);$ev=$root.'/catalog/compliance/evidence/o06/';
$n=0;$failed=[];
$ck=static function(bool $ok,string $msg)use(&$n,&$failed):void{++$n;if(!$ok)$failed[]=$msg;};
$hashes=[
 '20261030-G3-B04-T028-V432-original-EOF.u'=>'6ddf59b77ba21e9ede85ac84505fd6c281b6b0225454a5b51f3a9a622c6b0277',
 '20261030-G3-B04-T028-V432-original-EOF.log'=>'8bdd114a9bdc48d7a780ff31863dbae25b7826288cdc8cb7ffc742b8fd5589a1',
 '20261030-G3-B04-T028-C227k-original-BadNul.u'=>'9f93c7c3e94d1f09b95a27136773ba12bb611c45b5bc6d4aa3220704581e3993',
 '20261030-G3-B04-T028-C227k-original-BadCount.u'=>'c30b443cd26315ed6be00750c846e9852f5b41b219d43c2dea59a031b3341c81',
 '20261030-G3-B04-T028-C227k-original-BadNul.log'=>'53d92ec894ca8fc8b6aea5bebd6bbd55678566768c44bfd8b7c137dae3dc4c3b',
 '20261030-G3-B04-T028-C227k-original-BadCount.log'=>'c539e7dff35c8319731db9694202b1589e8fa53852d30e9b5dc2643686d01144',
 '20261030-G3-B04-T028-C227k-original-native-loader.cpp'=>'23c92f7051e7e7d77e13644550f3d6381b796d219d0314903f2be12aa36bc493',
 '20261030-G3-B04-T028-current-original-batch-check.php'=>'8cdc2ded3a68baaaeec57686839cfe0ebe419e8dc7efcca0283a4d019504d69f',
 '20261030-G3-BULK-SCALAR-C227k-original-SAVE.u'=>'3e01d5304c46563ce5d4f06c70cdc34ea462949ab64a5da1057f60007b6a8fa2',
 '20261030-G3-BULK-SCALAR-V432-original-FSTRING.u'=>'e494caf1c9eb86b5fb2fcf4f7af1e0e91c5b5b214066c670ef4917dc7a2cd933'
];
foreach($hashes as $name=>$sha)$ck(is_file($ev.$name)&&hash_file('sha256',$ev.$name)===$sha,'exact original-native SHA '.$name);
$ck(hash_file('sha256',$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php')==='c9480a8614354eb0d38034c41b3ce3bb4d9dbf0d3762019f26dddc87ed8ecc61','current actual UE1 tagged reader source pinned');
$root2='C:/Temp/UnrealDB/O06/';
$stock=$root2.'Native-UCC/System/G5DirectStringPayload.u';
$bad=$root2.'Native-UCC/System/G5DirectStringPayloadBad.u';
$cbase=$root2.'Native-227k/System/G3Scalar227Runtime.u';
$cnull=$root2.'Native-227k/System/G3Scalar227RuntimeBadNul.u';
$ccount=$root2.'Native-227k/System/G3Scalar227RuntimeBadCount.u';
$ck(is_file($stock)&&is_file($bad)&&is_file($cbase)&&is_file($cnull)&&is_file($ccount),'all original genuine stock saved packages and three original derived files present');
foreach([
 $bad=>'20261030-G3-B04-T028-V432-original-EOF.u',
 $cnull=>'20261030-G3-B04-T028-C227k-original-BadNul.u',
 $ccount=>'20261030-G3-B04-T028-C227k-original-BadCount.u'
] as $file=>$sealed)$ck(hash_file('sha256',$file)===hash_file('sha256',$ev.$sealed),'original runtime physical .u byte-for-byte pinned '.$sealed);
$diff=static function(string $a,string $b):array{
 $a=file_get_contents($a);$b=file_get_contents($b);
 if(!is_string($a)||!is_string($b)||strlen($a)!==strlen($b))return [-1];
 $out=[];for($i=0;$i<strlen($a);$i++)if($a[$i]!==$b[$i])$out[]=$i;
 return $out;
};
$ck($diff($stock,$bad)===[232],'real original compiled v432 package corrupted only FString character count byte at physical offset232, no other bytes altered');
$ck($diff($cbase,$cnull)===[223],'original 227k saved runtime package corrupted only terminal NUL byte at physical offset223');
$ck($diff($cbase,$ccount)===[221],'original 227k saved runtime package corrupted only positive count at physical offset221');
$ck(ord(file_get_contents($bad)[232])===0x4b
 &&ord(file_get_contents($stock)[232])===0x15,
 'v432 original source positive FString count21 mutated to count75 exceeding physical bytes');
$ck(substr((string)file_get_contents($cbase),221,3)==="\x02B\x00"
 &&substr((string)file_get_contents($cnull),221,3)==="\x02BX"
 &&substr((string)file_get_contents($ccount),221,3)==="\x3FB\x00",
 '227k valid string two characters including NUL, actual one byte malformed terminator and oversized count');
$logs=static function(string $p)use($ev):string{
 $b=(string)file_get_contents($ev.$p);return str_starts_with($b,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($b,2)):$b;
};
$ut=$logs('20261030-G3-B04-T028-V432-original-EOF.log');
$nul=$logs('20261030-G3-B04-T028-C227k-original-BadNul.log');
$big=$logs('20261030-G3-B04-T028-C227k-original-BadCount.log');
$ck(str_contains($ut,'ReadFile beyond EOF 283+1/283'),'ORIGINAL stock UT99 v432 actual native FArchive read failure occurs at physical Tell283 attempting 284');
$ck(str_contains($ut,'History: ULinkerLoad::Serialize <- FString<<')
 &&str_contains($ut,'FPropertyTag::SerializeTaggedProperty'),
 'original compiled v432 actual error method chain is FString<< via tagged UStrProperty not a made-up generic error');
$ck(str_contains($ut,'native_error=1')&&str_contains($ut,'ptr=00000000'),
 'original compiled v432 genuine native loader rejects corrupt archive and produces no successfully loaded package');
$ck(str_contains($nul,'G3_B04_T028_ORIGINAL_227K_BEGIN file=G3Scalar227RuntimeBadNul.u')
 &&str_contains($nul,'bad=0')&&str_contains($nul,'G3_SCALAR_227_RELOADED_COUNT 1'),
 'genuine original 2026 227k native linker accepts nonterminated declared FString and fully loads object, no invented fatal error');
$ck(str_contains($nul,'G3_SCALAR_227_NATIVE_TAG EmptyText="B"'),
 'genuine original native nonterminated malformed string loaded text B, not incorrectly attributed a full BX buffer');
$ck(str_contains($big,'G3_B04_T028_ORIGINAL_227K_BEGIN file=G3Scalar227RuntimeBadCount.u')
 &&str_contains($big,'bad=0')&&str_contains($big,'G3_SCALAR_227_RELOADED_COUNT 1'),
 'genuine original C227k oversized declared tag count is source-specific recoverable, no invented fatal exception');
$ck(str_contains($big,'Loading property EmptyText for G3Scalar227Actor size mismatch (got 4 expected 3 bytes)'),
 'original 227k native read reported actual source-specific property-size warning 4 vs3, distinct from fatal physical 283 EOF');
$ck(str_contains($big,'G3_SCALAR_227_NATIVE_TAG EmptyText="B"'),
 'original compiled 227k actually resumed loaded state after count warning, no assertion that native rejected object');
$ps=new P($bad);$ex=$ps->getExports()[0];
$ck($ps->getHeader()['version']===69&&filesize($bad)===283
 &&$ex['serialOffset']===229&&$ex['serialSize']===26,
 'actual original v432 malformed source 283-byte archive and 26-byte original FPropertyTag object export boundaries');
$error=null;
try{T::inspect($bad,$ps,0,'ut99-v432');}catch(Throwable $e){$error=$e->getMessage();}
$ck(is_string($error),'source-selected current v432 reader rejects genuine native fatal bad count at physical archive instead of inventing load success');
$ck(str_contains((string)$error,'UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset 283 during direct FString<<'),
 'current source-selected reader fails precisely at original stock engine physical FArchive Tell=283 and exact method');
foreach([['BadNul',$cnull],['BadCount',$ccount]] as [$label,$file]){
 $p=new P($file);$r=T::inspect($file,$p,0,'unreal-v227k');
 $ck($p->getHeader()['version']===69&&$p->getHeader()['licensee']===227,
 'original 2026 C227k damaged file still source-selected v69/licensee227 '.$label);
 $ck(($r['status']??null)==='tag_envelope_decoded'&&$r['tag_end_offset']===225&&$r['native_tail_bytes']===0,
 'current reader does NOT invent native fatal error and physically consumes original tagged archive to precise end225 '.$label);
 $values=[];foreach($r['tags'] as $tag)$values[$tag['name']]=$tag;
 $ck(count($values)===2&&($values['EmptyText']['value_kind']??null)==='opaque'
  &&array_key_exists('value',$values['EmptyText'])&&$values['EmptyText']['value']===null
  &&($values['EmptyText']['native_load_policy']??null)==='native_source_property_SerializeItem',
  'malformed original 227k loaded string cannot be claimed bitwise or as safe human Unicode; original raw bytes preserved, no invented successful conversion '.$label);
 $ck(($values['bBeta']['bool_value']??null)===true,'original source selection preserves unrelated valid native packed boolean tag '.$label);
}
$ck(str_contains((string)file_get_contents($root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php'),
 'UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset'),
 'current production code has source-defined native v432 physical FArchive EOF branch');
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'checks'=>$n,'failures'=>$failed,
 'original_fixed_T028'=>'PASS',
 'UT99_v432_original_native_fatal_Tell'=>283,
 'current_v432_native_error_Tell'=>283,
 '227k_malformed_NUL'=>'native_LOAD_success_reader_no_error',
 '227k_oversized_declared_count'=>'native_LOAD_warning_and_success_reader_no_fatal',
 'accepted_original_tests_reexecuted'=>0,
 'T029_T030_T031'=>'BLOCKED_invalid_any_real_linker_oracle_not_substituted',
 'overall_G3_accepted'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
