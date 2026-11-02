<?php
declare(strict_types=1);
/** B008 original import-redirect operation acceptance; no editor binary. */
if(PHP_SAPI!=='cli')exit(2);
$root=dirname(__DIR__);$reports=[];$drivers=[];$checks=[];$failed=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failed):void{
 $checks[$name]=$ok;if(!$ok)$failed[]=$name;
};
foreach([
 'source'=>'verify-uedb5-b008-import-redirect-source.php',
 'resolver'=>'verify-uedb5-ue5-zen-dependency-resolution.php',
 'mounted'=>'verify-uedb5-b006-operation-acceptance.php',
 'backend'=>'verify-uedb5-b005-operation-acceptance.php',
 'rebuild'=>'verify-uedb5-dependency-rebuilder.php',
 'sql'=>'verify-uedb5-dependency-sql-isolated.php',
 'options'=>'verify-uedb5-zen-context-options-standalone.php'
] as $key=>$filename){
 $output=[];$exit=127;
 // Only the backend-status MySQL fixture belongs to B008; DEV does not
 // contain PRD's unrelated seven retained V5 snapshot files.
 $sqlArgument=$key==='sql'?' '.escapeshellarg('--backend-status-only'):'';
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/'.$filename).$sqlArgument.' 2>&1',$output,$exit);
 $j=json_decode(implode("\n",$output),true);
 $reports[$key]=is_array($j)?$j:[];
 $drivers[$key]=['ok'=>$reports[$key]['ok']??false,'exit'=>$exit];
 $check('b008_driver_'.$key.'_passes',$exit===0&&($reports[$key]['ok']??false)===true);
}
$has=static fn(string $driver,string $case):bool=>
 ($reports[$driver]['checks'][$case]??false)===true;
$check('b008_pinned_epic_ordering_and_cook_context',
 $has('source','b008_pinned_async_sha256')
 &&$has('source','b008_pinned_store_sha256')
 &&$has('source','b008_pinned_file_sha256')
 &&$has('source','b008_rewrite_phase_order_core_instance_loose_store_load')
 &&$has('source','b008_loose_localization_requires_runtime_and_loose_mode'));
$check('b008_complete_global_package_store_maps_required',
 $has('resolver','b008_authority_flag_without_effective_global_store_maps_fails_closed')
 &&$has('resolver','zen_single_container_redirect_is_evidence_not_global_winner')
 &&$has('resolver','zen_absent_local_redirect_rows_do_not_prove_global_package_store_absence'));
$check('b008_complete_pre_store_identity_result_required',
 $has('resolver','b008_authority_flag_without_explicit_identity_result_fails_closed')
 &&$has('resolver','zen_package_import_requires_authoritative_core_redirect_instancing_localization_context')
 &&$has('resolver','zen_authoritative_pre_store_identity_rewrite_preserves_serialized_source_identity'));
$check('b008_explicit_redirect_and_name_remap_order',
 $has('resolver','zen_package_store_redirect_resolves_public_hash_in_target_provider')
 &&$has('resolver','zen_explicit_package_store_redirect_precedes_localization_mapping')
 &&$has('resolver','zen_authoritative_package_store_redirect_first_effective_order_entry_wins'));
$check('b008_source_none_refill_and_unknown_name_distinguished',
 $has('resolver','b008_epic_none_redirect_filled_by_later_valid_global_entry')
 &&$has('resolver','b008_missing_name_not_assumed_none_in_global_map')
 &&$has('source','b008_exhaustive_production_resolver_callsite_inventory'));
$check('b008_localization_requires_real_culture_and_runtime_mode',
 $has('resolver','zen_authoritative_localization_still_requires_runtime_culture_outside_known_editor_context')
 &&$has('resolver','zen_editor_context_skips_file_package_store_localization_redirect')
 &&$has('source','b008_file_localization_non_editor_and_installed_only'));
$check('b008_backend_arbitration_terminal_results_reach_resolver',
 $has('resolver','zen_pending_package_store_backend_does_not_publish_false_missing_or_resolved')
 &&$has('resolver','zen_backend_ok_without_staged_provider_remains_unresolved')
 &&$has('resolver','zen_backend_ok_rejects_mismatching_selected_physical_file'));
$check('b008_game_lifecycle_entrypoints_and_sql_persistence',
 $has('source','b008_exhaustive_production_resolver_callsite_inventory')
 &&$has('source','b008_game_source_requires_evidence_origin_for_authority')
 &&($reports['options']['unsupported_authority_rejected']??null)===true
 &&isset($reports['options']['backend_merged_context']));
echo json_encode(['ok'=>$failed===[],
 'operation'=>'UE5-583-IMPORT-REDIRECT-001','checks'=>$checks,
 'drivers'=>$drivers,'failures'=>$failed,
 'runtime_editor_or_culture_inferred'=>false,'source_derived'=>true
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
