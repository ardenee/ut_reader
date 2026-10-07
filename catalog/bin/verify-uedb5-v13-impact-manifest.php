#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
$transition=(string)file_get_contents($root.'/bin/transition-uedb5-source-identity-policy.php');
$apply=(string)file_get_contents($root.'/bin/apply-uedb5-v13-impact-manifest.php');
$checks=[];$fail=[];
$check=static function(string$n,bool$ok)use(&$checks,&$fail):void{$checks[$n]=$ok;if(!$ok)$fail[]=$n;};
$check('transition_can_persist_exact_manifest',str_contains($transition,"'manifest-out::'")&&str_contains($transition,"'schema'=>'uedb5-v13-impact-manifest-v1'")&&str_contains($transition,"'impacted'=>\$impacted")&&str_contains($transition,"'v4_impacted'=>\$v4Impacted"));
$check('manifest_apply_requires_explicit_manifest_and_apply',str_contains($apply,"'manifest:'")&&str_contains($apply,'--apply is required'));
$check('manifest_apply_has_no_impact_discovery_queries',!str_contains($apply,'PdoClassicSourceIdentityImpactQuery')&&!str_contains($apply,'PdoUe1VerifyImportImpactQuery')&&!str_contains($apply,'PdoUe2VerifyImportImpactQuery')&&!str_contains($apply,'PdoUe3VerifyImportImpactQuery')&&!str_contains($apply,'PdoUe4VerifyImportImpactQuery')&&!str_contains($apply,'PdoUe5ClassicVerifyImportImpactQuery')&&!str_contains($apply,'PdoUe5ZenDependencyImpactQuery'));
$check('manifest_apply_targets_impacted_temp_table',str_contains($apply,'tmp_uedb5_v13_impacted')&&str_contains($apply,'LEFT JOIN tmp_uedb5_v13_impacted'));
$check('manifest_apply_streams_only_legacy_dependency_rows',str_contains($apply,'WHERE p.package_key_kind=')&&str_contains($apply,'Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME')&&str_contains($apply,'legacy_dependency_keys_converted'));
$check('manifest_apply_rolls_unaffected_set_based',str_contains($apply,'rolled_forward_unaffected')&&str_contains($apply,'i.file_id IS NULL'));
$check('manifest_apply_rebuilds_exact_manifest_files',str_contains($apply,'foreach($impacted as$r)')&&str_contains($apply,'runFile((int)$r[\'game_id\'],$fid,true,true)'));
$check('manifest_apply_fails_prerequisites_before_mutation',strpos($apply,"Manifest contains Pass-1 prerequisites")<strpos($apply,'CREATE TEMPORARY TABLE tmp_uedb5_v13_impacted'));
$check('manifest_apply_requires_current_payloads',str_contains($apply,'Old-policy rows with stale payload checkpoints remain'));
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit($fail===[]?0:2);
