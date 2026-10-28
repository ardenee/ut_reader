#!/usr/bin/env php
<?php
/**
 * May 17 1998 original retail C98 compiled FObjectManager SavePackage,
 * the actual native Unreal.exe -make / original Editor.dll compiler.
 *
 * A newly authored Actor with UState and two UFunctions was genuinely saved
 * by original C98 into a 1058-byte .u; a distinct fresh C98 Unreal.exe
 * loaded that file and original x86 CDB captured post-LOAD UFunction+UState.
 *
 * The May19 v1.200 source confirms the native command's intended control
 * flow, but must NOT be misrepresented as byte-identical May17 binary source.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;

$e=dirname(__DIR__).'/compliance/evidence/o06/20261028-G3-04-C98-';
$sys='C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/';
$src='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/';
$hashes=[
 $e.'original-1998-native-SAVE.u'=>'4957034dc7629163ab7adabeedff1fc9cecc3def1d577c4aab31bec0763071a4',
 $e.'original-script-input.uc'=>'334f6df36aa460a3289d5de73dda4c172f636cdd1afeca6a7df663e90218edc2',
 $e.'original-compiled-postload.cmd'=>'c0ce100b29b4a3939d8ded4ce24b9ecf1c4da47055fb73ada0388281e3846542',
 $e.'original-compiled-postload.log'=>'e618259ae4eb3dc10bab57be7748ca36cb3831625b6d872099d884dde1f37f93',
 $e.'original-fresh-load-editor.log'=>'a7ee99ac741ed2c4bfa9fa2ebe221ddea4dd8c5c7fc6565f55bf7e692bcca023',
 $sys.'Core.dll'=>'9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92',
 $sys.'Engine.dll'=>'2f3ab0f8bc40960e6be5e1f540ca974a1537d9b23f2b9d7d06af589400b70069',
 $sys.'Editor.dll'=>'d24cee492ecdaa3756f60777691fd0c88548c162375edd11aaa3d8c0c9616c0d',
 $sys.'Unreal.exe'=>'d631c8af81d2bba50131254e9770aceb0e59ebd3df97f4cb8560c9c6835e7c30',
 $sys.'Core.u'=>'7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c',
 $sys.'Engine.u'=>'bdccc8ee1557b758554714bcc676edb2cb4474239c70bedaaf81f07e9a419b7d',
 $sys.'Editor.u'=>'c4f10c26dc21c87599d0e6b9f479e4bdd254207e3119220f45b939d1c0025a13',
 $sys.'Unreal.ini'=>'a565749beb564d4f9ed0845a863717c24057a779d40826987c84cc43c17e9186',
 $src.'Editor/Src/UnEditor.cpp'=>'205903c32b131434a1a2088781ac67c1da8ecf28668feced76d104a2c4d3a8f0',
 $src.'Launch/Src/Launch.cpp'=>'e6967ab69e79b12c6c32e63eea349b2a7c9b511426c653397c5aa91505731eff',
];
$count=0;$failed=[];
$ok=static function(bool $yes,string $reason)use(&$count,&$failed):void{++$count;if(!$yes)$failed[]=$reason;};
foreach($hashes as $f=>$sha)$ok(is_file($f)&&hash_file('sha256',$f)===$sha,'SHA original compiled/source evidence '.basename($f));
$source=(string)file_get_contents($e.'original-script-input.uc');
$ok(str_contains($source,'class G304C98Actor expands Actor')
  &&str_contains($source,'state G304State')
  &&str_contains($source,'function bool G304CanJump()')
  &&str_contains($source,'function int G304AddOne(int X)'),'new authentic C98 script declares both functions and a state');
$later=(string)file_get_contents($src.'Editor/Src/UnEditor.cpp');
$launch=(string)file_get_contents($src.'Launch/Src/Launch.cpp');
$ok(str_contains($later,'ParseParam( appCmdLine(),"MAKE" )')
  &&str_contains($later,'GObj.SavePackage( PkgObject, NULL, RF_Standalone, Filename )'),
  'distinct real FObjectManager SavePackage path anchored in May19 source (not misrepresented as May17 binary)');
$ok(str_contains($launch,'ParseParam(appCmdLine(),"MAKE")'),
  'original game launcher source explicitly supports -make command');
$runtime=(string)file_get_contents($e.'original-fresh-load-editor.log');
$ok(str_contains($runtime,'Loading: Package G304C98Save')
  &&str_contains($runtime,'Unloading: Package G304C98Save'),
  'fresh actual original 1998 compiled engine loads genuine saved package');
$cmd=(string)file_get_contents($e.'original-compiled-postload.cmd');
$log=(string)file_get_contents($e.'original-compiled-postload.log');
$ok(str_contains($cmd,'10114359')&&str_contains($cmd,'10114b69'),
  'original actual May17 C98 native non-NET UFunction / UState compiled breakpoints');
$ok(str_contains($log,'G304_C98_NEW_ORIGINAL_NATIVE_LOAD_FINISHED'),
  'original CDB native post-LOAD finished');
$file=$e.'original-1998-native-SAVE.u';$p=new P($file);$ex=$p->getExports();
$ok(filesize($file)===1058&&($p->getHeader()['version']??null)===61
    &&($p->getHeader()['licenseeVersion']??null)===0&&count($ex)===9,
    'true original May17 C98 native writer emitted 1058 byte v61/0 9-export package');
$ok(($ex[4]['objectNameText']??null)==='G304C98Actor'
  &&($ex[5]['objectNameText']??null)==='G304State',
  'original native C98 compiler generated UClass and UState objects');
$fnPattern='/^G304_C98_NEW_NATIVE_FUNCTION_NONNET linker=([0-9a-f]{8}) index=(\d+) object=[0-9a-f]{8}\s+[0-9a-f]{8}\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})/mi';
preg_match_all($fnPattern,$log,$fnEvents,PREG_SET_ORDER);
$new=[];
foreach($fnEvents as $x)if(in_array((int)$x[2],[6,8],true))$new[]=$x;
$ok(count($new)===2,'two newly C98-compiled functions captured in actual original post-LOAD');
$expect=[
 6=>['G304CanJump',1,4,0,770,40],
 8=>['G304AddOne',2,8,4,822,45],
];
$linker=[];$nativeCount=0;$indices=[];
foreach($new as $n){
 $idx=(int)$n[2];$linker[]=$n[1];$indices[$idx]=true;
 $exp=$expect[$idx];
 $value=N::inspect($file,$p,$idx,T::inspect($file,$p,$idx,S::EPIC_UE1),S::EPIC_UE1);
 $ok(($ex[$idx]['objectNameText']??null)===$exp[0]
   &&($ex[$idx]['serialOffset']??null)===$exp[4]
   &&($ex[$idx]['serialSize']??null)===$exp[5]
   &&($value['status']??null)==='native_function_record_complete',
   'original 1998 generated UFunction export identity, serial size/bounds '.$idx);
 $native=['function_flags'=>hexdec($n[3]),'loaded_parms_size'=>hexdec($n[4]),
          'loaded_return_value_offset'=>(hexdec($n[5])>>16)&65535,
          'loaded_num_parms'=>hexdec($n[6])];
 $reference=['function_flags'=>2,'loaded_parms_size'=>$exp[2],
          'loaded_return_value_offset'=>$exp[3],'loaded_num_parms'=>$exp[1]];
 foreach($reference as $field=>$correct){
   ++$nativeCount;
   $ok($native[$field]===$correct&&($value[$field]??null)===$correct,
     'TRUE actual original May17 compiled loaded '.$exp[0].' '.$field);
 }
}
$ok(count($indices)===2&&count(array_unique($linker))===1,
    'both true newly native loaded functions share one original linker');
$state=N::inspect($file,$p,5,T::inspect($file,$p,5,S::EPIC_UE1),S::EPIC_UE1);
$statePattern='/^G304_C98_NEW_NATIVE_STATE linker=([0-9a-f]{8}) index=5 object=[0-9a-f]{8}\s+[0-9a-f]{8}\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+[0-9a-f]{8}\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})/mi';
$ok(preg_match($statePattern,$log,$sm)===1,'original compiled C98 actual state loaded after SOURCE native SAVE');
if(isset($sm[8])){
  $ok($sm[1]===$linker[0],'same original compiled native linker for UState and functions');
  $ok(hexdec($sm[2])===0&&hexdec($sm[3])===0
      &&hexdec($sm[4])===0xffffffff&&hexdec($sm[5])===0xffffffff,
      'original real native C98 loaded ProbeMask0 and IgnoreMask UINT64_MAX');
  $ok(hexdec($sm[8])===12,'original C98 compiled native State label offset=12');
}
foreach(['status'=>'native_state_record_complete','state_flags'=>0,'probe_mask_hex'=>'0000000000000000',
 'ignore_mask_hex'=>'ffffffffffffffff','label_table_offset'=>12,'children_reference'=>7] as $field=>$correct)
   $ok(($state[$field]??null)===$correct,'original newly saved C98 UState '.$field);
$ok(($ex[5]['serialOffset']??null)===708&&($ex[5]['serialSize']??null)===62,
  'C98 native writer exact state export bounds');
echo json_encode(['ok'=>$failed===[],'checks'=>$count,'failed'=>$failed,
    'new_original_1998_native_saved_bytes'=>1058,'new_C98_original_compiled_UFunction_POSTLOAD'=>count($new),
    'new_C98_original_compiled_UState_POSTLOAD'=>isset($sm[8])?1:0,
    'new_C98_true_native_per_function_field_comparisons'=>$nativeCount,
    'earlier_sealed_native_reexecuted'=>false,
    'g3_04_FULL'=>'OPEN until all source-defined branches accepted'],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===[]?0:1);
