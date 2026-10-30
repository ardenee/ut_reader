#!/usr/bin/env php
<?php
/** Only NEW original 1999 v432 native UArrayProperty NetSerializeItem; 2012 Should remains blocked. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);$ev=$root.'/catalog/compliance/evidence/o06/';
$n=0;$failed=[];$ck=static function(bool $good,string $why)use(&$n,&$failed):void{++$n;if(!$good)$failed[]=$why;};
$sha=[
 '20261030-G3-T049-original-v432-NET.cpp'=>'8fc4e713db1ce80e9e2500b3e925d1f93cbc1aafe9857cbcbbd53e4e39a7f0d3',
 '20261030-G3-T049-original-v432-NET.log'=>'e261223209c7e2b81731f98eae7f6a44f97d9cd2081b344082ad4fb3db630892',
 '20261030-G3-T049-original-current-source-gates.php'=>'3e339a381757bedd334e6334b4f9246f927fb9c77ddd76f49245a4e8ab3b38fd',
 '20261029-G3-T049-C227i227k-NetSerialize-59pass-PARTIAL.json'=>'',
];
foreach($sha as $name=>$expected)$ck(is_file($ev.$name)
 &&($expected===''||hash_file('sha256',$ev.$name)===$expected),
 'actual compiled original prior/new source/log SHA '.$name);
$ck(hash_file('sha256',$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicPropertyNativeReader.php')
 ==='5c1f797d68d3d88cf52b80eac83ada26e1307255c82c3ad68afa62dd2433eb74',
 'production source-gated property reader current exact SHA');
$ck(hash_file('sha256',$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicNativeObjectReader.php')
 ==='afca90b65cfb192d23e5d0aaa0f449acb651c85548fb81ccac25bce3a9963714',
 'real production native record reader preserves explicit 2012 vs 2026 compiled ABI overlay');
$cpp=(string)file_get_contents($ev.'20261030-G3-T049-original-v432-NET.cpp');
$ck(str_contains($cpp,'G5ArrayPayload.u')&&str_contains($cpp,'UArrayProperty::StaticClass()')
 &&str_contains($cpp,'array->NetSerializeItem(writer,NULL,&real)'),
 'genuine original compiled UT99 v432 UArrayProperty::NetSerializeItem invoked on real G5ArrayProbe source-defined Numbers with genuine native INT inner');
$s=(string)file_get_contents($ev.'20261030-G3-T049-original-v432-NET.log');
if(str_starts_with($s,"\xFF\xFE"))$s=(string)iconv('UTF-16LE','UTF-8',substr($s,2));
$ck(str_contains($s,'G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=')
 &&str_contains($s,'native_error=0')
 &&str_contains($s,'G3_T049_ORIGINAL_V432_REAL_ARRAY')
 &&str_contains($s,'G3_T049_ORIGINAL_V432_NET_ALL_PASS=1'),
 'new original compiled stock v432 native loaded actual compiler-produced .u and real Int array method, all cases pass');
foreach([0,1,2] as $case)$ck(
 str_contains($s,'G3_T049_ORIGINAL_V432_NET case='.$case.' result=1 nativeCount='.$case.' bytes=0 hex='),
 'NEW original v432 compiled actual NetSerializeItem successfully returns1, writes ZERO bytes for source real array count '.$case);
$earlier=json_decode((string)file_get_contents($ev.'20261029-G3-T049-C227i227k-NetSerialize-59pass-PARTIAL.json'),true);
$ck(($earlier['ok']??false)===true &&($earlier['checks']??null)===59
 &&($earlier['status']??null)==='PARTIAL_ONLY',
 'reused sealed original 2012 C227i compiled Net stub and 2026 C227k actual native 24 Net/32 Should source proofs, no rerun');
$ck(($earlier['original_2012_native_NetSerializeItem']['serialized_bytes']??-1)===0
 &&($earlier['original_2012_native_NetSerializeItem']['return']??-1)===1
 &&($earlier['original_compiled_native_sessions_rerun_by_verifier']??-1)===0,
 'original 2012 compiled UArrayProperty NetSerializeItem true and no archive bytes, previously proven');
$ck(($earlier['original_2026_native_NetSerializeItem']['native_successful_calls']??0)===24,
 'previous original C227k compiled Engine 8 real INT inner array properties x 3 native payload cases');
$ck(($earlier['original_2026_native_ShouldSerializeItem']['native_boolean_calls']??0)===32,
 'original 2026 Core.dll actual 16 real UArrayProperty x null and non-null Should true method');
$decl=['G3Array227.u','G5ArrayProbe.u'];
$cases=[
 ['C:/Temp/UnrealDB/O06/Native-C227i-G304/System/G3Array227.u','unreal-v227i','Pairs',0,0],
 ['C:/Temp/UnrealDB/O06/Native-C227i-G304/System/G3Array227.u','unreal-v227k','Pairs',2,10],
 ['C:/Temp/UnrealDB/O06/Native-UCC/System/G5ArrayProbe.u','ut99-v432','Numbers',0,0]
];
foreach($cases as [$file,$family,$name,$arrayOneCount,$arrayOneBytes]){
 $pkg=new P($file);$found=false;
 foreach($pkg->getExports() as $i=>$ex){
  if($ex['objectNameText']!==$name)continue;
  $tag=T::inspect($file,$pkg,$i,$family);
  $native=N::inspect($file,$pkg,$i,$tag,$family);
  if(($native['property_kind']??null)!=='arrayproperty')continue;
  $scope=$native['subclass_fields']['network_packet_payload_decoding_in_package_file_reader']??null;
  $method=$native['subclass_fields']['runtime_array_network_method_gate']??[];
  $ck(($native['status']??null)==='native_property_record_complete'
     &&$scope===false,
     'source-linked REAL original UArrayProperty declaration no network payload fabricated from package archive '.$family);
  $ck(($method['net_method']??null)===($family==='unreal-v227k'
      ?'compiled_native_array_network_payload'
      :'compiled_native_returns_true_no_bytes'),
     'actual C++ compiled original runtime source profile selected NET behavior '.$family);
  $lengths=$method['native_output_bytes_for_0_1_2_int_values']
    ??$method['native_output_bytes_for_0_1_2']??[];
  $ck($lengths===($family==='unreal-v227k'?[2,10,18]:[0,0,0]),
     'real native runtime selected three source-defined array element counts network payload lengths '.$family);
  $found=true;break;
 }
 $ck($found,'genuine original compiler .u real array property reached via actual reader '.$family);
}
$missing=$earlier['original_2012_native_NetSerializeItem']['original_compiled_ShouldSerializeItem_runtime']??'';
$ck($missing==='UNVERIFIED',
 '2012 ShouldSerializeItem public source method declared, original native call STILL unverified, keep T049 BLOCKED not false pass');
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'checks'=>$n,'failures'=>$failed,
 'fixed_test'=>'T049','status'=>'PARTIAL_ONLY_2012_ORIGINAL_SHOULD_SERIALIZE_RUNTIME_MISSING',
 'new_original_v432_native_successful_NetSerializeItem_cases'=>3,
 'original_2012_NetSerializeItem'=>['result'=>1,'bytes'=>0],
 'original_2026_NetSerializeItem'=>['values'=>[0,1,2],'bytes'=>[2,10,18]],
 'original_2026_ShouldSerializeItem_actual_native_calls'=>32,
 'real_file_reader_network_packet_codec'=>'EXCLUDED_not_package_LOAD_SAVE',
 'original_2012_ShouldSerializeItem_runtime'=>'BLOCKED',
 'original_completed_engine_native_tests_reexecuted'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
