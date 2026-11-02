<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
$root=dirname(__DIR__);
$checks=[];$failures=[];$results=[];
$check=static function(string $id,bool $ok)use(&$checks,&$failures):void{
 $checks[$id]=$ok;if(!$ok)$failures[]=$id;
};
foreach([
 'layout'=>'verify-uedb5-b001-script-objects-source.php',
 'mapped'=>'verify-uedb5-b001-mapped-name-source.php',
 'authentic'=>'verify-uedb5-b001-authentic-scriptobjects.php',
 'standalone'=>'verify-uedb5-zen-script-objects-standalone.php',
] as $key=>$script){
 $out=[];$exit=127;
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/'.$script).' 2>&1',$out,$exit);
 $json=json_decode(implode("\n",$out),true);
 $results[$key]=is_array($json)?$json:[];
 $check('b001_driver_'.$key.'_passes',$exit===0&&($results[$key]['ok']??false)===true);
}
$has=static fn(string $driver,string $name):bool=>($results[$driver]['checks'][$name]??null)===true;
$check('b001_cooked_reader_trailing_byte_source_parity',
 $has('layout','b001_epic_no_end_of_chunk_exact_gate')
 &&$has('layout','b001_negative_before_positive_after_trailing_bytes')
 &&$has('layout','b001_truncated_record_still_rejected'));
$check('b001_all_mapped_name_kinds_and_global_index_parity',
 $has('mapped','b001_mapped_epic_all_three_name_types')
 &&$has('mapped','b001_mapped_epic_index_only_with_no_type_gate')
 &&$has('mapped','b001_mapped_type_0_uses_index_not_type')
 &&$has('mapped','b001_mapped_type_1_uses_index_not_type')
 &&$has('mapped','b001_mapped_type_2_uses_index_not_type'));
$check('b001_source_backed_last_duplicate_wins',
 $has('mapped','b001_mapped_epic_map_emplace_replaces_duplicate')
 &&$has('mapped','b001_mapped_duplicate_last_emplace_wins')
 &&$has('mapped','b001_mapped_original_rejected_replaceable_duplicate'));
$check('b001_real_cooked_chunk_rejects_historical_reader',
 $has('authentic','b001_real_historical_pre_fix_fails_on_actual_type_2')
 &&$has('authentic','b001_real_all_54968_names_are_global_type_2')
 &&$has('authentic','b001_real_reader_exact_cooked_counts')
 &&$has('authentic','b001_real_script_chunk_sha_and_length'));
$check('b001_real_toc_present_and_absent_paths',
 $has('authentic','b001_real_single_global_script_chunk_key')
 &&$has('authentic','b001_real_package_toc_without_script_chunk_is_absent')
 &&$has('authentic','b001_real_name_indices_in_range_no_duplicate'));
$check('b001_invalid_script_record_safety_preserved',
 ($results['standalone']['rejected_invalid']??0)===3
 &&$has('mapped','b001_mapped_out_of_range_index_still_rejected')
 &&$has('authentic','b001_real_reader_first_names_and_source_identity'));
$check('b001_complete_production_callsite_boundary',
 $has('mapped','b001_mapped_complete_production_php_inventory')
 &&$has('mapped','b001_mapped_no_production_runtime_consumer_is_fabricated')
 &&$has('mapped','b001_mapped_cooked_debug_is_optional_not_live_registration'));
$check('b001_ue5_runtime_registry_not_asserted',
 $has('authentic','b001_real_reader_never_claims_live_registration')
 &&$has('layout','b001_cooked_chunk_never_proves_live_runtime')
 &&($results['authentic']['live_epic_engine_executed']??null)===false);
echo json_encode(['ok'=>$failures===[],'operation'=>'UE5-583-SCRIPT-001',
 'checks'=>$checks,'failures'=>$failures,
 'drivers'=>array_map(static fn(array $r):array=>['ok'=>$r['ok']??false,'checks'=>count((array)($r['checks']??[]))],$results),
 'scope'=>'Source-defined cooked ScriptObjects chunk parser and diagnostics only; Epic loader/registration runtime not executed',
 'live_epic_runtime_executed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
