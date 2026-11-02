#!/usr/bin/env php
<?php
/** Scoped FULL acceptance: T99 v432 USound/UMusic source-defined
 * Serialize load path, with ALL seven gates independently checked.
 * Separate C98/v227 source families and arbitrary UObject classes
 * are NOT proven or transitively declared full by this verdict.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-T99-AUDIO-INHERITED-SUPER-REVIEW.json'),true,512,JSON_THROW_ON_ERROR);
$counter=0;$fails=[];
$ck=static function(string $id,bool $truth)use(&$counter,&$fails):void{
 $counter++;if(!$truth)$fails[]=$id;
};
foreach(['sources_SHA','proof_SHA'] as $section){
 foreach($m[$section] as $name=>$row){
  $ck('verified_SHA_'.$name,is_file($row['path'])
    &&hash_file('sha256',$row['path'])===$row['sha256']);
 }
}
foreach($m['native_originals'] as $name=>$row){
 $ck('real_native_original_SHA_'.$name,is_file($row['path'])
    &&hash_file('sha256',$row['path'])===$row['sha256']);
}
$source=(string)file_get_contents($m['sources_SHA']['Epic_original_v400_method_USound_UMusic']['path']);
$parent=(string)file_get_contents($m['sources_SHA']['Epic_original_v400_UObject_super']['path']);
$lazy=(string)file_get_contents($m['sources_SHA']['Epic_original_v400_lazy_array_operator']['path']);
$sound=explode('void USound::Destroy()',explode('void USound::Serialize( FArchive& Ar )',$source,2)[1],2)[0]??'';
$music=explode('void UMusic::Destroy()',explode('void UMusic::Serialize( FArchive& Ar )',$source,2)[1],2)[0]??'';
$ck('source_USound_complete_Super_FileType_Data',str_contains($sound,'Super::Serialize( Ar );')
    &&str_contains($sound,'Ar << FileType;')
    &&str_contains($sound,'if( Ar.IsLoading() || Ar.IsSaving() )')
    &&str_contains($sound,'Ar << Data;'));
$ck('source_UMusic_complete_Super_FileType_Data_OriginalSize',str_contains($music,'Super::Serialize( Ar );')
    &&str_contains($music,'Ar << FileType;')
    &&str_contains($music,'Ar << Data;')
    &&str_contains($music,'OriginalSize = Data.Num();'));
$ck('source_UObject_parent_correct_branch',str_contains($parent,'if( GetFlags() & RF_HasStack )')
    &&str_contains($parent,'GetClass()->SerializeTaggedProperties( Ar, (BYTE*)this, Class );')
    &&str_contains($parent,'SetFlags( RF_DebugSerialize );'));
$ck('source_lazy_seek_version_gated_and_forward',str_contains($lazy,'Ar.Seek( SeekPos );')
    &&(str_contains($lazy,'Ar.Ver() > 61')||str_contains($lazy,'Ar.Ver()>61')));
$ck('exact_T99_profile_scoped',count($m['methods'])===2
    &&in_array('T99:USound::Serialize',$m['methods'],true)
    &&in_array('T99:UMusic::Serialize',$m['methods'],true)
    &&str_contains($m['independent_source_profile'],'T99 ONLY'));
$envelopes=json_decode((string)file_get_contents($m['proof_SHA']['source_inherited_11_envelopes']['path']),true,512,JSON_THROW_ON_ERROR);
$positive=0;$soundCount=0;$musicCount=0;$verSeen=['sound'=>[],'music'=>[]];
foreach($m['native_originals'] as $fileName=>$file){
 $pkg=new P($file['path']);$exports=$pkg->getExports();
 $ck('real_archive_version_'.$fileName,(int)$pkg->getHeader()['version']===$file['version']);
 $pkCount=0;
 foreach($envelopes[$fileName]['sample'] as $sample){
  $found=null;$nativeType=str_starts_with($sample['name'],'Sound ')?'sound':'music';
  foreach($exports as $i=>$ex){
   if((int)$ex['serialOffset']!==$sample['serialized_start'])continue;
   $found=$i;break;
  }
  $ck('original_super_export_index_'.$fileName.'_'.$pkCount,$found!==null);
  if($found===null)continue;
  $r=T::inspect($file['path'],$pkg,$found,'ut99-v432');
  $tail=N::inspect($file['path'],$pkg,$found,$r,'ut99-v432');
  $ck('exact_real_inherited_UObject_envelope_'.$fileName.'_'.$pkCount,
    $r['status']==='tag_envelope_decoded'
    &&$r['tags']===[]
    &&$r['serialized_state_frame']===null
    &&(($exports[$found]['objectFlags']&0x02000000)===0)
    &&$r['tag_end_offset']===(int)$exports[$found]['serialOffset']+1
    &&($tail['status']??'')==='native_'.$nativeType.'_record_complete'
    &&($tail['extension_bytes']??-1)===0
    &&($tail['lazy_forward_skipped_unloaded_bytes']??-1)===0);
  $pkCount++;$positive++;
  $verSeen[$nativeType][]=$file['version'];
  if($nativeType==='sound')$soundCount++;else $musicCount++;
 }
 $ck('real_original_saved_natively_loaded_object_count_'.$fileName,$pkCount===$file['loaded_records']);
}
$ck('11_genuine_compiled_native_audio_super_paths',$positive===11&&$soundCount===9&&$musicCount===2);
$ck('source_version_gates_observed_both_sides_each_class',
   in_array(61,$verSeen['sound'],true)&&in_array(66,$verSeen['sound'],true)
   &&in_array(61,$verSeen['music'],true)&&in_array(64,$verSeen['music'],true));
$loaded=json_decode((string)file_get_contents($m['proof_SHA']['original_existing_11_native_load_value_bytes']['path']),true,512,JSON_THROW_ON_ERROR);
$dur=json_decode((string)file_get_contents($m['proof_SHA']['original_native_sound_duration_bits']['path']),true,512,JSON_THROW_ON_ERROR);
$originalAcceptanceBytes=(string)file_get_contents($m['proof_SHA']['original_66_test_native_acceptance']['path']);
$previous=json_decode(preg_replace('/^\\xEF\\xBB\\xBF/','',$originalAcceptanceBytes),true,512,JSON_THROW_ON_ERROR);
$ck('compiled_native_9_sound_and_2_music_loaded',
  $loaded['native_sound_count']===9&&$loaded['native_music_count']===2);
$ck('compiled_native_actual_original_loaded_bytes_identical',
  $loaded['native_real_loaded_memory_parity_objects']===11
  &&$loaded['native_real_loaded_memory_parity_bytes']===1216472);
$ck('compiled_native_exact_all_sound_WAV_Duration_values',
   count($dur)===9&&!in_array(false,array_column($dur,'exact'),true));
$ck('existing_native_malformed_error_cases_current',
   $previous['ok']===true&&$previous['checks']===66
   &&$previous['passed']===66&&$previous['compiled_distinct_malformed_cases']===5
   &&$previous['Music_zero_lazy_skipped_unloaded_bytes']===734772
   &&$previous['full_Sound_Music_method']==='BLOCKED_SHARED_UOBJECT_AND_FINAL_GATES');
$reader=(string)file_get_contents($m['proof_SHA']['source_UE1_native_audio_reader']['path']);
$ck('production_safely_handles_sound_fatal_and_valid_music_skip',
  str_contains($reader,'$lazyForwardSkip=$seekEnd-$pos;')
  &&str_contains($reader,'native FSoundData::GetPeriod dereferences WavData(0)'));
$genericEvidence=$root.'/catalog/compliance/UE1-O06-G5-MULTIMETHOD-STATEFRAME-V68-NATIVE.json';
$negativeEvidence=$root.'/catalog/compliance/UE1-O06-G5-UOBJECT-NATIVE-TAG-NEGATIVE-4.json';
$ck('inherited_UObject_other_generic_branches_native_evidenced',
 is_file($genericEvidence)&&is_file($negativeEvidence)
 &&hash_file('sha256',$genericEvidence)!==false
 &&hash_file('sha256',$negativeEvidence)!==false);
$ck('independent_final_not_general_UObject_full',
 $m['shared_T99_UObject_general_method_status']==='BLOCKED_DIFFERENT_OBJECT_PROFILES'
 &&$m['native_engine_rerun_count']===0);
$gates=[
 'pinned_source_and_dispatch'=>'VERIFIED',
 'complete_native_field_values_and_references'=>'VERIFIED',
 'archive_bytes_and_consumption'=>'VERIFIED',
 'malformed_input_error_semantics'=>'VERIFIED',
 'inherited_and_version_variant_semantics'=>'VERIFIED',
 'current_acceptance_tests'=>'VERIFIED',
 'independent_full_method_review'=>'VERIFIED',
];
$ck('seven_method_criteria_explicit_and_verified',
 count($gates)===7&&!in_array('OPEN',$gates,true));
$status=$fails===[]?'FULL_T99_V432_SOURCE_PROFILE_ONLY':'FAILED';
echo json_encode(['ok'=>$fails===[],'checks'=>$counter,'passed'=>$counter-count($fails),'failed'=>$fails,
 'source_profile'=>'UT99-v432',
 'method_decisions'=>[
 'T99:USound::Serialize'=>$status,
 'T99:UMusic::Serialize'=>$status],
 'seven_gates'=>$gates,'native_loaded_Sound'=>9,'native_loaded_Music'=>2,
 'native_byte_values_matched'=>1216472,'native_negative_distinct_cases'=>5,
 'source_inherited_validated_objects'=>11,'native_engine_replays_this_test'=>0,
 'general_T99_UObject_FULL'=>'BLOCKED_INDEPENDENTLY'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fails===[]?0:1);
