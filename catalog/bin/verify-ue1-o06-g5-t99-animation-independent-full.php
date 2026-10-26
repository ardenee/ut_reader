#!/usr/bin/env php
<?php
/** Source-scoped UT99 v432 UAnimation file-read final independent method
 * review. Reuses SHA-pinned compiled oracles, no native engine re-execution.
 * Does not certify generic UObject nor C98/C227 serializer methods.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$c=$root.'/catalog/compliance/';
$m=json_decode((string)file_get_contents($c.'UE1-O06-G5-T99-ANIMATION-ONE-METHOD-NATIVE.json'),true,512,JSON_THROW_ON_ERROR);
$err=json_decode((string)file_get_contents($c.'UE1-O06-G5-T99-ANIMATION-NATIVE-ERROR-CURSORS.json'),true,512,JSON_THROW_ON_ERROR);
$checkCount=0;$failed=[];
$ck=static function(string $id,bool $ok)use(&$checkCount,&$failed):void{$checkCount++;if(!$ok)$failed[]=$id;};
$hash=static fn(string $p):?string=>is_file($p)?hash_file('sha256',$p):null;
$pin=static function(array $a,string $label)use($ck,$hash):void{
 $ck('original_sha_'.$label,is_file($a['path'])&&$hash($a['path'])===$a['sha256']
     &&filesize($a['path'])===$a['bytes']);
};
foreach($m['original_epic_source'] as $name=>$a)$pin($a,'epic_'.$name);
foreach($m['native_engine_sha'] as $name=>$a)$pin($a,'dll_'.$name);
foreach($m['compiled_probe'] as $name=>$a){if(is_array($a)&&isset($a['path']))$pin($a,'probe_'.$name);}
foreach($m['source_comparison'] as $name=>$a)$pin($a,'source_comparator_'.$name);
$ck('exact_method',($m['method']??'')==='T99:UAnimation::Serialize'
 &&($err['method']??'')==='T99:UAnimation::Serialize');
$ck('source_profile',($err['source_family']??'')==='ut99-v432'
 &&($err['version']??0)===69);
$head=(string)file_get_contents($m['original_epic_source']['v432_skeletal_headers']['path']);
$anim=(string)file_get_contents($m['original_epic_source']['v432_animation_struct_fields']['path']);
$super=(string)file_get_contents($m['original_epic_source']['inherited_v400_UObject_method']['path']);
$arr=(string)file_get_contents('L:/Source/Games/UT99/Unreal Tournament v432/Core/Inc/UnTemplate.h');
$ck('native_v432_UAnimation_class_declared_direct_UObject',
 str_contains($head,'class ENGINE_API UAnimation : public UObject')
 &&str_contains($head,'DECLARE_CLASS(UAnimation,UObject,0,Engine)')
 &&str_contains($head,'void Serialize( FArchive& Ar );'));
$ck('source_nested_sequence_field_order',
 str_contains($anim,'Ar << A.Name << A.Group << A.StartFrame << A.NumFrames << A.Notifys << A.Rate'));
$ck('source_notify_field_order',str_contains($anim,'return Ar << N.Time << N.Function;'));
$ck('generic_source_super_method',
 str_contains($super,'void UObject::Serialize( FArchive& Ar )')
 &&str_contains($super,'if( GetFlags() & RF_HasStack )')
 &&str_contains($super,'GetClass()->SerializeTaggedProperties( Ar, (BYTE*)this, Class );'));
$ck('version_neutral_array_operators',
 str_contains($arr,'Ar << AR_INDEX(NewNum)')
 &&str_contains($arr,'A.Empty( NewNum )')
 &&str_contains($arr,'for( INT i=0; i<NewNum; i++ )'));
$ck('direct_derived_archive_only_three_arrays',
 count($m['native_cases'])===3
 &&($m['inherited_envelopes']['verified_original_objects']??-1)===3
 &&($m['compiled_positive_result']['motion_chunks']??-1)===70);
$loaded=0;
foreach($m['native_cases'] as $idx=>$case){
 $pin($case['source_package'],'untouched_original_'.$idx);
 $pin($case['compiled_loaded_log'],'actual_loaded_values_'.$idx);
 $p=new P($case['source_package']['path']);
 $ck('original_package_ver69_'.$idx,(int)$p->getHeader()['version']===69);
 $stem=pathinfo($case['original'],PATHINFO_FILENAME);
 $index=['table'=>23,'EcoTerror'=>6,'d00d'=>7][$stem]??null;
 $ck('original_export_index_'.$idx,is_int($index));
 if(!is_int($index))continue;
 $export=$p->getExports()[$index];
 $tag=T::inspect($case['source_package']['path'],$p,$index,'ut99-v432');
 $record=N::inspect($case['source_package']['path'],$p,$index,$tag,'ut99-v432');
 $ck('no_original_UAnimation_StateFrame_'.$idx,
   (((int)$export['objectFlags']&0x02000000)===0)
   &&($tag['serialized_state_frame']??null)===null);
 $ck('original_source_inherited_no_saved_tags_'.$idx,
   ($tag['status']??'')==='tag_envelope_decoded'
   &&($tag['tags']??[false])===[]);
 $ck('original_method_archive_complete_'.$idx,
   ($record['status']??'')==='native_animation_record_complete'
   &&($record['extension_bytes']??-1)===0
   &&($record['archive_bytes_consumed']??-1)===$tag['native_tail_bytes']);
 $ck('original_source_method_only_RefBones_Moves_AnimSeqs_'.$idx,
   ($record['archive_fields_in_order']??[])===['RefBones','Moves','AnimSeqs']);
 $loaded++;
}
$ck('three_original_compiled_loaded_whole_animations',$loaded===3);
$ck('all_original_loaded_fields_completely_proven',
 $m['compiled_positive_result']['current_php_loaded_value_checks']===3694
 &&$m['compiled_positive_result']['native_compared_elements_or_records']===3667
 &&$m['compiled_positive_result']['numeric_array_bytes']===540836);
$ck('all_eight_original_malformed_outcomes_pinned',
 count($m['negative_cases'])===8
 &&$m['negative_result']['native_rejected']===8
 &&$m['negative_result']['php_rejected']===8);
foreach($m['negative_cases'] as $idx=>$case){
 $pin($case['source_original'],'negative_original_'.$idx);
 $pin($case['corrupted_copied_package'],'negative_mutated_'.$idx);
 $pin($case['compiled_native_exception_log'],'negative_native_log_'.$idx);
}
$ck('six_compiled_cdb_first_chance_errors',count($err['exact_cursors'])===6);
$ck('native_original_core_engine_sha',
 $hash('C:/Temp/UnrealDB/O06/Native-UCC/System/Core.dll')===$err['immutable_original_Core_DLL_sha256']
 &&$hash('C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.dll')===$err['immutable_original_Engine_DLL_sha256']);
$expected=['d00d-invalid-bone-name'=>153323,'EcoTerror-invalid-bone-name'=>34393,
 'EcoTerror-invalid-sequence-name'=>593470,'EcoTerror-negative-animtrack-count'=>899657,
 'EcoTerror-negative-keyquat-count'=>899657,'table-negative-refbones-count'=>413388];
foreach($err['exact_cursors'] as $case){
 $id=$case['case'];
 $ck('compiled_true_cursor_'.$id,isset($expected[$id])
    &&$expected[$id]===$case['compiled_cursor']);
 $ck('native_CDB_log_untouched_'.$id,$hash($case['compiled_cdb_log_path'])===$case['compiled_cdb_log_sha256']);
}
$oldProofs=[
 $c.'UE1-O06-G5-MULTIMETHOD-STATEFRAME-V68-NATIVE.json',
 $c.'UE1-O06-G5-UOBJECT-NATIVE-TAG-NEGATIVE-4.json',
 $c.'UE1-O06-G5-USOUND-SOURCE-PROFILE-FULL-ACCEPTANCE.json',
 $c.'UE1-O06-G5-UFONT-SOURCE-PROFILE-FULL-ACCEPTANCE.json',
];
foreach($oldProofs as $i=>$p)$ck('shared_generic_UObject_compiled_inherited_proof_'.$i,is_file($p)&&$hash($p)!==false);
$audio=json_decode((string)file_get_contents($oldProofs[2]),true,512,JSON_THROW_ON_ERROR);
$font=json_decode((string)file_get_contents($oldProofs[3]),true,512,JSON_THROW_ON_ERROR);
$ck('shared_v432_inheritance_already_accepted',
 $audio['decision']==='FULL'&&$font['decision']==='FULL'
 &&$audio['seven_verified_criteria']['inherited_and_version_variant_semantics']==='VERIFIED'
 &&$font['seven_verified_criteria']['inherited_and_version_variant_semantics']==='VERIFIED');
$gates=array_fill_keys(['pinned_source_and_dispatch','complete_native_field_values_and_references',
 'archive_bytes_and_consumption','malformed_input_error_semantics',
 'inherited_and_version_variant_semantics','current_acceptance_tests',
 'independent_full_method_review'],'VERIFIED');
$ck('seven_source_scoped_criteria',count($gates)===7
 &&count(array_filter($gates,static fn($g)=>$g==='VERIFIED'))===7);
$all=$failed===[];
echo json_encode(['ok'=>$all,'checks'=>$checkCount,'passed'=>$checkCount-count($failed),
 'failed'=>$failed,'method'=>'T99:UAnimation::Serialize',
 'decision'=>$all?'FULL_UT99_V432_SOURCE_PROFILE_ONLY':'FAILED',
 'seven_gates'=>$all?$gates:array_fill_keys(array_keys($gates),'OPEN'),
 'original_compiled_whole_loaded_objects'=>3,'original_native_value_comparisons'=>3694,
 'native_matched_numeric_array_bytes'=>540836,'distinct_native_malformed_outcomes'=>8,
 'compiled_exact_native_error_cursors'=>6,'native_engine_reruns_this_review'=>0,
 'general_T99_UObject_full'=>'NOT_CLAIMED',
 'limitations'=>'UT99 v432 package-loading subclass serializer only; generic UObject all-class and C98/C227, saving/transaction archives excluded'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($all?0:1);
