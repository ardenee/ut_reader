<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
$root=dirname(__DIR__);
$checks=[];$fail=[];$drivers=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fail):void{
 $checks[$name]=$ok;if(!$ok)$fail[]=$name;
};
foreach([
 'registration'=>'verify-uedb5-b003-script-registration-source.php',
 'script_sql'=>'verify-uedb5-b003-script-hash-sql-projection.php',
 'mysql_sql'=>'verify-uedb5-b003-script-hash-mysql-isolated.php',
 'runtime_boundary'=>'verify-uedb5-b002-script-import-runtime-boundary.php',
 'name_batch'=>'verify-uedb5-b002-operation-acceptance.php',
 'dependency'=>'verify-uedb5-ue5-zen-dependency-resolution.php'
] as $key=>$filename){
 $out=[];$exit=127;
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/'.$filename).' 2>&1',$out,$exit);
 $decoded=json_decode(implode("\n",$out),true);
 $drivers[$key]=is_array($decoded)?$decoded:[];
 $check('b003_driver_'.$key.'_passes',$exit===0&&($drivers[$key]['ok']??false)===true);
}
$has=static fn(string $name,string $check):bool=>($drivers[$name]['checks'][$check]??null)===true;
$check('b003_finished_registration_and_editor_package_pinned',
 $has('registration','b003_finished_registration_only_no_nonfinished_mutation')
 &&$has('registration','b003_editor_only_package_global_identity')
 &&$has('registration','b003_registration_always_adds_finished_object'));
$check('b003_nested_public_vs_private_and_live_lookup_pinned',
 $has('registration','b003_nested_public_only_and_full_object_path')
 &&$has('registration','b003_runtime_find_is_live_script_hash_map')
 &&$has('registration','b003_unregister_unreachable_lifecycle'));
$check('b003_verse_vni_and_compiled_in_registration_is_separate',
 $has('registration','b003_compiled_in_package_discovery_and_verse_vni_filter')
 &&$has('registration','b003_registration_complete_editor_add_check_only_verify')
 &&$has('registration','b003_separate_verse_vm_cell_registration'));
$check('b003_original_projection_red_fixed_projection_green',
 $has('script_sql','d027_original_projection_silently_dropped_source_hash')
 &&$has('script_sql','d027_fixed_main_optional_and_bundle_preserve_script_key')
 &&$has('script_sql','d027_sql_key_contract_distinct_from_public_export'));
$check('b003_authenticated_script_index_and_bounds',
 $has('script_sql','d027_authentic_cooked_script_hash_index_payload')
 &&$has('script_sql','d027_valid_62bit_boundary_0')
 &&$has('script_sql','d027_valid_62bit_boundary_1')
 &&$has('script_sql','d027_non_62bit_identity_rejected_0')
 &&$has('script_sql','d027_non_62bit_identity_rejected_1'));
$check('b003_mysql_sql_temporary_writer_and_rollback',
 $has('mysql_sql','d027_mysql_session_temp_tables_created')
 &&$has('mysql_sql','d027_mysql_source_hash_round_trip_exact')
 &&$has('mysql_sql','d027_mysql_republish_idempotent')
 &&$has('mysql_sql','d027_mysql_transaction_rollback_preserves_source_hash'));
$check('b003_script_dependency_outcome_not_falsified',
 $has('mysql_sql','d027_mysql_null_provider_and_no_package_key')
 &&$has('registration','b003_staged_export_hash_does_not_fake_live_script_object')
 &&$has('runtime_boundary','b002_script3_sql_unresolved_does_not_claim_package_or_object'));
$check('b003_live_uobject_registry_not_claimed',
 ($drivers['registration']['actual_epic_uobject_registry_executed']??true)===false
 &&($drivers['script_sql']['live_epic_script_registry_executed']??true)===false
 &&($drivers['runtime_boundary']['runtime_global_import_store_not_executed']??false)===true);
echo json_encode(['ok'=>$fail===[],'operation'=>'UE5-583-SCRIPT-004',
 'disposition'=>'partial','checks'=>$checks,'failures'=>$fail,
 'drivers'=>array_map(static fn(array $d):bool=>($d['ok']??false)===true,$drivers),
 'epic_live_registration_executed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
