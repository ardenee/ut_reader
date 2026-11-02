#!/usr/bin/env php
<?php
/** T047 original compiled actual v432, original 2012 C227i and original 2026 C227k map family differential. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicStructArrayValueReader as S;
$root=dirname(__DIR__,2);$ev=$root.'/catalog/compliance/evidence/o06/';
$n=0;$fail=[];
$ck=static function(bool $pass,string $reason)use(&$n,&$fail):void{
 ++$n;if(!$pass)$fail[]=$reason;
};
$hashes=[
 '20261030-G3-MAP-original-C227i-native-2012-disasm.cmd'=>'6bfbd6fa162159d0285598d06c21e5f04bcff46fdbe297f7521478380b42aaa6',
 '20261030-G3-MAP-original-C227i-native-2012-disasm.log'=>'bd6a8d4e656cf9bd716bd08fc7b307ba5fbb5ae754c287b4b1af22397bb9d1ea',
 '20261030-G3-MAP-original-C227i-2012-export-RVA.txt'=>'d1c955889a39b41f9a952a78bf966cf6f50083605dc2f5cb3ecb749af1e2eed7',
 '20261030-G3-MAP-current-original-three-release.php'=>'5bb9635adefb54c76d0bcc18db366620f75b90feb1abf9ecb5010db6b1191831',
 '20261030-G3-B06-FIXED-V432-original-T047-partial-MAP.cpp'=>'ecb272520831d822dfcb68a53381b9c90e583d131a97b62d66b2a01176c67af3',
 '20261030-G3-B06-FIXED-V432-original-T047-partial-MAP.log'=>'e137d776744395d9581dbe317bc3540deeea600223d9076f5427f4b3b01f40cf',
 '20261030-G3-B06-FIXED-C227k-original-T047-partial-MAP.cpp'=>'a432235ca7d988c637c4600067c821a32855b1b3dfedd94d4acc5f3e63d14dbd',
 '20261030-G3-B06-FIXED-C227k-original-T047-partial-MAP.log'=>'82911deb40aef6797ff76901e40530f0d174944f89d94aebed6f392e1f585051',
];
foreach($hashes as $name=>$sha){
 $ck(is_file($ev.$name)&&hash_file('sha256',$ev.$name)===$sha,'sealed native original source/log/disassembly exact sha '.$name);
}
$ck(hash_file('sha256',$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicStructArrayValueReader.php')
    ==='a55457d788c91a9b9021c5489b9b94d6b913a8049fe54c4abf02ec453bad58d9',
 'current map decoder integrated into actual StructArrayValueReader pinned original source');
foreach([
 'C:/Temp/UnrealDB/O06/Native-C227i-G304/System/Core.dll'
    =>'287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897',
 'C:/Temp/UnrealDB/O06/Native-227k/System/Core.dll'
    =>'c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194',
 'C:/Temp/UnrealDB/O06/Native-UCC/System/Core.dll'
    =>'237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8'
] as $file=>$sha)$ck(is_file($file)&&hash_file('sha256',$file)===$sha,
 'actual unmodified compiled original UE1 Core.dll three source families '.$file);
$real2012=(string)file_get_contents($ev.'20261030-G3-MAP-original-C227i-2012-export-RVA.txt');
$ck(str_contains($real2012,'00001000 ?SerializeItem@UMapProperty@@')
 &&str_contains($real2012,'00049FE0 ?NetSerializeItem@UMapProperty@@'),
 'original 2012 PE export table UMapProperty::SerializeItem maps precisely to RVA0x1000, NetSerializeItem mapped RVA0x49fe0');
$cmd=(string)file_get_contents($ev.'20261030-G3-MAP-original-C227i-native-2012-disasm.cmd');
$ck(str_contains($cmd,'u Core+0x1000 L7')&&str_contains($cmd,'u Core+0x49fe0 L16'),
 'genuine original C227i debugger disassembles SOURCE export addresses without guessing');
$cdb=(string)file_get_contents($ev.'20261030-G3-MAP-original-C227i-native-2012-disasm.log');
$ck(str_contains($cdb,'Core!FNullOutError::Serialize:')
 &&str_contains($cdb,'10101000 c20800          ret     8'),
 'original 2012 compiled Core.dll shared UMapProperty SerializeItem RVA 0x1000 has *only* ret8; this applies to empty AND nonempty native map');
$ck(str_contains($cdb,'Core!UMapProperty::NetSerializeItem:')
 &&str_contains($cdb,'10149fe0 b801000000      mov     eax,1')
 &&str_contains($cdb,'10149fe5 c20c00          ret     0Ch'),
 'original 2012 compiled method NetSerializeItem returns true and writes no bytes; not imported from 227k');
$getText=static function(string $name)use($ev):string{
 $b=(string)file_get_contents($ev.$name);
 return str_starts_with($b,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($b,2)):$b;
};
$v432=$getText('20261030-G3-B06-FIXED-V432-original-T047-partial-MAP.log');
$ck(str_contains($v432,'G3_B06_ORIGINAL_V432_REAL_MAP map=')
 &&str_contains($v432,'nonemptyCount=2 first=1200 second=3400'),
 'original compiled stock v432 Core.dll actual UMapProperty with valid key/value descriptors and genuine TMap two entries, not fabricated');
foreach(['idx=0 originalElements=0 archived=0 readerPos=0 remaining=0',
          'idx=1 originalElements=2 archived=0 readerPos=0 remaining=2'] as $frag)$ck(
 str_contains($v432,$frag),
 'actual original 1999 compiled UE1 UMapProperty::SerializeItem both 0 count and 2 nonempty map wrote 0 bytes loaded cursor0 '.$frag);
$cpp432=(string)file_get_contents($ev.'20261030-G3-B06-FIXED-V432-original-T047-partial-MAP.cpp');
$ck(str_contains($cpp432,'new(actual,FName(TEXT("G3B06OriginalMap")),RF_Transient)UMapProperty')
 &&str_contains($cpp432,'nonempty.Set(12,1200)')
 &&str_contains($cpp432,'nonempty.Set(34,3400)'),
 'real original v432 compiled C++ map instance created, nonempty values native and original method invoked');
$native227=$getText('20261030-G3-B06-FIXED-C227k-original-T047-partial-MAP.log');
$ck(str_contains($native227,'G3_B06_C227K_REAL_MAP_ALLOC map=')
 &&str_contains($native227,'idx=0 count=0')
 &&str_contains($native227,'idx=1 count=2'),
 'actual original C227k native constructed real UMapProperty with two valid native UIntProperty children, real native zero and nonzero TScriptMap');
$cases=[
 ['idx'=>0,'hex'=>'00','bytes'=>1,'pairs'=>[]],
 ['idx'=>1,'hex'=>'020C000000B004000022000000480D0000',
  'bytes'=>17,'pairs'=>[['key'=>12,'value'=>1200],['key'=>34,'value'=>3400]]]
];
$p=new P('C:/Temp/UnrealDB/O06/Native-227k/System/G3Array227Runtime.u');
foreach($cases as $test){
 $idx=$test['idx'];$hex=$test['hex'];$expected=$test['bytes'];$expectedPairs=$test['pairs'];
 $ck(str_contains($native227,'idx='.$idx.' bytes='.$expected.' hex='.$hex)
 &&str_contains($native227,'idx='.$idx.' count='.count($expectedPairs).' tell='.$expected),
 'genuine 227k compiled Core.dll native serialized real TScriptMap count and exact archive bytes, then independently loads same count and Tell '.$idx);
 $raw=hex2bin($hex);
 foreach([227,0] as $licensee){
  $pos=0;$decoded=S::decodeOriginalMapAt($raw,$pos,'unreal-v227k',$licensee,'intproperty','intproperty');
  $ck($pos===$expected
   &&($decoded['source_map_value_archive_bytes']??-1)===$expected
   &&($decoded['source_map_element_count']??-1)===count($expectedPairs)
   &&($decoded['source_map_entries']??null)===$expectedPairs,
   'current source-gated actual original C227k native key/value archive exact cursor and values, independent of licensee '.$licensee.' idx'.$idx);
  $layout=['struct_reference'=>null,'source_family'=>'unreal-v227k','fields'=>[[
    'name'=>'OriginalNativeMap','kind'=>'mapproperty','array_dim'=>1,'skip_persistent_archive'=>false,
    'map_fields_verified'=>true,'map_source_family'=>'unreal-v227k',
    'map_key_kind'=>'intproperty','map_value_kind'=>'intproperty']]];
  $pos=0;$full=S::readAt($raw,$pos,$layout,true,$p);
  $out=$full['members'][0]['values'][0]??[];
  $ck($pos===$expected
    &&($full['status']??null)==='struct_field_values_decoded'
    &&($out['source_map_entries']??null)===$expectedPairs
    &&($full['serialized_bytes']??null)===$expected,
   'actual production struct member map linked readAt consumes precise original native integer map source bytes and values '.$idx.' licensee'.$licensee);
 }
}
$raw=hex2bin($cases[1]['hex']);
foreach(['ut99-v432','unreal-v227i'] as $profile){
 foreach([0,227] as $licensee){
  $pos=0;$decoded=S::decodeOriginalMapAt($raw,$pos,$profile,$licensee,'intproperty','intproperty');
  $ck($pos===0 &&$decoded['status']==='map_original_source_no_data'
   &&$decoded['source_map_memory_count_not_inferable']===true
   &&$decoded['source_map_items_archived']===false,
   'original compiled v432/2012 no-data source branch correctly does not infer nonempty memory map from zero file bytes '.$profile.' '.$licensee);
 }
}
foreach([
 ['unreal-v227','00'],
 ['unreal-v227k','020C000000B004000022000000480D0000']
] as [$profile,$hex]){
 $pos=0;$raw=hex2bin($hex);
 $decoded=S::decodeOriginalMapAt($raw,$pos,$profile,227,
    $profile==='unreal-v227'?'intproperty':'nameproperty','intproperty');
 $ck($pos===0&&($decoded['status']??null)==='map_source_unsupported',
   'ambiguous engine source family or untested key serializers explicitly unsupported, never fabricated');
}
$ok=$fail===[];
echo json_encode([
 'ok'=>$ok,'checks'=>$n,'failures'=>$fail,
 'full_frozen_g3_ids'=>['T047'=>'PASS'],
 'original_UT99_v432_map_bytes_empty_nonempty'=>[0,0],
 'original_2012_C227i_map_bytes_empty_nonempty'=>[0,0],
 'original_2026_C227k_map_bytes_empty_nonempty'=>[1,17],
 'selected_by_compiled_original_engine_profile_not_archive_header'=>true,
 'previously_accepted_native_engine_probes_reexecuted'=>0,
 'G3_complete'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
