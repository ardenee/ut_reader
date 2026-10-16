<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
$root=dirname(__DIR__);$checks=[];$fails=[];$drivers=[];$reports=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fails):void{
 $checks[$name]=$ok;if(!$ok)$fails[]=$name;
};
foreach([
 'source'=>'verify-uedb5-b015-recursive-imports.php',
 'historical'=>'verify-uedb5-b015-recursive-before-after.php',
 'backend'=>'verify-uedb5-b005-operation-acceptance.php',
 'identity'=>'verify-uedb5-b008-operation-acceptance.php',
 'names'=>'verify-uedb5-b011-operation-acceptance.php',
 'optional'=>'verify-uedb5-b012-operation-acceptance.php',
 'public'=>'verify-uedb5-b007-operation-acceptance.php',
 'cell'=>'verify-uedb5-b016-operation-acceptance.php',
 'rebuilder'=>'verify-uedb5-dependency-rebuilder.php',
 'publisher'=>'verify-uedb5-dependency-projection-publisher.php',
 'callers'=>'verify-uedb5-zen-backend-callers.php'
] as $key=>$script){
 $out=[];$exit=127;
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/'.$script).' 2>&1',$out,$exit);
 $json=json_decode(implode("\n",$out),true);
 $reports[$key]=is_array($json)?$json:[];
 $drivers[$key]=['exit'=>$exit,'ok'=>$reports[$key]['ok']??false];
 $check('b0152_driver_'.$key.'_passes',$exit===0&&($reports[$key]['ok']??false)===true);
}
$has=static fn(string $driver,string $check):bool=>($reports[$driver]['checks'][$check]??null)===true;
$check('b0152_epic_source_enumeration_and_order',
 $has('source','b0152_pinned_epic_source')
 &&$has('source','b0152_every_package_id_enumerated')
 &&$has('source','b0152_epic_redirect_instancing_localization_order')
 &&$has('source','b0152_existing_package_lookup_precedes_store'));
$check('b0152_original_defect_reproducible',
 $has('historical','baseline_drops_ordinary_imported_package_ids')
 &&$has('historical','corrected_emits_main_package_without_object_imports')
 &&$has('historical','corrected_emits_optional_package_without_object_imports'));
$check('b0152_live_runtime_status_not_guessed',
 $has('source','b0152_existing_load_context_runtime_not_fabricated')
 &&$has('source','b0152_not_installed_does_not_invent_unloaded_state')
 &&$has('source','b0152_pending_kept_unresolved')
 &&$has('source','b0152_no_backend_status_never_claims_loaded_or_missing'));
$check('b0152_main_optional_and_redirect_identity',
 $has('source','b0152_editor_optional_separate_source_row')
 &&$has('source','b0152_noneditor_optional_not_loaded')
 &&$has('source','b0152_redirect_precedes_evidenced_provider')
 &&$has('source','b0152_identity_remap_before_backend_lookup'));
$check('b0152_compressed_v5_sql_republished',
 $has('source','b0152_real_compressed_v5_roundtrip')
 &&$has('source','b0152_real_isolated_sql_persists_package_source_kinds')
 &&$has('source','b0152_real_isolated_sql_separates_optional_and_main'));
$check('b0152_downstream_existing_acceptance_unbroken',
 ($drivers['backend']['ok']??false)===true
 &&($drivers['identity']['ok']??false)===true
 &&($drivers['optional']['ok']??false)===true
 &&($drivers['rebuilder']['ok']??false)===true
 &&($drivers['publisher']['ok']??false)===true);
echo json_encode(['ok'=>$fails===[],'operation'=>'UE5-583-B015-2',
 'scope'=>'offline imported-package requirements, not async scheduling/runtime loading',
 'checks'=>$checks,'drivers'=>$drivers,'failures'=>$fails,
 'live_epic_scheduler_executed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fails===[]?0:1);
