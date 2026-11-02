<?php
declare(strict_types=1);
/**
 * B005 operation-level acceptance. Source-first independent oracles,
 * complete direct consumer inventory and end-to-end V5/temporary-SQL checks.
 * Fails closed and never writes persistent production DB data.
 */
if(PHP_SAPI!=='cli')exit(2);
$root=dirname(__DIR__);$checks=[];$fail=[];$evidence=[];
$check=static function(string $key,bool $good)use(&$checks,&$fail):void{
 $checks[$key]=$good;if(!$good)$fail[]=$key;
};
$exec=static function(string $name,array $arguments=[])use($root,&$evidence):array{
 $script=$root.'/bin/'.$name;
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);
 foreach($arguments as $argument){$cmd.=' '.escapeshellarg($argument);}
 $cmd.=' 2>&1';
 $out=[];$code=127;exec($cmd,$out,$code);
 $result=json_decode(implode("\n",$out),true);
 $evidence[$name]=['exit'=>$code,'ok'=>is_array($result)?($result['ok']??false):false];
 return [$code,is_array($result)?$result:[]];
};
$tests=[
 'source'=>'verify-uedb5-zen-backend-source-oracle.php',
 'hybrid'=>'verify-uedb5-zen-hybrid-source-contract.php',
 'callers'=>'verify-uedb5-zen-backend-callers.php',
 'selector'=>'verify-uedb5-physical-provider-selector.php',
 'rebuilder'=>'verify-uedb5-dependency-rebuilder.php',
 'arbitration'=>'verify-uedb5-zen-backend-arbitration.php',
 'mount'=>'verify-uedb5-zen-effective-store-context.php',
 'sql'=>'verify-uedb5-dependency-sql-isolated.php',
];
$reports=[];
foreach($tests as $key=>$test){
 [$code,$data]=$exec($test,$key==='sql'?['--backend-status-only']:[]);
 $reports[$key]=$data;
 $check('b005_driver_'.$key.'_passes',$code===0&&($data['ok']??false)===true);
}
$has=static fn(string $driver,string $key):bool=>
 ($reports[$driver]['checks'][$key]??false)===true;
$check('b005_source_exact_priority_and_statuses',
 $has('source','b005_pinned_package_store_source_hash')
 &&$has('source','b005_epic_none_missing_only_continue')
 &&$has('source','b005_epic_other_statuses_terminal')
 &&$has('source','b005_independent_equal_priority_newest_wins')
 &&$has('source','b005_independent_pending_terminal')
 &&$has('source','b005_independent_notinstalled_terminal')
 &&$has('source','b005_independent_missing_continues'));
$check('b005_hybrid_all_key_source_branches',
 $has('hybrid','b005_hybrid_pinned_source')
 &&$has('hybrid','b005_hybrid_activation')
 &&$has('hybrid','b005_hybrid_asset_registry')
 &&$has('hybrid','b005_hybrid_cooked_lookup')
 &&$has('hybrid','b005_hybrid_missing_only_falls_back')
 &&$has('hybrid','b005_hybrid_redirects_from_cooked')
 &&$has('hybrid','b005_hybrid_oracle_forced_loose')
 &&$has('hybrid','b005_hybrid_oracle_writable_asset')
 &&$has('hybrid','b005_hybrid_oracle_asset_registry_unknown_writable_loose')
 &&$has('hybrid','b005_hybrid_oracle_read_only_cooked_pending_terminal')
 &&$has('hybrid','b005_hybrid_oracle_read_only_cooked_missing_falls_to_loose')
 &&($reports['hybrid']['runtime_live_mount_captured']??null)===false);
$check('b005_complete_production_call_inventory',
 ($reports['callers']['production_php_scanned']??0)>500
 &&$has('callers','b005_all_direct_production_arbitrator_calls_mapped')
 &&$has('callers','b005_unmapped_future_caller_fails_inventory')
 &&$has('callers','b005_extra_existing_caller_fails_inventory'));
$check('b005_physical_selection_real_pdo',
 $has('selector','b005_nested_backend_ok_picks_exact_evidenced_provider')
 &&$has('selector','b005_nested_backend_pending_never_guesses_catalogue_file')
 &&$has('selector','b005_conflicting_backend_evidence_must_not_select_a_file')
 &&$has('selector','zen_mounted_file_backend_does_not_override_higher_pending_backend')
 &&$has('selector','zen_mounted_file_entries_without_authority_stay_ambiguous'));
$check('b005_rebuild_persistent_v5_metadata_roundtrip',
 $has('rebuilder','b005_rebuilder_uses_nested_authoritative_pending_evidence')
 &&$has('rebuilder','b005_rebuilder_rejects_conflicting_authoritative_backend_evidence')
 &&$has('rebuilder','v5_rebuilder_forwards_authoritative_package_store_pending_status')
 &&$has('rebuilder','v5_rebuilder_preserves_authoritative_missing_status'));
$statuses=[
 'Pending'=>['unresolved','package_store_entry_pending',0,1],
 'NotInstalled'=>['missing','package_store_entry_not_installed',1,0],
 'Missing'=>['missing','package_store_entry_missing',1,0],
 'Ok'=>['unresolved','package_store_evidenced_provider_unavailable',0,1],
];
$seen=[];
foreach((array)($reports['sql']['backend_status_fixture']??[]) as $row){
 $status=$row['status']??'';
 if(!isset($statuses[$status])||isset($seen[$status]))continue;
 [$outcome,$reason,$missing,$unresolved]=$statuses[$status];
 if(($row['ok']??false)===true
  &&($row['source_outcome']??'')===$outcome
  &&($row['source_reason']??'')===$reason
  &&($row['sql_missing']??null)===$missing
  &&($row['sql_unresolved']??null)===$unresolved){$seen[$status]=true;}
}
$check('b005_mysql_temporary_status_projection_complete',count($seen)===4);
$check('b005_production_runtime_authority_not_fabricated',
 $has('selector','zen_mounted_file_entries_without_authority_stay_ambiguous')
 &&$has('source','b005_conflicting_evidence_rejected')
 &&$has('hybrid','b005_editor_build_guard'));
echo json_encode([
 'ok'=>$fail===[],'operation'=>'UE5-583-BACKEND-STATUS-001',
 'read_only_real_catalog'=>true,'temporary_sql_only'=>true,
 'evidence'=>$evidence,'checks'=>$checks,'failures'=>$fail
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
