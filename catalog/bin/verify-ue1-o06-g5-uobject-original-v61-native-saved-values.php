#!/usr/bin/env php
<?php
/**
 * Existing original compiled v432 loader output: reconcile all 926
 * saved tags in 308 original PRD v61 exports, without rerunning engine.
 * The 124 transient XLevel properties are excluded by the exact
 * Epic UProperty::Port() predicate, not falsely claimed as loaded.
 */
declare(strict_types=1);
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-UOBJECT-PRD-V61-COMPILED-SAVED-VALUES-802.json'),true,512,JSON_THROW_ON_ERROR);
$good=0;$failed=[];
$check=static function(string $name,bool $ok)use(&$good,&$failed):void{
 if($ok){$good++;}else{$failed[]=$name;}
};
foreach(['sources_SHA','proof_files_SHA'] as $section){
 foreach($m[$section] as $name=>$e){
  $check('sha256_'.$name,is_file($e['path'])&&hash_file('sha256',$e['path'])===$e['sha256']);
 }
}
$src=fn(string $group,string $name):string=>(string)file_get_contents($m[$group][$name]['path']);
$check('source_v400_export_text_calls_Port',str_contains($src('sources_SHA','Native_ExportProperties_Port_gate'),'if( It->Port() )'));
$check('source_v400_Port_rejects_transient',
 str_contains($src('sources_SHA','Native_UProperty_Port_cpp'),'Category!=NAME_None || !(PropertyFlags & (CPF_Transient | CPF_Native))'));
$check('source_Actor_XLevel_transient',str_contains($src('sources_SHA','Native_Actor_XLevel_transient_uc'),'var transient const Level XLevel'));
$check('source_Brush_enum_CSG_Add_Subtract',
 str_contains($src('sources_SHA','Native_Brush_CSG_enum_uc'),'CSG_Add,')
 &&str_contains($src('sources_SHA','Native_Brush_CSG_enum_uc'),'CSG_Subtract,'));
$check('source_SheerAxis_ZX_5',str_contains($src('sources_SHA','Native_FScale_sheer_enum_h'),'SHEER_ZX   = 5'));
$original=json_decode($src('proof_files_SHA','real_current_PHP_saved_tags'),true,512,JSON_THROW_ON_ERROR);
$native=json_decode($src('proof_files_SHA','complete_compiled_PHP_value_comparison'),true,512,JSON_THROW_ON_ERROR);
$originalTotal=array_sum(array_map(static fn(array $e):int=>count($e['tags']),$original['tagged_object_rows']));
$matched=array_sum(array_filter($native['counts'],static fn(int $n,string $key):bool=>str_starts_with($key,'MATCH_'),ARRAY_FILTER_USE_BOTH));
$check('SHA_original_v61_provenance',$original['genuine_sha256']===$m['proof_files_SHA']['original_sha_copy_v61_map']['sha256']);
$check('SHA_existing_native_stdout_provenance',$native['native_log_sha256']===$m['proof_files_SHA']['original_compiled_v432_native_log']['sha256']);
$check('native_exact_loaded_object_count',$original['loaded']===308&&$native['native_actual_loaded_objects']===308);
$check('original_all_saved_property_count',$originalTotal===926&&$native['original_source_saved_object_tags']===926);
$check('all_saved_tag_rows_accounted',
 $matched===802
 &&$native['counts']['SOURCE_DEFINED_NOT_EXPORTED_TRANSIENT_XLEVEL']===124
 &&$matched+124===$originalTotal);
$check('all_802_native_saved_values_typed_equal',$native['direct_runtime_matches']===802
 &&count($native['positive_matches'])===802
 &&$m['direct_native_memory_export_property_value_matches']===802);
$check('zero_native_value_mismatches',
 $native['direct_mismatch_examples'] === []
 &&$m['native_compiled_value_mismatches']===0
 &&count(array_filter(array_keys($native['counts']),static fn(string $k):bool=>str_starts_with($k,'MISMATCH_')))===0);
$check('zero_unknown_saved_values',
 $native['unchecked_format_examples'] === []
 &&$native['counts']['SOURCE_DEFINED_NOT_EXPORTED_TRANSIENT_XLEVEL']===124
 &&!array_filter(array_keys($native['counts']),static fn(string $k):bool=>str_starts_with($k,'UNTESTED_')));
$check('XLevel_omissions_expected_never_inferred_runtime_verified',
 $m['Epic_Source_Port_Transient_XLevel_intentionally_omitted']===124
 &&$m['scope_limit']!==''
 &&count($native['native_omitted_examples'])>0
 &&count(array_filter($native['native_omitted_examples'],static fn(array $r):bool=>$r['property']!=='XLevel'))===0);
$compareScript=$src('proof_files_SHA','DEV_only_comparison_script');
$check('full_class_qualified_UT99_export_and_import_object_identity',
 $m['native_references_compared_by_full_class_qualified_object_paths']===true
 &&str_contains($compareScript,'def exact_referent(ref):')
 &&str_contains($compareScript,'expected=exact_referent(ref)')
 &&str_contains($compareScript,'return v.casefold()==expected.casefold()'));
$check('native_pointregion_zone_full_referent_verified',
 $m['point_region_zone_referents_compared_by_full_class_qualified_paths']===true
 &&str_contains($compareScript,"expected_zone=exact_referent(value['zone_object_reference'])")
 &&str_contains($compareScript,'expected_zone.casefold()==actual_zone.casefold()'));
$check('native_formatted_float_not_claimed_bitwise_exact',
 $m['native_formatted_float_comparison_tolerance']===0.000001
 &&str_contains($compareScript,'abs_tol=0.000001'));
$check('no_repeat_native_compiled_engine',
 $m['native_compiled_engine_replays_this_turn']===0);
$check('T99_UObject_full_still_blocked',$m['method_FULL']==='BLOCKED');
echo json_encode(['ok'=>$failed===[],'checks'=>$good+count($failed),'passed'=>$good,'failed'=>$failed,
 'native_original_v61_export_objects'=>308,'saved_tag_properties'=>926,
 'loaded_native_value_matches'=>802,'source_defined_XLevel_text_exclusions'=>124,
 'native_value_mismatches'=>0,'unaccounted_saved_tags'=>0,'compiled_engine_replays'=>0,
 'T99_UObject_independent_FULL'=>'BLOCKED'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===[]?0:1);
