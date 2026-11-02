<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
$root=dirname(__DIR__);$checks=[];$fail=[];$reports=[];$drivers=[];
$check=static function(string $name,bool $pass)use(&$checks,&$fail):void{
 $checks[$name]=$pass;if(!$pass)$fail[]=$name;
};
foreach([
 'source'=>'verify-uedb5-b007-public-export-source.php',
 'resolver'=>'verify-uedb5-ue5-zen-dependency-resolution.php',
 'v5'=>'verify-uedb5-b007-v5-roundtrip.php',
 'compact'=>'verify-uedb5-zen-compact-reason-db.php',
 'backend'=>'verify-uedb5-b005-operation-acceptance.php',
 'optional'=>'verify-uedb5-b012-operation-acceptance.php',
 'identity'=>'verify-uedb5-b008-operation-acceptance.php',
 'rebuilder'=>'verify-uedb5-dependency-rebuilder.php',
 'sql'=>'verify-uedb5-dependency-sql-isolated.php',
] as $label=>$filename){
 $out=[];$exit=127;
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/'.$filename);
 if($label==='sql')$cmd.=' '.escapeshellarg('--backend-status-only');
 exec($cmd.' 2>&1',$out,$exit);
 $json=json_decode(implode("\n",$out),true);
 $reports[$label]=is_array($json)?$json:[];
 $drivers[$label]=['ok'=>$reports[$label]['ok']??false,'exit'=>$exit];
 $check('b007_driver_'.$label.'_passes',$exit===0&&($reports[$label]['ok']??false)===true);
}
$has=static fn(string $label,string $name):bool=>($reports[$label]['checks'][$name]??false)===true;
$check('b007_pinned_makekey_import_identity',
 $has('source','b007_epic_cpp_pinned')
 &&$has('source','b007_epic_public_header_pinned')
 &&$has('source','b007_key_equality_requires_both_fields')
 &&$has('source','b007_runtime_first_package_id_then_hash'));
$check('b007_invalid_zero_hash_or_package_rejected',
 $has('source','b007_invalid_zero_import_hash_rejected_like_epic')
 &&$has('source','b007_import_zero_fpackageid_rejected_like_epic')
 &&$has('source','b007_provider_zero_fpackageid_rejected_like_epic'));
$check('b007_duplicate_header_first_vs_live_overwrite',
 $has('source','b007_header_main_before_optional')
 &&$has('source','b007_global_hash_collision_replaces_existing')
 &&$has('source','b007_source_header_first_index_provenance_not_runtime_resolution')
 &&$has('resolver','zen_dependency_duplicate_public_hash_is_runtime_collision_ambiguous'));
$check('b007_exact_package_and_hash_and_provider_required',
 $has('source','b007_same_hash_wrong_package_does_not_resolve')
 &&$has('source','b007_exact_provider_and_hash_resolves')
 &&$has('resolver','zen_dependency_package_import_resolved_by_id_hash')
 &&$has('resolver','zen_dependency_selected_export_provenance')
 &&$has('resolver','zen_backend_ok_rejects_mismatching_selected_physical_file'));
$check('b007_source_editor_and_filter_branches',
 $has('resolver','zen_noneditor_object_redirector_is_returned_without_destination_follow')
 &&$has('resolver','zen_editor_object_redirector_requires_destination_payload')
 &&$has('resolver','zen_dependency_filtered_export_requires_runtime_state')
 &&$has('resolver','zen_editor_import_searches_optional_provider_exports_after_main_exports'));
$check('b007_real_v5_snapshot_preserves_collision_provenance',
 $has('v5','b007_writes_real_verified_v5_container')
 &&$has('v5','b007_v5_preserves_collision_provenance')
 &&$has('v5','b007_v5_preserves_exact_key_and_unresolved'));
$check('b007_compact_reader_preserves_unresolved_reasons',
 $has('compact','compact_facade_preserves_distinct_reasons')
 &&$has('compact','source_shaped_reader_retains_two_reasons'));
$check('b007_production_caller_inventory_and_downstream',
 $has('source','b007_complete_production_resolver_caller_inventory')
 &&$has('source','b007_new_unregistered_callsite_fails_inventory')
 &&$has('resolver','zen_dependency_cell_import_rejects_ordinary_export_hash_match'));
echo json_encode(['ok'=>$fail===[],
 'operation'=>'UE5-583-PUBLIC-EXPORT-001','checks'=>$checks,
 'drivers'=>$drivers,'failures'=>$fail,
 'live_global_import_store_executed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
