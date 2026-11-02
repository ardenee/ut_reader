<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
$root=dirname(__DIR__);
$checks=[];$failures=[];$drivers=[];
$check=static function(string $name,bool $pass)use(&$checks,&$failures):void {
 $checks[$name]=$pass;if(!$pass)$failures[]=$name;
};
foreach([
 'lookup_preview'=>'verify-uedb5-b003-script-diagnostic-path-source.php',
 'reader'=>'verify-uedb5-b001-operation-acceptance.php',
 'runtime_boundary'=>'verify-uedb5-b002-script-import-runtime-boundary.php',
 'registration'=>'verify-uedb5-b003-script-registration-boundary-acceptance.php'
] as $name=>$script){
 $out=[];$exit=127;
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/'.$script).' 2>&1',$out,$exit);
 $decoded=json_decode(implode("\n",$out),true);
 $drivers[$name]=is_array($decoded)?$decoded:[];
 $check('script5_driver_'.$name.'_passes',$exit===0 &&($drivers[$name]['ok']??false)===true);
}
$has=static fn(string $name,string $key):bool=>($drivers[$name]['checks'][$key]??null)===true;
$check('script5_epic_live_registry_first_then_diagnostic_only',
 $has('lookup_preview','script5_live_script_lookup_is_only_findref')
 &&$has('lookup_preview','script5_get_import_object_separates_script_and_package_paths')
 &&$has('lookup_preview','script5_importname_prefers_live_object_name_then_optional_fallback'));
$check('script5_compilation_and_precdo_debug_gate',
 $has('lookup_preview','script5_optional_precdo_flag_controls_chunk_loading')
 &&$has('lookup_preview','script5_trim_only_when_compiled_diagnostics_enabled'));
$check('script5_trimmed_outer_chain_preserves_names',
 $has('lookup_preview','script5_trim_marks_registered_but_preserves_outers')
 &&$has('lookup_preview','script5_registered_outer_retained_for_missing_nested')
 &&$has('lookup_preview','script5_all_known_runtime_indices_leave_no_diagnostics'));
$check('script5_source_delimiters_and_invalid_input',
 $has('lookup_preview','script5_names_use_dot_colon_question_for_descendant_depth')
 &&$has('lookup_preview','script5_full_depth_source_delimiters')
 &&$has('lookup_preview','script5_reject_invalid_identity')
 &&$has('lookup_preview','script5_bounded_outer_cycle_never_hangs'));
$check('script5_authentic_54968_entry_identity_and_exact_nested_path',
 $has('lookup_preview','script5_authentic_54968_scripts_pinned')
 &&$has('lookup_preview','script5_authentic_getrecipes_has_nonempty_diagnostic_path')
 &&($drivers['lookup_preview']['sample']['preview_name']??null)==='/Script/FactoryGame.FGRecipeUnlockedDependency:GetRecipes');
$check('script5_dependency_provider_not_fabricated',
 $has('runtime_boundary','b002_script3_main_result_is_unresolved_without_false_provider')
 &&$has('runtime_boundary','b002_script3_sql_unresolved_does_not_claim_package_or_object')
 &&$has('registration','b003_script_dependency_outcome_not_falsified'));
$check('script5_debug_never_proves_live_object',
 ($drivers['lookup_preview']['live_epic_runtime_executed']??true)===false
 &&($drivers['lookup_preview']['diagnostic_preview_only']??false)===true
 &&($drivers['registration']['epic_live_registration_executed']??true)===false);
echo json_encode(['ok'=>$failures===[],'original_operation'=>'UE5-583-SCRIPT-005',
 'disposition'=>'partial','checks'=>$checks,'failures'=>$failures,
 'drivers'=>array_map(static fn(array $v):bool=>($v['ok']??false)===true,$drivers),
 'epic_live_lookup_executed'=>false,'diagnostic_preview_only'=>true],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
