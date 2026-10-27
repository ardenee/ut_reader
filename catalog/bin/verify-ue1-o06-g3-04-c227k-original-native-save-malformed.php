#!/usr/bin/env php
<?php
/**
 * Genuine original shipped 2026 Unreal 227k Core.dll:
 * native UState/UFunction actual SAVE -> archive -> fresh native LOAD,
 * plus malformed function FUNC_Net -> original SerialSize fatal negative.
 * No PHP simulation is used as the original-engine oracle.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;
$root=dirname(__DIR__,2);
$ev=$root.'/catalog/compliance/evidence/o06/20261027-';
$sys='C:/Temp/UnrealDB/O06/Native-227k/System/';
$hashes=[
 [$sys.'Core.dll','c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194'],
 ['L:/Source/Games/Unreal/OldUnreal-UnrealPatch227k-SDK-Windows/Core/Lib/x86/Core.lib','b46f9cab43814946c7e4ac224fdf2dfc575498dad1a4557548639bb9ff12321f'],
 [$sys.'G304FunctionStateGenerate.exe','ffefa7262e58dee6088a714bb29124a5f66f2b3a320947f4136fb429aae6ebf4'],
 [$sys.'G304FunctionStateLoad.exe','4b3ae1af383824803f26903f5d70b2ab76195e4292cdcb6dfe2901665c9d6f39'],
 [$sys.'G304FunctionStateBadNetLoad.exe','405e734b3b515c79da10d7aa29f04a5c21975a079c1f2b14395c7f8ea6dd7381'],
 [$ev.'G304FunctionStateFixture.u','1a9a98e156c15d66cf9c12e58110b50a622e7e965e24d1586b9b2dedcc4edb63'],
 [$ev.'G304FunctionStateBadNet.u','39d31b8726fa99a3e80c491dd369c250f58ed9748d2017ce21e61a757661bcb1'],
 [$ev.'g3-04-227k-tiny-native-save.cmd','3d9acbf52779bf7eb907609c2b53f881bdc30281cf5539be1d9907b660371a08'],
 [$ev.'g3-04-227k-tiny-native-save.log','47d42511afd7c9df7398466c74740e255057556174c435d0f9922e1caf1899a1'],
 [$ev.'g3-04-227k-tiny-native-reload.cmd','2603b800eb9d1708dcfd8705da678b708d9e0932768bf067ed5d1441cd38a209'],
 [$ev.'g3-04-227k-tiny-native-reload.log','441d08df3b4ba1f6c45d7b5a083d6abdf4be690ede88450daa4ad3222d891d2d'],
 [$ev.'g3-04-227k-tiny-badnet-native-error.cmd','84cffb8b10cbc9e829d2eda9ee94e9427f63516ea4625c724c5e85c58ce30dd6'],
 [$ev.'g3-04-227k-tiny-badnet-native-error.log','d8809caf42ae4490fbbfe6d50c7434c530b1a6583a97028e550fca6e4e08ab45'],
 [$ev.'g3-04-227k-tiny-original-load-console.log','5fcea14c4182d5ece3849e0587700aad54e90000141ef3cc6fa09e09c245a3d0'],
 [$ev.'g3-04-227k-tiny-badnet-original-console.log','034349492ae2871638c3898c65746c67bc1ec9e5555b8df7db81f7272e7ed8ad'],
 [$ev.'g3-04-227k-native-function-state-generate.cpp','ea66624797f6435ce24bf124e22f6035c4cf68563e806d57206db5dc09967e37'],
 [$ev.'g3-04-227k-native-function-state-load.cpp','ce6ff2927ff7ab7036df9e8db9ca23ac75b831ff6fb83af90824eca9f6740f57'],
 [$ev.'g3-04-227k-native-function-state-badnet-load.cpp','f5bd40dd1ca088e65e7ff823055a5d0996e88feccb05f64397683c59769e7f35'],
];
$checks=0;$fail=[];
$assert=static function(bool $ok,string $label)use(&$checks,&$fail):void{
 ++$checks;if(!$ok&&count($fail)<25)$fail[]=$label;
};
foreach($hashes as [$path,$sha])
 $assert(is_file($path)&&hash_file('sha256',$path)===$sha,
   'original native or generated fixture SHA '.basename($path));
$source=file_get_contents($ev.'g3-04-227k-native-function-state-generate.cpp');
$assert(str_contains($source,'UObject::SavePackage(')
 &&str_contains($source,'UState::StaticClass()')
 &&str_contains($source,'UFunction::StaticClass()')
 &&str_contains($source,'G304FunctionStateFixture.u')
 &&str_contains($source,'state->Children=fn;'),
 'source-native generator uses original compiled UState/UFunction SavePackage');
$save=file_get_contents($ev.'g3-04-227k-tiny-native-save.log');
$assert(preg_match_all('/^G304_TINY_SAVING_UFUNCTION\s*$/m',$save)===3
 &&preg_match_all('/^G304_TINY_SAVING_USTATE\s*$/m',$save)===3
 &&str_contains($save,'G304_TINY_NATIVE_SAVE_START'),
 'original CDB observed one real native SavePackage with three passes each');
$assert(str_contains($save,'$t1=00000003')
 &&str_contains($save,'$t2=00000003'),
 'original debugger native UFunction/UState Save registers');
$load=file_get_contents($ev.'g3-04-227k-tiny-original-load-console.log');
$nativeLoad=file_get_contents($ev.'g3-04-227k-tiny-native-reload.log');
$assert(str_contains($load,'G304_ORIGINAL_REAL_LOADER_FIELDS_MATCH=1')
 &&str_contains($load,'G304_LOADED_STATE_PROBE=1020304050607080')
 &&str_contains($load,'IGNORE=1234567890ABCDEF')
 &&str_contains($load,'FLAGS=00010200 LABEL=0 CHILD='),
 'fresh process original compiled Core.dll reloaded exact UState values and children');
$assert(str_contains($load,'G304_LOADED_FUNCTION_FLAGS=00000002')
 &&str_contains($load,'NATIVE=0 PRECEDENCE=0 NUMPARMS=0 PARMSIZE=0 RETURN=65535')
 &&str_contains($nativeLoad,'G304_TINY_NATIVE_LOADING_UFUNCTION')
 &&str_contains($nativeLoad,'G304_TINY_NATIVE_LOADING_USTATE'),
 'actual original compiled UFunction/UState LOAD method and in-memory values');
$validPath=$ev.'G304FunctionStateFixture.u';
$badPath=$ev.'G304FunctionStateBadNet.u';
$valid=file_get_contents($validPath);
$bad=file_get_contents($badPath);
$assert(strlen($valid)===310&&strlen($bad)===310,
 'original SAVE writes a 310-byte genuine UE1 v69 package');
$changed=[];
for($i=0;$i<strlen($valid);++$i)if($valid[$i]!==$bad[$i])$changed[]=$i;
$assert($changed===[217]&&ord($valid[217])===2&&ord($bad[217])===0x42,
 'malformed input is only one original UFunction FunctionFlags FUNC_Net bit');
$validPkg=new P($validPath);
$badPkg=new P($badPath);
foreach(['valid'=>$validPkg,'mutant'=>$badPkg] as $kind=>$pkg){
 $head=$pkg->getHeader();$exp=$pkg->getExports();$im=$pkg->getImports();
 $assert(($head['version']??null)===69&&($head['licenseeVersion']??null)===227
    &&count($exp)===2&&count($im)===3,
    $kind.' genuine 227k saved header, 2 exports and 3 imports');
 $assert(($exp[0]['objectNameText']??null)==='G304Function'
    &&($exp[1]['objectNameText']??null)==='G304State'
    &&($exp[0]['serialOffset']??null)===196
    &&($exp[0]['serialSize']??null)===25
    &&($exp[1]['serialOffset']??null)===221,
    $kind.' same genuine UFunction/UState source export identities/cursors');
}
$inspect=static function(string $path,P $pkg,int $idx):array{
 return N::inspect($path,$pkg,$idx,
   T::inspect($path,$pkg,$idx,S::UNREAL_V227),S::UNREAL_V227);
};
$function=$inspect($validPath,$validPkg,0);
$state=$inspect($validPath,$validPkg,1);
$assert(($function['status']??null)==='native_function_record_complete'
 &&($function['function_flags']??null)===2
 &&($function['native_index']??null)===0
 &&($function['operator_precedence']??null)===0
 &&($function['rep_offset']??'absent')==='absent',
 'genuine newly saved original 227k UFunction persisted metadata');
foreach(['loaded_parms_size'=>0,'loaded_num_parms'=>0,
 'loaded_return_value_offset'=>65535,'children_reference'=>0,
 'source_line'=>0,'source_text_pos'=>0,'script_size'=>0] as $field=>$expected)
 $assert(($function[$field]??null)===$expected,'original native fixture function '.$field);
$assert(($function['source_function_compiled_runtime_profile']??null)===S::UNREAL_V227
 &&($function['source_function_link_status']??null)==='source_property_layout_computed'
 &&($function['runtime_function_param_chain_linked']??null)===false,
 'fresh original native source family and no fabricated pointer linkage');
$assert(($state['status']??null)==='native_state_record_complete'
 &&($state['friendly_name']['text']??null)==='G304State'
 &&($state['children_reference']??null)===1
 &&($state['probe_mask_hex']??null)==='8070605040302010'
 &&($state['ignore_mask_hex']??null)==='efcdab9078563412'
 &&($state['state_flags']??null)===66048
 &&($state['label_table_offset']??null)===0,
 'actual native saved State 2 eight-byte masks, 32-bit flags, label and child');
$badNative=file_get_contents($ev.'g3-04-227k-tiny-badnet-original-console.log');
$badNativeStack=file_get_contents($ev.'g3-04-227k-tiny-badnet-native-error.log');
$assert(str_contains($badNative,'Core.Errors.SerialSize')
 &&!str_contains($badNative,'G304_ORIGINAL_REAL_LOADER_FIELDS_MATCH=1'),
 'the original compiled 227k loader treats function serial size overrun as a fatal SerialSize error');
$assert(str_contains($badNativeStack,'Core!ULinkerLoad::Preload+0x163')
 &&str_contains($badNativeStack,'Access violation')
 &&str_contains($badNativeStack,'G304_TINY_BADNET_REAL_NATIVE_ERROR_EXIT'),
 'real compiled malformed native error stack retained without claiming native Tell');
$negative=$inspect($badPath,$badPkg,0);
$assert(($negative['status']??null)==='native_export_serial_size_mismatch'
 &&($negative['source_serial_size_check_failed']??null)===true
 &&($negative['function_flags']??null)===0x42
 &&($negative['rep_offset']??null)===0,
 'reader exposes fatal archive-size mismatch rather than completing invalid UFunction');
$assert(($negative['archive_cursor']??null)===223
 &&($negative['expected_export_end']??null)===221
 &&($negative['serial_size_overrun_bytes']??null)===2,
 'reader physical archived cursor 223 is 2 beyond valid export bound 221');
$assert(!isset($negative['source_function_loaded_fields_computed'])
 &&($negative['loaded_num_parms']??null)===null,
 'malformed UFunction does not expose guessed post-LOAD values');
$next=$inspect($badPath,$badPkg,1);
$assert(($next['status']??null)==='native_state_record_complete',
 'mutated UFunction does not modify original UState export payload');
$ok=$fail===[];
echo json_encode([
 'ok'=>$ok,'checks'=>$checks,'fail'=>$fail,
 'original_engine_build'=>'Unreal 227k v69/227 x86 original Core.dll',
 'original_compiled_state_save_calls'=>3,
 'original_compiled_function_save_calls'=>3,
 'original_compiled_distinct_saved_state_objects'=>1,
 'original_compiled_distinct_saved_function_objects'=>1,
 'original_saved_package_size_bytes'=>strlen($valid),
 'original_compiled_fresh_process_load_match'=>true,
 'compiled_fatal_malformed_error'=>'Core.Errors.SerialSize',
 'reader_malformed_cursor'=>223,'function_serial_end'=>221,
 'numeric_native_Tell_observed'=>false,
 'C98_T99_469f_C227i_native_reexecuted'=>false,
 'G3_04_Full'=>'OPEN: other versions, native malformed exact Tell, SAVE branches and remaining states'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
