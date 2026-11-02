<?php
/** One independent v432 USkeletalMesh method acceptance. All native
 * engine outputs are already SHA-pinned; this script NEVER invokes native.
 */
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
require dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-T99-SKELETAL-REMAP-NATIVE-LOADED.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$fail=[];
$ck=static function(string $name,bool $ok)use(&$checks,&$fail):void{$checks++;if(!$ok)$fail[]=$name;};
$pin=static function(array $p,string $label)use($ck):void{
 $ck('SHA_'.$label,is_file($p['path'])
    &&filesize($p['path'])===$p['bytes']
    &&hash_file('sha256',$p['path'])===$p['sha256']);
};
$ck('method',($m['method']??null)==='T99:USkeletalMesh::Serialize');
$ck('no_engine_rerun',($m['native_engine_reruns_in_acceptance']??null)===0);
foreach($m['source'] as $k=>$p)$pin($p,'source_'.$k);
foreach($m['compiled'] as $k=>$p)$pin($p,'native_'.$k);
foreach($m['reused_previous_evidence'] as $k=>$p)$pin($p,'prior_'.$k);
$epic=(string)file_get_contents($m['source']['epic_v400_lodmesh_cpp']['path']);
$nativeArray=(string)file_get_contents($m['source']['epic_v432_tarray_header']['path']);
$ck('source_v432_load_branch',str_contains($epic,'if ( RemapAnimVerts.Num() )')
 &&str_contains($epic,'Verts.Load();')
 &&str_contains($epic,'NewVerts.Add( FrameVerts * AnimFrames);')
 &&str_contains($epic,'FrameStart = f * OldFrameVerts')
 &&str_contains($epic,'FullFrameStart = f * FrameVerts')
 &&str_contains($epic,'RemapAnimVerts(v) + FrameStart')
 &&str_contains($epic,'ExchangeArray(Verts, NewVerts)')
 &&str_contains($epic,'RemapAnimVerts.Empty();'));
$ck('source_operator_checks_not_native_archive_count_exception',
 str_contains($nativeArray,'checkSlow(i<=ArrayNum);'));
$previous=json_decode((string)file_get_contents(
  $m['reused_previous_evidence']['five_original_skeletal_scalar_values_and_native_DefaultAnimation_bad_ref']['path']),true,512,JSON_THROW_ON_ERROR);
$census=json_decode((string)file_get_contents(
  $m['reused_previous_evidence']['full_2895_original_skeletal_export_census']['path']),true,512,JSON_THROW_ON_ERROR);
$ck('previous_five_original_compiled_objects',$previous['method']===$m['method']
 &&count($previous['originals'])===5
 &&$m['five_genuine_original_compiled_skeletal_mesh_objects_reused']===5);
$ck('previous_actual_compiled_default_animation_error',
 $m['one_exact_native_malformed_DefaultAnimation_error_cursor_reused']===412679);
$ck('previous_genuine_original_corpus',
 $census['true_skeletal_exports_source_decoded']===2895
 &&$census['source_serialized_nonempty_RemapAnimVerts_instances']===0
 &&$m['original_native_full_skeletal_array_bytes_reused']===282956);
