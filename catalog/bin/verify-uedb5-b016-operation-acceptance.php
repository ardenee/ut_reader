<?php
declare(strict_types=1);
// B016 final acceptance script.
if(PHP_SAPI!=='cli')exit(2);
$root=dirname(__DIR__);$checks=[];$fails=[];$reports=[];$drivers=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fails):void{
 $checks[$name]=$ok;if(!$ok)$fails[]=$name;
};
foreach([
 'source'=>'verify-uedb5-b016-public-cell-source.php',
 'resolver'=>'verify-uedb5-ue5-zen-dependency-resolution.php',
 'v5'=>'verify-uedb5-b016-v5-roundtrip.php',
 'optional'=>'verify-uedb5-b012-operation-acceptance.php',
 'public'=>'verify-uedb5-b007-operation-acceptance.php',
 'identity'=>'verify-uedb5-b008-operation-acceptance.php',
 'rebuilder'=>'verify-uedb5-dependency-rebuilder.php',
 'sql'=>'verify-uedb5-dependency-sql-isolated.php',
 'compact'=>'verify-uedb5-zen-compact-reason-db.php'
] as $tag=>$script){
 $out=[];$exit=127;
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/'.$script);
 if($tag==='sql')$cmd.=' '.escapeshellarg('--backend-status-only');
 exec($cmd.' 2>&1',$out,$exit);
 $j=json_decode(implode("\n",$out),true);
 $reports[$tag]=is_array($j)?$j:[];
 $drivers[$tag]=['ok'=>$reports[$tag]['ok']??false,'exit'=>$exit];
 $check('b016_driver_'.$tag.'_passes',$exit===0&&($reports[$tag]['ok']??false)===true);
}
$has=static fn(string $tag,string $key):bool=>($reports[$tag]['checks'][$key]??false)===true;
$check('b016_pinned_epic_cell_header_search_and_offsets',
 $has('source','b016_epic_async_loading_hash_pinned')
 &&$has('source','b016_header_search_main_cell_map_only')
 &&$has('source','b016_cell_local_export_offsets_ordinary_exports')
 &&$has('source','b016_cell_header_first_match_then_not_found'));
$check('b016_import_indices_and_creation_path_source_verified',
 $has('source','b016_cell_import_offsets_ordinary_imports')
 &&$has('source','b016_cell_serialize_import_uses_combined_index')
 &&$has('source','b016_cell_fallback_uses_separate_cell_index')
 &&$has('source','b016_cell_serialize_uses_cell_export_path'));
$check('b016_live_verse_registry_not_fabricated_from_header',
 $has('source','b016_verse_registry_uses_package_id_and_hash')
 &&$has('source','b016_verse_registry_updates_same_hash')
 &&$has('source','b016_verse_registry_separate_from_uobject_registry')
 &&$has('source','b016_cell_creation_verse_vm_build_guarded')
 &&$has('source','b016_cell_store_requires_importable_package_and_hash'));
$check('b016_exact_key_and_static_vs_live_separation',
 $has('source','b016_cell_import_uses_public_export_key')
 &&$has('source','b016_unrealdb_separate_cell_export_hash_map')
 &&$has('resolver','zen_dependency_cell_import_rejects_ordinary_export_hash_match')
 &&$has('resolver','zen_dependency_cell_import_classified'));
$check('b016_combined_source_and_provider_indices_proven',
 $has('source','b016_unrealdb_combined_cell_index_provenance')
 &&$has('resolver','b016_main_cell_local_export_index_offsets_object_exports')
 &&$has('resolver','b016_cell_import_local_index_offsets_object_imports')
 &&$has('resolver','zen_dependency_cell_load_order_uses_combined_import_index'));
$check('b016_collisions_remain_unresolved_with_header_first_provenance',
 $has('source','b016_cell_first_header_candidate_explicitly_not_live')
 &&$has('resolver','b016_duplicate_cell_source_header_first_candidate_without_live_claim')
 &&$has('resolver','zen_dependency_duplicate_cell_hash_is_runtime_collision_ambiguous')
 &&$has('v5','b016_duplicate_cell_remains_unresolved')
 &&$has('v5','b016_combined_header_index_provenance'));
$check('b016_optional_cell_needs_live_runtime_registration',
 $has('source','b016_optional_cell_registration_not_assumed')
 &&$has('resolver','zen_editor_cell_import_searches_optional_provider_cell_exports')
 &&$has('resolver','zen_noneditor_cell_import_does_not_search_optional_provider_cell_exports')
 &&$has('resolver','zen_optional_provider_export_requires_editor_build_context_when_unknown'));
$check('b016_compressed_v5_roundtrip_retains_key_and_collision',
 $has('v5','b016_real_compressed_v5_written_verified')
 &&$has('v5','b016_v5_retains_first_cell_index_and_collision')
 &&$has('v5','b016_v5_retains_exact_cell_package_hash'));
$check('b016_all_direct_php_consumers_enumerated',
 $has('source','b016_exact_two_direct_production_consumers')
 &&$has('resolver','zen_dependency_results_retain_resolver_policy'));
$check('b016_previous_public_optional_identity_operations_still_pass',
 ($drivers['public']['ok']??false)===true
 &&($drivers['optional']['ok']??false)===true
 &&($drivers['identity']['ok']??false)===true);
$check('b016_rebuilder_sql_and_compact_consumer_acceptance',
 ($drivers['rebuilder']['ok']??false)===true
 &&($drivers['sql']['ok']??false)===true
 &&($drivers['compact']['ok']??false)===true);
echo json_encode(['ok'=>$fails===[],
 'operation'=>'UE5-583-B016-1','checks'=>$checks,
 'drivers'=>$drivers,'failures'=>$fails,
 'live_verse_vm_executed'=>false,
 'scope'=>'offline pinned-source, V5 metadata and SQL; live runtime not inferred'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fails===[]?0:1);
