<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
$root=dirname(__DIR__);$checks=[];$fail=[];$results=[];
$check=static function(string $n,bool $ok)use(&$checks,&$fail):void{
 $checks[$n]=$ok;if(!$ok)$fail[]=$n;
};
foreach([
 'source'=>'verify-uedb5-b002-name-batch-source.php',
 'authentic'=>'verify-uedb5-b001-authentic-scriptobjects.php',
 'b001'=>'verify-uedb5-b001-operation-acceptance.php',
 'standalone'=>'verify-uedb5-zen-script-objects-standalone.php',
 'zen'=>'verify-uedb5-ue5-zen-package.php',
] as $key=>$script){
 $out=[];$exit=127;
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/'.$script).' 2>&1',$out,$exit);
 $parsed=json_decode(implode("\n",$out),true);
 $results[$key]=is_array($parsed)?$parsed:[];
 $check('b002_driver_'.$key.'_passes',$exit===0&&($results[$key]['ok']??false)===true);
}
$has=static fn(string $k,string $c):bool=>($results[$k]['checks'][$c]??null)===true;
$check('b002_exact_epic_batch_structure_and_zero_count',
 $has('source','b002_epic_zero_names_returns_without_header')
 &&$has('source','b002_epic_wire_count_string_bytes_hash_and_arrays')
 &&$has('source','b002_epic_header_utf16_and_15bit_length'));
$check('b002_input_and_shard_order_mapping',
 $has('source','b002_epic_saved_hash_rehash_or_input_shard_branch')
 &&$has('source','b002_epic_cooked_loader_preserves_name_map_order')
 &&$has('source','b002_unrealdb_name_batch_preserves_index_and_original_hash'));
$check('b002_utf8_utf16_and_negative_bounds',
 $has('source','b002_utf8_utf16_names_are_ordered_and_roundtrip')
 &&$has('source','b002_zero_names_consume_only_count')
 &&$has('source','b002_truncated_utf16_still_rejected')
 &&$has('source','b002_declared_name_bytes_outside_available_rejected'));
$check('b002_actual_cooked_name_map',
 $has('source','b002_real_50640_name_map_has_54968_serialized_consumers')
 &&$has('source','b002_real_first_record_mapped_name_index_and_display')
 &&$has('authentic','b001_real_all_54968_names_are_global_type_2'));
$check('b002_exact_one_name_batch_caller_in_production_inventory',
 $has('source','b002_complete_source_files_and_one_script_diagnostic_caller')
 &&($results['source']['caller_count']??null)===1
 &&($results['source']['source_files_scanned']??0)>=500);
$check('b002_diagnostic_names_not_runtime_objects',
 $has('authentic','b001_real_reader_never_claims_live_registration')
 &&($results['source']['epic_runtime_FNamePool_executed']??true)===false
 &&($results['b001']['live_epic_runtime_executed']??true)===false);
echo json_encode(['ok'=>$fail===[],'operation'=>'UE5-583-SCRIPT-002','checks'=>$checks,'failures'=>$fail,
 'drivers'=>array_map(static fn(array $r):bool=>($r['ok']??false)===true,$results),
 'scope'=>'Offline cooked global NameBatch decoding, not FNamePool live intern/registration',
 'epic_fname_pool_executed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
