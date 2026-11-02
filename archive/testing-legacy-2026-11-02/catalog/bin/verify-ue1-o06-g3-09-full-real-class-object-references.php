<?php
/** Independent one-off G3-09 original compiled reference class Serializer acceptance.
 * Previously accepted 600 C98 references and two actual C98 native failures reused
 * by SHA only, NOT executed. Native positive original T99/C227 and new one-byte
 * opposite compiled MetaClass outcomes are compared to genuine source archives.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as F;
$repo=dirname(__DIR__,2);
$root=$repo.'/catalog/compliance';
$m=json_decode((string)file_get_contents($root.'/UE1-O06-G3-09-T99-C227-REAL-REFERENCES-NULL-METACLASS.json'),true,512,JSON_THROW_ON_ERROR);
$live=json_decode((string)file_get_contents($root.'/UE1-O06-G3-LIVE-CLOSURE-STATUS.json'),true,512,JSON_THROW_ON_ERROR);
$n=0;$failed=[];
$check=static function(string $x,bool $valid)use(&$n,&$failed):void{
 $n++;if(!$valid&&count($failed)<32)$failed[]=$x;
};
$check('exact source contract G3-09 and cannot falsely certify full G3',
 $m['contract']==='G3-09'&&$m['whole_G3_09_full_method_gate_already_passed']===false
 &&$live['full_contracts_accepted']===4
 &&current(array_values(array_filter($live['contracts'],static fn($z)=>$z['id']==='G3-09')))['full_status']==='OPEN');
$old=[
 'UE1-O06-G3-C98-MAY1998-600-REFERENCE-LOADED.json',
 'UE1-O06-G3-C98-MAY1998-TWO-NATIVE-MALFORMED-CURSORS.json'];
foreach($old as $filename){
 $p=$root.'/'.$filename;
 $check('previous authentic May C98 compiled reference/failure evidence present '.$filename,
   is_file($p)&&filesize($p)>1000);
}
$oldPositive=json_decode((string)file_get_contents($root.'/'.$old[0]),true,512,JSON_THROW_ON_ERROR);
$oldNegative=json_decode((string)file_get_contents($root.'/'.$old[1]),true,512,JSON_THROW_ON_ERROR);
$check('prior compiled May1998 actual six-hundred-class-reference native methods already accepted',
 $oldPositive['actual_original_native_events']===600
 &&$oldPositive['original_binary_packages']['Core.dll']['sha256']
   ==='9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92');
$check('prior real C98 original two malformed exact native error cursor paths preserved',
 $oldNegative['original_Core_u_untouched_after_tests']===true
 &&count($oldNegative['isolated_mutations_one_byte_diff_from_unmodified_original'])===2);
foreach($m['unmodified_original_stock_source_and_real_native_positive_proof_artifacts'] as $name=>$pin){
 $path=$repo.'/'.$pin['repo_path'];
 $check('NEW original CDB WinDbg immutable actual native evidence SHA '.$name,
   is_file($path)&&filesize($path)===$pin['bytes']
   &&hash_file('sha256',$path)===$pin['sha256']);
}
$asm=[];
foreach(['T99_actual_original_compiled_469f_UObj_UClass_native_functions','C227i_actual_original_compiled_UObj_UClass_native_functions','later_C227k_actual_compiled_UObj_UClass_native_functions'] as $key){
 $item=$m['unmodified_original_stock_source_and_real_native_positive_proof_artifacts'][$key];
 $asm[$key]=str_replace(["\r\n","\r"],"\n",(string)iconv('UTF-16','UTF-8',(string)file_get_contents($repo.'/'.$item['repo_path'])));
}
$check('true UT99 469f original compiled method calls UProperty and archives PropertyClass',
 str_contains($asm['T99_actual_original_compiled_469f_UObj_UClass_native_functions'],'Core!UObjectProperty::Serialize:')
 &&str_contains($asm['T99_actual_original_compiled_469f_UObj_UClass_native_functions'],'Core!UProperty::Serialize')
 &&str_contains($asm['T99_actual_original_compiled_469f_UObj_UClass_native_functions'],'[edi+5Ch]'));
$check('genuine 469f compiled UClass invokes UObject and archives MetaClass',
 str_contains($asm['T99_actual_original_compiled_469f_UObj_UClass_native_functions'],'Core!UClassProperty::Serialize:')
 &&str_contains($asm['T99_actual_original_compiled_469f_UObj_UClass_native_functions'],'call    Core!UObjectProperty::Serialize')
 &&str_contains($asm['T99_actual_original_compiled_469f_UObj_UClass_native_functions'],'add     edi,64h'));
$check('actual 469f compiled ZERO MetaClass substitutes UObject private static instead of asserting',
 str_contains($asm['T99_actual_original_compiled_469f_UObj_UClass_native_functions'],'mov     dword ptr [edi],offset Core!UObject::PrivateStaticClass')
 &&!str_contains($asm['T99_actual_original_compiled_469f_UObj_UClass_native_functions'],'Core!appFailAssert'));
$check('genuine 2012 C227i compiled UClass archives both references but fatal on null',
 str_contains($asm['C227i_actual_original_compiled_UObj_UClass_native_functions'],'call    Core!UObjectProperty::Serialize')
 &&str_contains($asm['C227i_actual_original_compiled_UObj_UClass_native_functions'],'Core!appFailAssert')
 &&str_contains($asm['C227i_actual_original_compiled_UObj_UClass_native_functions'],'add     esi,60h'));
$check('matching 2026 C227k compiled UClass maintains real fatal null branch',
 str_contains($asm['later_C227k_actual_compiled_UObj_UClass_native_functions'],'Core!appFailAssert')
 &&str_contains($asm['later_C227k_actual_compiled_UObj_UClass_native_functions'],'Core!UObjectProperty::Serialize'));
$oldSource=(string)file_get_contents('L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnProp.cpp');
$oldCpp=(string)file_get_contents('L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/UnProp.cpp');
$check('original v400/v432 class-source asserts null (not newer 469f) source SHA and method',
 hash('sha256',$oldSource)==='693194ef489989f1c2ffee8542cf4c2d8950d07ed0b041eff154531c5b097457'
 &&preg_match('/void UClassProperty::Serialize\s*\( FArchive& Ar \).*?Ar << MetaClass;\s*check\(MetaClass\);/s',$oldSource)===1);
$check('original May C98 inherited UClass source also asserts null',
 hash('sha256',$oldCpp)==='512de89b03aa6c3ab3ec2ba8b3b704d0d8839a5297e5a2271a7fe2782c26c52b'
 &&preg_match('/void UClassProperty::Serialize\s*\( FArchive& Ar \).*?Ar << MetaClass;\s*check\(MetaClass\);/s',$oldCpp)===1);
$readerSource=(string)file_get_contents($repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicPropertyNativeReader.php');
$check('production branch preserves source zero MetaClass but records compiled 469f runtime fallback',
 str_contains($readerSource,"if (!\$ut99_469f)")
 &&str_contains($readerSource,"'effective_metaclass_runtime_fallback'")
 &&str_contains($readerSource,"'UObject::PrivateStaticClass'"));
$origSource=[
 'T99'=>['L:/Source/Games/UT99/OldUnreal-UTPatch469f-Windows-x86/System/Core.u',F::UT99_V469F],
 'C227'=>['C:/Temp/UE1-G3-NativeOracle/original227i/System/Core.u',F::UNREAL_V227]];
$observedUnloaded=[];
foreach($m['authentic_original_compiled_reference_profiles'] as $profile=>$set){
 [$file,$family]=$origSource[$profile];
 $pkg=new P($file);
 $check('real original '.$profile.' package validates',$pkg->validatePackage()===[]);
 $imports=$pkg->getImports();$exports=$pkg->getExports();
 $resolve=static function(int $r)use($imports,$exports):?string{
   return $r<0?($imports[-$r-1]['objectNameText']??null)
     :($r>0?($exports[$r-1]['objectNameText']??null):null);
 };
 $cache=[];$seen=[];
 foreach($set['compiled_original_UObjectProperty_Serialize_loaded'] as $row){
   $i=$row['source_export_zero_based_index'];
   $check('native '.$profile.' object reference exact source export identity once '.$i,
      !isset($seen[$i])&&isset($exports[$i]));$seen[$i]=true;
   $ex=$exports[$i]??null;
   if(!$ex)continue;
   $ci=(int)$ex['classIndex'];
   $kind=$ci<0?strtolower((string)($imports[-$ci-1]['objectNameText']??'')):'';
   $check('original '.$profile.' saved property class kind exact '.$i,
      $kind===$row['kind']&&$ex['objectNameText']===$row['source_name']);
   $rec=N::inspect($file,$pkg,$i,T::inspect($file,$pkg,$i,$family),$family);
   $cache[$i]=$rec;
   $check('native '.$profile.' saved original ObjectProperty source complete '.$i,
      $rec['status']==='native_property_record_complete'
      &&$rec['extension_bytes']===0);
   $ref=$rec['subclass_fields']['property_class_reference'];
   $check('original '.$profile.' saved Class reference literal compact ref and native global name '.$i,
      $ref===$row['saved_PropertyClass_compact_ref']
      &&$resolve($ref)===$row['target_original_global_name']);
 }
 $check('complete count original compiler authenticated '.$profile,
   count($seen)==($profile==='T99'?12:25));
 $seenMeta=[];
 foreach($set['compiled_original_UClassProperty_MetaClass_Serialize_loaded'] as $row){
   $i=$row['source_export_zero_based_index'];
   $check('real original '.$profile.' meta class native identity unique '.$i,
      !isset($seenMeta[$i])&&isset($cache[$i]));$seenMeta[$i]=true;
   $ref=$cache[$i]['subclass_fields']['meta_class_reference']??null;
   $check('real original '.$profile.' native UObject MetaClass target and saved original ref match '.$i,
      $ref===$row['saved_MetaClass_compact_ref']
      &&$resolve((int)$ref)===$row['target_original_global_name']
      &&$row['target_original_global_name']==='Object');
 }
 $check('compiled original '.$profile.' MetaClass method count exact',
   count($seenMeta)===($profile==='T99'?4:10));
 $missing=$set['source_real_export_indices_not_compiled_loaded'];
 $check('do not invent compiled loaded object for missing source '.$profile,
   count($missing)===($profile==='T99'?1:0)
   &&count(array_intersect($missing,array_keys($seen)))===0);
 $observedUnloaded[$profile]=$missing;
}
$check('specific original 469f genuine ObjectProperty source-only export 771 remains unobserved native',
 $observedUnloaded['T99']===[771]);
foreach($m['original_separate_two_real_compiled_mutated_MetaClass_outcomes'] as $name=>$pin){
 $original=$pin['original'];$mutant=$pin['isolated_actual_compiled_loaded_package_copy'];
 $offset=$pin['physical_one_byte_offset'];
 $a=(string)file_get_contents($original);$b=(string)file_get_contents($mutant);
 $check('mutant '.$name.' original stock SHA unchanged',
  hash('sha256',$a)===$pin['original_sha256']);
 $check('mutant '.$name.' exactly 1 byte changed by original physical archive address',
  hash('sha256',$b)===$pin['isolated_SHA256']&&strlen($a)===strlen($b)
  &&$a[$offset]==="\x03"&&$b[$offset]==="\x00"
  &&substr($a,0,$offset)===substr($b,0,$offset)
  &&substr($a,$offset+1)===substr($b,$offset+1));
 $family=str_starts_with($name,'T99')?F::UT99_V469F:F::UNREAL_V227;
 $pkg=new P($mutant);
 $check('original single-byte actual native mutant still valid real package '.$name,$pkg->validatePackage()===[]);
 $tags=T::inspect($mutant,$pkg,12,$family);
 if($family===F::UT99_V469F){
   $result=N::inspect($mutant,$pkg,12,$tags,$family);
   $check('real original 469f compiled null is saved zero but native fallback explicitly named',
    $result['status']==='native_property_record_complete'
    &&$result['subclass_fields']['meta_class_reference']===0
    &&($result['subclass_fields']['effective_metaclass_runtime_fallback']??null)==='UObject::PrivateStaticClass');
   $early='';try{N::inspect($mutant,$pkg,12,T::inspect($mutant,$pkg,12,F::UT99_V432),F::UT99_V432);}
   catch(RuntimeException $e){$early=$e->getMessage();}
   $check('same original archive legacy UT99 v432 correctly asserts unlike new 469f overlay',
    str_contains($early,'MetaClass must be non-null'));
 }else{
   $error='';try{N::inspect($mutant,$pkg,12,$tags,$family);}
   catch(RuntimeException $e){$error=$e->getMessage();}
   $check('actual original C227i compiled null MetaClass error emulated not fallback',
     str_contains($error,'MetaClass must be non-null'));
 }
}
$tr=$m['unmodified_original_stock_source_and_real_native_positive_proof_artifacts'];
$t99=(string)iconv('UTF-16','UTF-8',(string)file_get_contents($repo.'/'.$tr['T99_469f_true_original_compiled_one_byte_null_MetaClass_fallback']['repo_path']));
$c227=(string)iconv('UTF-16','UTF-8',(string)file_get_contents($repo.'/'.$tr['C227i_2012_true_original_compiled_one_byte_null_MetaClass_appFailAssert']['repo_path']));
$check('real original compiled UT99 469f mutated source reached correct native method and new fallback',
 substr_count($t99,'G309_469F_NATIVE_ORIGINAL_BOUNDED_NULL_META_FALLBACK')>=2
 &&str_contains($t99,'Core!UObject::PrivateStaticClass')
 &&str_contains($t99,'646d9d40'));
$check('real original compiled C227i mutated null source reached fatal native appFailAssert',
 str_contains($c227,'G309_C227I_NULL_META_SOURCE_METHOD_BRANCH_TAKEN')
 &&str_contains($c227,'G309_ORIGINAL_C227I_ACTUAL_APPFAILASSERT_ENTERED')
 &&str_contains($c227,'Core!appFailAssert'));
$check('G3-14 native malformed cursor remains explicitly independent',
 current(array_values(array_filter($live['contracts'],static fn($v)=>$v['id']==='G3-14')))['full_status']==='OPEN');
$check('G3-15 missing corpus native references remains independent',
 current(array_values(array_filter($live['contracts'],static fn($v)=>$v['id']==='G3-15')))['full_status']==='OPEN');
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'checks'=>$n,'passed'=>$n-count($failed),'failed'=>$failed,
 'G3_09_source_class_method_decision'=>$ok?'ACCEPTED':'BLOCKED',
 'new_compiled_original_469f_UClassProperty_records'=>4,
 'new_compiled_original_469f_UObjectProperty_records'=>12,
 'new_compiled_original_C227_UClassProperty_records'=>10,
 'new_compiled_original_C227_UObjectProperty_records'=>25,
 'separate_real_compiled_Metaclass_zero_behavior_oracles'=>2,
 'original_native_T99_unobserved_property_export'=>[771],
 'historical_C98_600_and_two_negative_tests_reexecuted'=>0],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
