#!/usr/bin/env php
<?php
/** One source-current decision. PRD original read-only corpus is source
 * evidence, NOT a native oracle. A separate genuine compiled v432 Core
 * save/load nonempty FString fixture exposes native unconstructed array
 * slots; do not claim loaded values. Prior compiled oracle SHA-reused.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);
$read=static function(string $path):array{
    $bytes=(string)file_get_contents($path);
    if(str_starts_with($bytes,"\xEF\xBB\xBF"))$bytes=substr($bytes,3);
    return json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
};
$m=$read($root.'/catalog/compliance/UE1-O06-G5-UOBJECT-PRD-CORPUS-FSTRING-NATIVE.json');
$tests=0;$failed=[];
$ck=static function(string $key,bool $ok)use(&$tests,&$failed):void{
    $tests++;if(!$ok)$failed[]=$key;
};
$pin=static fn(array $x):bool=>isset($x['path'],$x['sha256'])
    &&is_file($x['path'])&&hash_file('sha256',$x['path'])===$x['sha256'];
foreach($m['sha_pinned_evidence'] as $key=>$file){
    $ck('pinned_PRD_or_compiled_source_'.$key,$pin($file));
}
$c=$m['corpus'];
$ck('verified_prd_total_and_original_map_class_sample',
    $c['verified_prd_ut99_files']===74432
    &&$c['verified_prd_unrealgold_files']===10935
    &&$c['sha_staged_real_u_unr_files']===239
    &&$c['real_exports_examined']===150150
    &&$c['array_property_declarations_found']===6);
$ck('genuine_prd_camera_and_archive_version_sample',
    $c['original_maps_with_camera_names']===90
    &&$c['Camera_exports_read']===437
    &&$c['Camera_stateframes_parsed']===433);
$ck('real_actor_dynamic_tags_absent_in_checked_sample',
    $c['real_map_ordinary_property_exports_parsed']===30000
    &&$c['actual_array_tag_values_in_30000']===0);
$ck('source_only_unmodified_prd_fire_native_tail',
    $c['real_utx_packages_staged']===315
    &&$c['real_procedural_texture_exports']===9189
    &&$c['source_native_firetexture_records_complete']===43
    &&$c['real_nonempty_FireTexture_Sparks_arrays']===37
    &&$c['native_source_FSpark_records']===4573);
$camera=$read($m['sha_pinned_evidence']['prd_real_camera_stateframe_scan']['path']);
$fire=$read($m['sha_pinned_evidence']['prd_native_fire_spark_scan']['path']);
$actors=$read($m['sha_pinned_evidence']['prd_real_ordinary_actor_30000_tags']['path']);
$ck('corpus_not_inferred_from_hardcoded_constants',
    $camera['counts']['stateframe_present']===$c['Camera_stateframes_parsed']
    &&$fire['stats']['complete']===$c['source_native_firetexture_records_complete']
    &&$fire['stats']['sparks_total']===$c['native_source_FSpark_records']
    &&$actors['counts']['inspected']===$c['real_map_ordinary_property_exports_parsed']
    &&$actors['counts']['array_tags']===0);
$source=(string)file_get_contents(
    $m['sha_pinned_evidence']['native_UE1_UArrayProperty_source']['path']);
$template=(string)file_get_contents(
    $m['sha_pinned_evidence']['native_v432_FArray_Add_source']['path']);
$ck('Epic_UArrayProperty_load_uses_uninitialised_Add',
    str_contains($source,'((FArray*)Value)->Add( n, c );')
    &&str_contains($source,'Inner->SerializeItem( Ar, p+i*c );'));
$ck('Epic_UStrProperty_uses_FString_archive',
    str_contains($source,'void UStrProperty::SerializeItem')
    &&str_contains($source,'Ar << *(FString*)Value;'));
$ck('Epic_FArray_Add_and_AddZeroed_distinct',
    str_contains($template,'INT Add( INT Count, INT ElementSize )')
    &&str_contains($template,'INT AddZeroed( INT ElementSize, INT n=1 )')
    &&str_contains($template,'appMemzero( (BYTE*)Data+Index*ElementSize, n*ElementSize );'));
$logRaw=(string)file_get_contents($m['sha_pinned_evidence']['compiled_native_string_CDB_stack']['path']);
$log=str_starts_with($logRaw,"\xFF\xFE")
    ?(string)iconv('UTF-16LE','UTF-8',substr($logRaw,2)):$logRaw;
$ck('compiled_v432_native_load_fault_observed',
    str_contains($log,'Access violation - code c0000005')
    &&str_contains(strtolower($log),'baadf00d')
    &&str_contains($log,'Core.dll'));
$nativeOutput=(string)file_get_contents(
    $m['sha_pinned_evidence']['compiled_native_string_loader_unbuffered_output']['path']);
if(str_starts_with($nativeOutput,"\xFF\xFE"))
    $nativeOutput=(string)iconv('UTF-16LE','UTF-8',substr($nativeOutput,2));
$ck('native_compiled_load_never_claimed_success',
    str_contains($nativeOutput,'G5_NATIVE_DIRECT_PACKAGE_BEGIN')
    &&!str_contains($nativeOutput,'G5_NATIVE_OBJECT_COUNT 1'));
$archive=$m['sha_pinned_evidence']['compiled_native_string_UE1_package']['path'];
$pkg=new P($archive);$r=T::inspect($archive,$pkg,0,'ut99-v432');
$values=[];
foreach($r['tags']??[] as $tag)$values[$tag['name']]=$tag;
$cap=$values['Captions']??[];
$loc=$values['Locations']??[];
$ck('native_compiled_saved_archive_trailer_and_two_tagged_fields',
    ($r['status']??null)==='tag_envelope_decoded'
    &&($r['native_tail_bytes']??null)===0&&count($r['tags']??[])===2);
$ck('actual_native_Core_saved_FString_source_wire_count_and_values',
    ($cap['type']??null)==='ArrayProperty'
    &&($cap['serialized_size']??null)===28
    &&($cap['on_disk_value_bytes']??null)===28
    &&($cap['value']['element_count']??null)===2
    &&($cap['value']['inner_property_kind']??null)==='strproperty'
    &&($cap['value']['preview_first_32'][0]['ascii_text']??null)==='Alpha-string'
    &&($cap['value']['preview_first_32'][1]['ascii_text']??null)==='Beta-string');
$ck('production_clearly_not_native_verified_for_nonempty_FString',
    ($cap['value_kind']??null)==='source_typed_dynamic_array'
    &&($cap['value']['native_loaded_value_parity']??null)==='NOT_VERIFIED'
    &&str_contains((string)($cap['value']['source_runtime_risk']??''),
       'FArray::Add for unconstructed FString'));
$ck('native_source_resolved_vector_unchanged_in_this_saved_fixture',
    ($loc['value']['element_count']??null)===2
    &&($loc['value']['inner_property_kind']??null)==='structproperty'
    &&($loc['on_disk_value_bytes']??null)===25);
$ck('fixture_is_generated_and_not_claimed_original_PRD',
    $m['native_fstring']['fixture_is_generated_by_compiled_v432_not_original_game_asset']===true
    &&$m['native_fstring']['native_Core_SavePackage_success']===true
    &&$m['native_fstring']['native_Core_LoadPackage_success']===false
    &&$m['native_fstring']['no_universal_failure_claim']===true);
$events=array_map(static fn($line)=>json_decode($line,true),
    array_filter(explode("\n",(string)file_get_contents(
        $root.'/catalog/compliance/UE1-O06-TEST-EVENTS.jsonl'))));
$old=null;foreach($events as $e){
    if(($e['test']??null)==='verify-ue1-o06-g5-uobject-nested-vector-decision.php'
       &&($e['state']??null)==='PASS')$old=$e;
}
$oldPath=$old?$root.'/'.str_replace('\\','/',$old['log']):null;
$oldData=$oldPath&&is_file($oldPath)?$read($oldPath):[];
$ck('previous_native_vector_source_scoped_SHA_only',
    $old!==null
    &&hash_file('sha256',$oldPath)===$old['output_sha256']
    &&hash_file('sha256',$root.'/catalog/bin/verify-ue1-o06-g5-uobject-nested-vector-decision.php')
      ===$old['test_sha256']
    &&($oldData['ok']??null)===true
    &&($oldData['checks']??null)===36
    &&($oldData['real_compiled_native_nested_struct_cases_matched']??null)===3
    &&($oldData['previous_six_dynamic_array_cases_SHA_reused']??null)===6
    &&($oldData['previous_genuine_original_TaggedValues_SHA_reused']??null)===113);
$ck('full_method_still_blocked',$m['conclusion']==='BLOCKED');
echo json_encode(['ok'=>$failed===[],'checks'=>$tests,
    'passed'=>$tests-count($failed),'failures'=>$failed,
    'method'=>'T99:UObject::Serialize',
    'prd_original_staged_u_unr'=>239,
    'prd_original_camera_StateFrames_static_decoded'=>433,
    'prd_source_native_FSpark_records_decoded'=>4573,
    'real_v432_saved_nonempty_FString_elements_source_decoded'=>2,
    'compiled_v432_nonempty_string_load_status'=>'native_access_violation',
    'compiled_Core_native_load_success'=>false,
    'current_production_string_native_loaded_value_parity'=>'NOT_VERIFIED',
    'previous_compiled_native_nested_vector_cases_SHA_reused'=>3,
    'previous_113_genuine_Entry_native_tagged_values_SHA_reused'=>113,
    'compiled_native_reruns_in_acceptance'=>0,
    'full_method_decision'=>'BLOCKED'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===[]?0:1);