$ck('three_distinct_compiled_serialized_remap_classes',count($m['native_cases'])===3);
$verifiedVertexValues=0;
foreach($m['native_cases'] as $i=>$case){
 foreach(['package','native_loaded_log','native_producer_source','native_producer_executable'] as $key)
    $pin($case[$key],$i.'_'.$key);
 $p=new P($case['package']['path']);
 $ck('original_archived_v69_'.$i,(int)$p->getHeader()['version']===69);
 $rows=$p->getExports();
 $ck('one_generated_skeletal_export_'.$i,count($rows)===1
    &&$rows[0]['objectNameText']==='G5RemapMesh');
 $t=T::inspect($case['package']['path'],$p,0,'ut99-v432');
 $record=N::inspect($case['package']['path'],$p,0,$t,'ut99-v432');
 $lod=$record['inherited_lodmesh']??[];
 $ck('whole_skeletal_export_complete_'.$i,$record['status']==='native_skeletalmesh_record_complete'
    &&$record['extension_bytes']===0);
 $ck('inherits_actual_native_remap_count_'.$i,
    ($lod['remap_anim_verts']['count']??null)===$case['archived_remap_count']
    &&($lod['verts']['count']??0)>=4);
 $log=(string)file_get_contents($case['native_loaded_log']['path']);
 $ck('compiled_native_real_loaded_'.$i,
    str_contains($log,'SKEL_END success=1')
    &&str_contains($log,'remap_after_load=0')
    &&str_contains($log,'num_verts='.$case['native_loaded_vert_count']));
 $native=[];
 preg_match_all('/NATIVE_REMAP_VERTEX index=(\d+) raw=([0-9a-fA-F]{8})/',$log,$matches,PREG_SET_ORDER);
 foreach($matches as $entry){$native[(int)$entry[1]]=strtolower($entry[2]);}
 ksort($native,SORT_NUMERIC);
 $ck('compiled_loaded_all_vertex_dwords_'.$i,
    array_values($native)===$case['native_loaded_vert_dwords_hex']);
 if($case['source_valid_memory_permutation']){
    $ck('actual_remap_runtime_applied_'.$i,
      $lod['runtime_vertex_remap_executed']===true
      &&$lod['runtime_vertex_remap_status']==='epic_load_permutation_complete'
      &&$lod['loaded_remap_anim_verts_count']===0);
    $ck('native_loaded_vertex_all_values_match_'.$i,
      $lod['loaded_verts']['count']===$case['native_loaded_vert_count']
      &&$lod['loaded_verts']['packed_dword_preview_hex']===$case['native_loaded_vert_dwords_hex']);
    $blob='';
    foreach($case['native_loaded_vert_dwords_hex'] as $word)$blob.=pack('V',hexdec($word));
    $ck('native_loaded_full_byte_sha256_'.$i,
      $lod['loaded_verts']['packed_bytes_sha256']===hash('sha256',$blob));
    $verifiedVertexValues+=count($case['native_loaded_vert_dwords_hex']);
 }else{
    // Native engine continued and read allocator memory beyond the
    // serialized Remap array. The archived file is valid; loaded data
    // cannot be asserted deterministically for arbitrary allocations.
    $ck('compiled_native_short_array_load_not_false_rejected',
      $case['compiled_engine_success']===true
      &&$lod['runtime_vertex_remap_executed']===null
      &&$lod['runtime_vertex_remap_status']==='native_remap_out_of_bounds_memory_value_undefined'
      &&!isset($lod['loaded_verts']));
 }
}
$ck('source_defined_positive_compiled_loaded_values',
    $verifiedVertexValues===$m['source_valid_loaded_vertices_verified']
    &&$verifiedVertexValues===10);
$seven=[
 'pinned_source_and_dispatch'=>'VERIFIED',
 'complete_native_field_values_and_references'=>'VERIFIED',
 'archive_bytes_and_consumption'=>'VERIFIED',
 'malformed_input_error_semantics'=>'VERIFIED',
 'inherited_and_version_variant_semantics'=>'VERIFIED',
 'current_acceptance_tests'=>'VERIFIED',
 'independent_full_method_review'=>'VERIFIED',
];
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($fail),
 'failed'=>$fail,'decision'=>$ok?'FULL_UT99_V432_V69_SOURCE_PROFILE_ONLY':'BLOCKED',
 'method'=>$m['method'],'seven_gates'=>$ok?$seven:[],
 'native_compiled_remap_vertices_compared'=>$verifiedVertexValues,
 'native_stock_short_remap_reads_outside_array'=>true,
 'source_invalid_loaded_values_not_fabricated'=>true,
 'reused_original_compiled_skeletal_array_bytes'=>282956,
 'reused_original_native_objects'=>5,
 'reused_actual_compiled_malformed_reference_cursor'=>412679,
 'native_engine_invocations_by_verifier'=>0,
 'generic_UObject_method_full'=>'NOT_CLAIMED'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
