#!/usr/bin/env php
<?php
/** One genuinely distinct native v432 malformed dynamic array failure.
 * The compiled DLL attempts stream reads until PHYSICAL package EOF,
 * not a preemptive count<=export SerialSize guard. No native reruns.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);$base='C:/Temp/UnrealDB/O06/';
$fail=[];$good=0;
$ck=static function(string $name,bool $v) use(&$fail,&$good):void {
 if($v)$good++; else $fail[]=$name;
};
$source=$base.'Native-UCC/System/G5ArrayPayload.u';
$core=$base.'Native-UCC/System/Core.dll';
$log=$base.'G5-UObject-Native-Array-Count31-New-Native.txt';
$report=$base.'G5-UObject-Native-Array-Count31-One-New-Mutation.json';
$devScript=$base.'g5-native-array-large-count-one-new.py';
$engine='L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnProp.cpp';
$reader=$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php';
$ck('original_generated_compiled_package_SHA',
 is_file($source)&&hash_file('sha256',$source)==='2436d2545135a8ca02d5f1e71252daf3314be6e5b02c371a7db8ae5450e16ff3');
$ck('original_compiled_v432_core_SHA',
 is_file($core)&&hash_file('sha256',$core)==='237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8');
$ck('native_new_stdout_SHA',
 is_file($log)&&hash_file('sha256',$log)==='5b22df2a609d87803a857c047397a4f9bf6885fddf87cac449c0b63a3889c869');
$ck('Epic_real_v400_UArray_source_SHA',
 is_file($engine)&&hash_file('sha256',$engine)==='693194ef489989f1c2ffee8542cf4c2d8950d07ed0b041eff154531c5b097457');
$man=json_decode((string)file_get_contents($report),true,512,JSON_THROW_ON_ERROR);
$ck('native_single_new_mutation_source',
 $man['source']==='compiled_original_UT99_v432_Core_DLL'
 &&$man['one_byte_count_mutation']===['offset'=>254,'original'=>3,'new'=>31]);
$ck('native_real_early_failure_exit1',
 $man['native_exit']===1&&$man['native_observed_failure']===true);
$ck('native_real_FArchive_reaches_exact_physical_end',
 str_contains((string)file_get_contents($log),'ULinkerLoad::Serialize')
 &&str_contains((string)file_get_contents($log),'308==308/308')
 &&in_array('Exiting due to error',$man['native_selected_lines'],true));
$ck('native_stdout_not_duplicated_prior_native_array',
 $man['native_stdout_sha256']==hash_file('sha256',$log)
 &&$man['pristine_restored']===true);
$ck('safe_DEV_only_original_copy_scope',
 is_file($devScript)
 &&str_contains((string)file_get_contents($devScript),'finally:')
 &&str_contains((string)file_get_contents($devScript),'p.write_bytes(orig)'));
$epic=(string)file_get_contents($engine);
$ck('native_source_counted_add_not_export_size',
 str_contains($epic,'void UArrayProperty::SerializeItem( FArchive& Ar, void* Value ) const')
 &&str_contains($epic,'((FArray*)Value)->Add( n, c );')
 &&str_contains($epic,'Inner->SerializeItem( Ar, p+i*c );'));
$new=(string)file_get_contents($reader);
$ck('production_safe_physical_bound_not_export_cap',
 str_contains($new,'$streamState[\'physical_limit\']-$position')
 &&str_contains($new,'UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset ')
 &&!str_contains($new,'dynamic array element count exceeds export SerialSize'));
$bytes=(string)file_get_contents($source);
$ck('unmodified_original_count3',strlen($bytes)===308&&ord($bytes[254])===3);
$folder=$base.'g5-array-php-fixtures';
if(!is_dir($folder))mkdir($folder,0777,true);
foreach(['G5ArrayProbe.u','Core.u','Engine.u'] as $name){
 $from=$base.'Native-UCC/System/'.$name;
 $to=$folder.'/'.$name;
 if(!is_file($to)||hash_file('sha256',$to)!==hash_file('sha256',$from)){
  if(!copy($from,$to))throw new RuntimeException('Cannot stage native dependency');
 }
 $ck('native_pristine_dependency_'.$name,hash_file('sha256',$from)===hash_file('sha256',$to));
}
$copy=$folder.'/G5ArrayPayload.u';
try{
 $newBytes=$bytes;$newBytes[254]=chr(31);
 $ck('one_byte_mutation_in_DEV_scratch_only',
  count(array_filter(array_map(static fn(int $a,int $b):bool=>$a!==$b,
   array_values(unpack('C*',$bytes)),array_values(unpack('C*',$newBytes)))))===1);
 file_put_contents($copy,$newBytes);
 $pkg=new P($copy);
 $ck('v432_original_archive_version_69',$pkg->getHeader()['version']===69);
 $error=null;
 try{T::inspect($copy,$pkg,0,'ut99-v432');}catch(Throwable $e){$error=$e->getMessage();}
 $ck('production_native_linker_physical_EOF_cursor_identical',
  $error==='UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset 308');
}finally{
 file_put_contents($copy,$bytes);
}
$ck('scratch_restored_byte_identical',hash_file('sha256',$copy)===hash_file('sha256',$source));
$ck('canonical_original_still_unchanged',
 hash_file('sha256',$source)===$man['original_pkg_sha256']);
$ck('scope_only_T99_v432_class_source',
 str_contains($new,'$family===CatalogUE1ClassicSourceFamily::UT99_V432')
 &&$man['one_byte_count_mutation']['new']===31);
$result=['ok'=>$fail===[],'checks'=>$good+count($fail),'passed'=>$good,'failed'=>$fail,
 'actual_compiled_native_exit'=>1,
 'native_linker_failure_absolute_offset'=>308,
 'PHP_diagnostic_native_source_origin'=>'ULinkerLoad::Serialize',
 'original_archive_restore_SHA_verified'=>true,
 'native_binary_reruns_in_verifier'=>0,
 'global_UObject_method_full'=>'BLOCKED_OTHER_SOURCE_VARIANTS'];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
