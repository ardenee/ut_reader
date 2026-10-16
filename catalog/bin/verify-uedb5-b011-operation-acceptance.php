<?php
declare(strict_types=1);
/** Original B011 independent source-to-V5 operation acceptance. */
if(PHP_SAPI!=='cli')exit(2);
$root=dirname(__DIR__);$checks=[];$fail=[];$drivers=[];$reports=[];
$check=static function(string $id,bool $ok)use(&$checks,&$fail):void{
 $checks[$id]=$ok;if(!$ok)$fail[]=$id;
};
foreach([
 'source'=>'verify-uedb5-b011-imported-name-source.php',
 'package'=>'verify-uedb5-ue5-zen-package.php',
 'dependency'=>'verify-uedb5-ue5-zen-dependency-resolution.php',
] as $key=>$filename){
 $o=[];$exit=127;
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/'.$filename).' 2>&1',$o,$exit);
 $j=json_decode(implode("\n",$o),true);$reports[$key]=is_array($j)?$j:[];
 $drivers[$key]=['ok'=>$reports[$key]['ok']??false,'exit'=>$exit];
 $check('b011_driver_'.$key.'_passes',$exit===0&&($reports[$key]['ok']??false)===true);
}
$has=static fn(string $driver,string $name):bool=>
 ($reports[$driver]['checks'][$name]??false)===true;
$check('b011_epic_serializer_and_header_boundary_pinned',
 $has('source','b011_pinned_loader_source')
 &&$has('source','b011_pinned_zen_header_source')
 &&$has('source','b011_source_reads_name_batch_first')
 &&$has('source','b011_source_reads_separate_signed_number_per_name')
 &&$has('source','b011_source_reader_view_continues_to_header_size')
 &&$has('source','b011_source_no_trailing_consumption_assertion'));
$check('b011_independent_name_number_wire_fixtures',
 $has('source','b011_case_one_number_zero')
 &&$has('source','b011_case_one_number_three')
 &&$has('source','b011_case_two_names')
 &&$has('source','b011_utf16_batch_and_number')
 &&$has('source','b011_case_zero_names_with_padding'));
$check('b011_trailing_and_invalid_boundaries',
 $has('source','b011_case_trailing_header_padding')
 &&$has('source','b011_truncated_number_rejected')
 &&$has('source','b011_out_of_bounds_span_rejected')
 &&$has('source','b011_no_unsourced_trailing_bytes_restriction'));
$check('b011_source_to_production_caller_inventory',
 $has('source','b011_direct_reader_call_and_definition_accounted')
 &&$has('source','b011_main_optional_builder_preserves_imported_names'));
$check('b011_synthetic_iostore_and_v5_preserve_provider_imports',
 $has('package','zen_imported_package_names_parsed')
 &&$has('package','b011_non_consumed_header_suffix_does_not_hide_imports')
 &&$has('package','zen_snapshot_uedb5_package_import_round_trip')
 &&$has('package','zen_snapshot_optional_header_preserved_separately'));
$check('b011_downstream_dependency_identity_preserved',
 $has('dependency','zen_dependency_package_import_resolved_by_id_hash')
 &&$has('dependency','zen_package_import_requires_authoritative_core_redirect_instancing_localization_context'));
echo json_encode(['ok'=>$fail===[],
 'operation'=>'UE5-583-IMPORTED-PACKAGE-NAMES-001',
 'checks'=>$checks,'drivers'=>$drivers,'failures'=>$fail,
 'real_editor_binary_executed'=>false,'production_sql_writes'=>false
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
