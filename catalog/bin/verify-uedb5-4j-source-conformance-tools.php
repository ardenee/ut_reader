#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
$scan=(string)file_get_contents($root.'/bin/diagnose-4j-name-semantic-candidates.php');
$repair=(string)file_get_contents($root.'/bin/repair-uedb5-4j-source-policies.php');
$checks=[];
$check=static function(string$n,bool$ok)use(&$checks):void{$checks[$n]=$ok;};
$check('candidate_scan_is_read_only',!str_contains($scan,'UPDATE ')&&!str_contains($scan,'DELETE ')&&!str_contains($scan,'INSERT INTO '));
$check('candidate_scan_streams_each_table_once',str_contains($scan,'scan($gid,$fid,\'imports\')')&&str_contains($scan,'scan($gid,$fid,\'exports\')')&&str_contains($scan,'scan($gid,$fid,\'names\')')&&!str_contains($scan,'rowsByPositions'));
$check('candidate_scan_persists_ids',str_contains($scan,'file_put_contents($idsFile'));
$check('candidate_scan_is_exact_reference_intersection',str_contains($scan,"'exact'=>true")&&str_contains($scan,'isset($refs[$idx])'));
$check('policy_repair_is_dry_run_by_default',str_contains($repair,'$apply=isset($o[\'apply\'])')&&strpos($repair,'if(!$apply)')!==false);
$check('policy_repair_requires_current_dependency_checkpoint',str_contains($repair,'dependency_checkpoint_not_current_payload'));
$check('policy_repair_verifies_dependency_rows_unchanged',str_contains($repair,'dependency_results_changed_during_policy_refresh'));
$check('policy_repair_verifies_provider_identity_unchanged',str_contains($repair,'provider_identity_changed_during_policy_refresh'));
$check('policy_repair_rebinds_same_dependency_policy',str_contains($repair,'markDependencySucceeded($fid,$gid,$newPayload,(string)$meta[\'dependency_policy\'])'));
$check('policy_repair_does_not_publish_provider_graph',!str_contains($repair,'PdoUedb5ProviderKeyPublisher'));
$check('unrealgold_pass1_boundaries_fail_closed',str_contains($repair,'pre50_requires_pass1_reparse')&&str_contains($repair,'generation_count_out_of_range_requires_pass1_reparse'));
$check('policy_repair_supports_continuous_batches',str_contains($repair,"'continuous'")&&str_contains($repair,'$continuous=isset($o[\'continuous\'])')&&str_contains($repair,"'status'=>'batch_complete'"));
$check('policy_repair_continuous_advances_resume_cursor',str_contains($repair,'$cursor=$next')&&str_contains($repair,'Continuous source-policy repair made no forward progress.'));
$check('policy_repair_preserves_single_batch_mode',str_contains($repair,'if(!$continuous)break;')&&str_contains($repair,"'continuous'=>true"));
$failed=array_keys(array_filter($checks,static fn(bool$v):bool=>!$v));
echo json_encode(['ok'=>$failed===[],'checks'=>$checks,'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:2);
