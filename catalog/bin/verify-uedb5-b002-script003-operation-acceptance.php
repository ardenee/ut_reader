<?php
declare(strict_types=1);
/** SCRIPT-003 operation gate. Excerpt is reviewable evidence, not proof of provenance. */
$root = dirname(__DIR__, 2);
$fixture = json_decode((string)file_get_contents($root.'/catalog/tests/fixtures/ue5-script003-postgc-runtime-excerpt.json'), true, 512, JSON_THROW_ON_ERROR);
$lines=[]; $rc=127;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/catalog/bin/verify-uedb5-b002-script-import-runtime-boundary.php').' 2>&1', $lines, $rc);
$oracle=json_decode(implode("\n",$lines),true);
$c=[];
$c['script003_source_boundary'] = $rc===0 && ($oracle['ok']??false)===true && count((array)($oracle['checks']??[]))===11;
$c['script003_live_dispatch'] = ($oracle['checks']['b002_script3_live_find_ref_only']??false)===true && ($oracle['checks']['b002_script3_dispatches_script_without_public_export_fallback']??false)===true;
$c['script003_offline_unresolved'] = ($oracle['checks']['b002_script3_main_result_is_unresolved_without_false_provider']??false)===true && ($oracle['checks']['b002_script3_sql_unresolved_does_not_claim_package_or_object']??false)===true;
$c['script003_sql_typed_hash'] = ($oracle['checks']['b002_script3_script_hash_not_conflated_with_zen_public_export_hash']??false)===true;
$events=(array)($fixture['events']??[]);
$idx=(string)($fixture['index']??'');
$path=(string)($fixture['path']??'');
$hits=static fn(string $term): array => array_values(array_filter($events,static fn($line): bool => is_string($line)&&str_contains($line,$term)));
$add=$hits('UEDB_RT|event=add|kind=initial_object|index='.$idx.'|path='.$path);
$remove=$hits('UEDB_RT|event=remove_gc|index='.$idx.'|path='.$path);
$lookups=$hits('UEDB_RT|event=lookup|index='.$idx.'|found=0');
$c['script003_typed_registration'] = (bool)preg_match('/^[4567][0-9A-F]{15}$/D',$idx)&&count($add)===1;
$c['script003_same_index_unreachable_gc'] = count($remove)===1 && count($add)===1 && array_search($add[0],$events,true)<array_search($remove[0],$events,true);
$c['script003_null_lookup_after_gc'] = count($lookups)===2 && count($remove)===1 && array_search($remove[0],$events,true)<array_search($lookups[0],$events,true)&&array_search($lookups[0],$events,true)<array_search($lookups[1],$events,true);
$c['script003_direct_trace_digest_and_inspection'] = strtoupper((string)($fixture['original_log_sha256']??''))==='CD3EED15774F748038F7C996CDA5EA59834A61B2FECBF3BBB52F4EB35AA4CEC6'
  && ($fixture['original_event_count']??0)===67206 && ($fixture['original_inspection_accepted']??0)===67206 && ($fixture['original_inspection_rejected']??-1)===0;
$local='D:/Temp/uedb5-ue583-runtime/script003-postgc-direct.log';
if(is_file($local)) $c['script003_direct_trace_digest_and_inspection'] = $c['script003_direct_trace_digest_and_inspection'] && hash_file('sha256',$local)===strtolower($fixture['original_log_sha256']);
$ok=!in_array(false,$c,true);
echo json_encode(['ok'=>$ok,'operation'=>'UE5-583-SCRIPT-003','checks'=>$c,'excerpt_does_not_attest_runtime_origin'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
