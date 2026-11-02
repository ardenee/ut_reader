#!/usr/bin/env php
<?php
/** Distinguish genuine Epic archive success from runtime allocator risk:
 * unchanged compiled UT99 v432 Core DLL, stock loader native fault versus
 * one disposable custom-zero-allocator native success. Never claim
 * default allocator native value parity on the basis of zeroing.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);
$data=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-T99-UOBJECT-FSTRING-ALLOCATOR-CONDITIONAL.json'),true,512,JSON_THROW_ON_ERROR);
$passes=0;$fails=[];
$check=static function(string $name,bool $ok)use(&$passes,&$fails):void{
 if($ok)$passes++;else $fails[]=$name;
};
foreach(['native_original_Epic_source_SHA','validation_artifacts_SHA'] as $section){
 foreach($data[$section] as $key=>$file){
  $check('pin_'.$key,is_file($file['path'])
   &&hash_file('sha256',$file['path'])===$file['sha256']);
 }
}
$src=static fn(string $group,string $key):string=>(string)file_get_contents($data[$group][$key]['path']);
$prop=$src('native_original_Epic_source_SHA','Epic_v400_UArrayProperty_SerializeItem');
$temp=$src('native_original_Epic_source_SHA','Epic_v432_FArray_Add_vs_AddZeroed');
$check('Epic_UArrayProperty_uses_Add_not_AddZeroed',
 str_contains($prop,'((FArray*)Value)->Add( n, c );')
 &&str_contains($prop,'((FArray*)Value)->Empty( c );'));
$check('Epic_UArrayProperty_serializes_each_inner',
 str_contains($prop,'Inner->SerializeItem( Ar, p+i*c );'));
$check('Epic_Add_does_not_initialize',
 str_contains($temp,'INT Add( INT Count, INT ElementSize )')
 &&str_contains($temp,'INT Index = ArrayNum;')
 &&str_contains($temp,'return Index;')
 &&str_contains($temp,'appMemzero( (BYTE*)Data+Index*ElementSize, n*ElementSize );'));
$modified=$src('validation_artifacts_SHA','ZERO_ALLOCATOR_DIAGNOSTIC_native_source');
$stock=$src('validation_artifacts_SHA','stock_original_unchanged_DLL_native_load_source');
$check('diagnostic_allocator_explicitly_custom',
 str_contains($modified,'class FDiagnosticZeroMalloc : public FMallocAnsi')
 &&str_contains($modified,'memset(p,0,Count)')
 &&str_contains($modified,'memset((BYTE*)p+before,0,Count-before)'));
$check('diagnostic_only_modifies_allocating_host',
 str_contains($stock,'UObject::LoadPackage(NULL,TEXT("G5StringPayload.u"),LOAD_NoFail)')
 &&str_contains($modified,'UObject::LoadPackage(NULL,TEXT("G5StringPayload.u"),LOAD_NoFail)')
 &&str_contains($modified,'FDiagnosticZeroMalloc Malloc;'));
$decodeNative=static function(string $bytes):string {
 if(str_starts_with($bytes,"\xFF\xFE")||str_starts_with($bytes,"\xFE\xFF")){
  $decoded=iconv('UTF-16','UTF-8',$bytes);
  return $decoded===false?$bytes:$decoded;
 }
 return $bytes;
};
$nativeLog=$decodeNative($src('validation_artifacts_SHA','ZERO_ALLOCATOR_DIAGNOSTIC_native_stdout'));
$check('controlled_native_load_success',
 str_contains($nativeLog,'G5_NATIVE_DIRECT_PACKAGE_RESULT')
 &&str_contains($nativeLog,'native_error=0')
 &&str_contains($nativeLog,'G5_NATIVE_OBJECT_COUNT 1'));
$check('controlled_exact_two_loaded_FStrings',
 str_contains($nativeLog,'Captions=("Alpha-string","Beta-string")'));
$check('controlled_exact_two_loaded_FVectors',
 str_contains($nativeLog,'Locations=((X=1.500000,Y=2.500000,Z=3.500000),(X=-4.000000,Y=5.000000,Z=-6.000000))'));
$stockLog=$decodeNative($src('validation_artifacts_SHA','stock_native_loader_fail_stdout'));
$cdbLog=$decodeNative($src('validation_artifacts_SHA','stock_native_allocator_crash_debugger'));
$check('stock_allocator_did_not_complete_native_load',
 str_contains($stockLog,'G5_NATIVE_DIRECT_PACKAGE_BEGIN')
 &&!str_contains($stockLog,'G5_NATIVE_OBJECT_COUNT 1'));
$check('stock_allocator_real_CDB_invalid_pointer_fault',
 str_contains(strtolower($cdbLog),'baadf00d')
 &&str_contains($cdbLog,'Access violation - code c0000005'));
$original=$data['validation_artifacts_SHA']['original_valid_generated_package']['path'];
$pkg=new P($original);$ix=null;
foreach($pkg->getExports() as $i=>$exp){
 if($exp['objectNameText']==='G5StringActor'){$ix=$i;break;}
}
$check('original_native_generated_actor_present',$ix!==null);
$tags=$ix===null?[]:T::inspect($original,$pkg,$ix,'ut99-v432')['tags'];
$captions=null;$locations=null;
foreach($tags as $t){
 if($t['name']==='Captions')$captions=$t;
 if($t['name']==='Locations')$locations=$t;
}
$check('original_compiled_archive_v69',$pkg->getHeader()['version']===69);
$check('original_Captions_two_strings_typed',
 $captions!==null
 &&$captions['value_kind']==='source_typed_dynamic_array'
 &&$captions['value']['inner_property_kind']==='strproperty'
 &&$captions['value']['element_count']===2
 &&$captions['on_disk_value_bytes']===28
 &&array_column($captions['value']['preview_first_32'],'ascii_text')
  ===['Alpha-string','Beta-string']);
$check('original_Locations_two_vectors_typed',
 $locations!==null
 &&$locations['value_kind']==='source_typed_dynamic_array'
 &&$locations['value']['inner_property_kind']==='structproperty'
 &&$locations['value']['element_count']===2);
$float=[];
foreach($locations['value']['preview_first_32']??[] as $v){
 foreach($v['members']??[] as $m)foreach($m['values']??[] as $f)$float[]=$f;
}
$check('original_vector_compiled_values_exact',
 $float===[1.5,2.5,3.5,-4.0,5.0,-6.0]);
$check('native_stock_runtime_parity_explicitly_not_claimed',
 $captions!==null
 &&$captions['value']['native_loaded_value_parity']==='NOT_VERIFIED'
 &&$data['loaded_value_parity_scope']==='ONLY_WHEN_ALLOCATOR_ZERO_INITIALIZES_RETURNED_MEMORY; stock v432 native loaded FString parity remains NOT_VERIFIED');
$check('real_compiled_Core_DLL_not_modified',
 $data['cpp_native_DLL_untouched']===true&&$data['original_package_untouched']===true);
$check('no_native_rerun_in_focused_test',
 $data['new_stock_native_invocations']===0 &&$data['new_diagnostic_native_invocations']===1);
$check('method_not_falsely_full',
 $data['T99_UObject_GLOBAL_FULL']==='BLOCKED'
 &&$data['all_archive_diagnostic_memory_values_match']===true);
$out=['ok'=>$fails===[],'checks'=>$passes+count($fails),'passed'=>$passes,'failed'=>$fails,
 'native_original_v432_DLL_loaded_under_zero_allocator'=>true,
 'original_archive_nested_strings_equal_diagnostic_native'=>2,
 'original_archive_nested_vector_scalars_equal_diagnostic_native'=>6,
 'stock_allocator_native_loader_failure_captured'=>true,
 'stock_allocator_FString_loaded_value_parity'=>'NOT_VERIFIED',
 'T99_UObject_method_full'=>'BLOCKED',
 'real_native_executions_repeated_in_this_test'=>0];
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fails===[]?0:1);
