<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
$root=dirname(__DIR__);$checks=[];$fails=[];$results=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fails):void{
 $checks[$name]=$ok;if(!$ok)$fails[]=$name;
};
foreach([
 'enumeration'=>'verify-uedb5-b015-runtime-script-enumeration-source.php',
 'registration'=>'verify-uedb5-b003-script-registration-source.php',
 'hash'=>'verify-uedb5-b013-operation-acceptance.php',
 'lookup'=>'verify-uedb5-b002-script-import-runtime-boundary.php',
 'diagnostic'=>'verify-uedb5-b003-script-lookup-boundary-acceptance.php'
] as $key=>$driver){
 $lines=[];$code=127;
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/'.$driver).' 2>&1',$lines,$code);
 $out=json_decode(implode("\n",$lines),true);
 $results[$key]=is_array($out)?$out:[];
 $check('b0151_driver_'.$key.'_passes',$code===0&&($results[$key]['ok']??null)===true);
}
$has=static fn(string $driver,string $name):bool=>($results[$driver]['checks'][$name]??null)===true;
$check('b0151_package_enumeration_and_verse_uht_branches_pinned',
 $has('enumeration','b0151_epic_compiledin_upackage_only')
 &&$has('enumeration','b0151_epic_case_sensitive_script_first_verse_vni_second')
 &&$has('enumeration','b0151_epic_verse_vni_uht_only'));
$check('b0151_editor_package_and_public_nested_rules',
 $has('enumeration','b0151_epic_editor_package_registration_or_missing_warning')
 &&$has('enumeration','b0151_epic_recursive_public_only')
 &&$has('enumeration','b0151_editor_has_two_package_keys_three_public_objects'));
$check('b0151_noneditor_check_or_skip_does_not_add_live_objects',
 $has('enumeration','b0151_epic_verify_does_not_repair_missing_initial_registration')
 &&$has('enumeration','b0151_noneditor_check_only_missing_registration_indices')
 &&$has('enumeration','b0151_shipping_no_source_enumeration'));
$check('b0151_finished_registration_and_gc_separate_original',
 $has('enumeration','b0151_epic_finished_event_registration_distinct_from_enumeration')
 &&$has('registration','b003_unregister_unreachable_lifecycle'));
$check('b0151_vm_cells_not_script_uobjects',
 $has('enumeration','b0151_epic_verse_vm_cells_use_separate_registry')
 &&$has('enumeration','b0151_verse_cells_never_equated_to_script_uobjects'));
$check('b0151_native_script_hash_matches_real_satisfactory_record',
 $has('enumeration','b0151_native_typed_script_index_from_authentic_path')
 &&$has('hash','hash_actual_epic_cityhash_98_native_vectors'));
$check('b0151_no_fabricated_live_provider_or_registration',
 $has('enumeration','b0151_offline_dependency_does_not_claim_observed_live_object')
 &&$has('lookup','b002_script3_main_result_is_unresolved_without_false_provider')
 &&($results['enumeration']['epic_live_uobject_registry_executed']??true)===false
 &&($results['enumeration']['external_runtime_observations_supplied']??true)===false);
$check('b0151_errors_do_not_hide_invalid_user_runtime_observation',
 $has('enumeration','b0151_invalid_external_runtime_observations_fail_closed'));
$check('b0151_original_sibling_operations_remain_separate',
 ($results['registration']['actual_epic_uobject_registry_executed']??true)===false
 &&($results['hash']['live_epic_registry_executed']??true)===false
 &&($results['diagnostic']['epic_live_lookup_executed']??true)===false);
echo json_encode(['ok'=>$fails===[],'operation'=>'UE5-583-B015-1',
 'disposition'=>'partial','checks'=>$checks,'failures'=>$fails,
 'drivers'=>array_map(static fn(array $a):bool=>($a['ok']??false)===true,$results),
 'actual_epic_runtime_execution'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fails===[]?0:1);
