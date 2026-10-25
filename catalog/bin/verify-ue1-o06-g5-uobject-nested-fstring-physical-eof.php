#!/usr/bin/env php
<?php
/** Distinct actual compiled v432 nested FString overrun: one byte native
 * count mutation 13->96, compiled unchanged Core.dll with previously
 * documented diagnostic heap-zero allocator reaches physical EOF byte 308.
 * The diagnostic heap only enables safe entry to this malformed branch:
 * not a claim of ordinary stock allocator runtime success.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);
$base='C:/Temp/UnrealDB/O06/';
$source=$base.'Native-UCC/System/G5StringPayload.u';
$log=$base.'G5-UObject-Native-Nested-FString-Count96-PhysicalEOF.txt';
$manifest=$base.'G5-UObject-Native-Nested-FString-Count96-PhysicalEOF.json';
$nativeScript=$base.'g5-uobject-nested-fstring-physical-eof-native.py';
$phpScript=$base.'g5-uobject-nested-fstring-physical-eof-php.php';
$originalEngine='L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnProp.cpp';
$core=$base.'Native-UCC/System/Core.dll';
$diagnostic=$base.'G5-UObject-Native-String-Load-ZeroAllocator.cpp';
$nativeBinary=$base.'G5-UObject-Native-String-Load-ZeroAllocator.exe';
$reader=$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php';
$bad=[];$passes=0;
$assert=static function(string $name,bool $test)use(&$bad,&$passes):void{
 if($test)$passes++;else $bad[]=$name;
};
$expectedSha='680f56fd730b1f61d9d1b25a8c8bc8f0aaff95f4e7ce8900753159b24b6e2e5c';
$assert('original_Epic_core_Dll_pinned',is_file($core)
 &&hash_file('sha256',$core)==='237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8');
$assert('original_generated_archive_sha',is_file($source)&&hash_file('sha256',$source)===$expectedSha);
$assert('source_unmodified_v400_UnProp',is_file($originalEngine)
 &&hash_file('sha256',$originalEngine)==='693194ef489989f1c2ffee8542cf4c2d8950d07ed0b041eff154531c5b097457');
$assert('controlled_original_native_executable_pinned',is_file($nativeBinary)
 &&hash_file('sha256',$nativeBinary)!==false);
$assert('source_heap_zero_origin_diagnostic_only',
 is_file($diagnostic)
 &&str_contains((string)file_get_contents($diagnostic),'class FDiagnosticZeroMalloc : public FMallocAnsi')
 &&str_contains((string)file_get_contents($diagnostic),'memset(p,0,Count)'));
$assert('original_Epic_array_count_serialize_is_unbounded_by_export',
 str_contains((string)file_get_contents($originalEngine),'Inner->SerializeItem( Ar, p+i*c );')
 &&str_contains((string)file_get_contents($originalEngine),'((FArray*)Value)->Add( n, c );'));
$m=json_decode((string)file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
$assert('one_byte_diff_and_pristine_recovery',
 $m['mutation']===['offset'=>224,'old'=>13,'new'=>96,'changed_byte_count'=>1]
 &&$m['original_restored']===true
 &&$m['original_archive_sha256']===$expectedSha);
$assert('native_original_core_dispatched_overlong_FString',
 $m['native_exit']===1
 &&$m['native_finished_loading']===false
 &&$m['stock_native_loaded_success']==='NOT_ESTABLISHED');
$assert('native_original_stdout_hash_pinned',
 is_file($log)&&hash_file('sha256',$log)===$m['native_stdout_sha256']
 &&$m['native_stdout_sha256']==='9e0ac12fac20df2933bf0e6c9c4000c9f9a0eb73b69f800b4ce5f34a1d679786');
$nativeBytes=(string)file_get_contents($log);
if(str_starts_with($nativeBytes,"\xFF\xFE")||str_starts_with($nativeBytes,"\xFE\xFF")){
 $n=iconv('UTF-16','UTF-8',$nativeBytes);
 $nativeBytes=$n===false?$nativeBytes:$n;
}
$assert('real_native_linker_physical_end',
 str_contains($nativeBytes,'ULinkerLoad::Serialize')
 &&str_contains($nativeBytes,'308==308/308'));
$assert('real_native_error_from_FString',
 str_contains($nativeBytes,'FString<<')
 &&str_contains($nativeBytes,'(Captions[-1])'));
$assert('native_rejection_is_not_success',
 str_contains($nativeBytes,'native_error=1')
 &&!str_contains($nativeBytes,'G5_NATIVE_OBJECT_COUNT 1'));
$assert('one_new_native_original_mutation_runner',
 is_file($nativeScript)
 &&str_contains((string)file_get_contents($nativeScript),'finally:')
 &&str_contains((string)file_get_contents($nativeScript),'original.write_bytes(blob)'));
$assert('production_scope_only_nested_strproperty_branch',
 str_contains((string)file_get_contents($reader),
  '// Epic UStrProperty::SerializeItem calls the')
 &&str_contains((string)file_get_contents($reader),
  "'UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset '")
 &&str_contains((string)file_get_contents($reader),"' during FString<<'"));
$assert('production_old_early_error_removed',
 !str_contains((string)file_get_contents($reader),
 'UE1 v432 dynamic array FString exceeds export SerialSize'));
$assert('independent_DEV_php_probe_available',is_file($phpScript));
$orig=(string)file_get_contents($source);
$assert('source_first_FString_13_and_length308',strlen($orig)===308
 &&ord($orig[224])===13&&substr($orig,225,12)==='Alpha-string');
$dir=$base.'g5-nested-fstring-eof-php';
if(!is_dir($dir))mkdir($dir,0777,true);
foreach(['G5NestedProbe.u','Core.u','Engine.u'] as $dep){
 $a=$base.'Native-UCC/System/'.$dep;$b=$dir.'/'.$dep;
 if(!is_file($b)||hash_file('sha256',$a)!==hash_file('sha256',$b)){
  if(!copy($a,$b))throw new RuntimeException('Unable to stage original dependency '.$dep);
 }
 $assert('original_source_dependency_'.$dep,
  is_file($b)&&hash_file('sha256',$a)===hash_file('sha256',$b));
}
$copy=$dir.'/G5StringPayload.u';
try{
 $bytes=$orig;$bytes[224]=chr(96);
 file_put_contents($copy,$bytes);
 $pkg=new P($copy);
 $assert('real_original_archive_v69',(int)$pkg->getHeader()['version']===69);
 $idx=null;
 foreach($pkg->getExports() as $i=>$row)if($row['objectNameText']==='G5StringActor')$idx=$i;
 $assert('source_class_G5StringActor_export',$idx!==null);
 $error=null;
 if($idx!==null){
  try{T::inspect($copy,$pkg,$idx,'ut99-v432');}
  catch(Throwable $e){$error=$e->getMessage();}
 }
 $assert('production_matches_exact_native_absolute_EOF_and_origin',
  $error==='UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset 308 during FString<<');
}finally{file_put_contents($copy,$orig);}
$assert('scratch_archive_restored_SHA',hash_file('sha256',$copy)===$expectedSha);
$assert('canonical_compiled_original_intact_after_test',hash_file('sha256',$source)===$expectedSha);
$out=['ok'=>$bad===[],'checks'=>$passes+count($bad),'passed'=>$passes,'failed'=>$bad,
 'native_unchanged_compiled_DLL'=>true,
 'native_conditioned_allocator_origin_explicit'=>true,
 'native_mutation_physical_EOF_byte'=>308,
 'native_mutation_exception_origin'=>'ULinkerLoad::Serialize <- FString<<',
 'production_physical_EOF_cursor'=>308,'source_generated_archive_restored'=>true,
 'stock_native_loaded_FString_parity'=>'NOT_VERIFIED',
 'general_T99_UObject_METHOD'=>'BLOCKED',
 'native_engine_reruns_in_verifier'=>0];
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($bad===[]?0:1);
