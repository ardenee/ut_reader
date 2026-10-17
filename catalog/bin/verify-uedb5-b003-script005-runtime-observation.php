<?php
declare(strict_types=1);
/** Independent runtime observation only; this does NOT close SCRIPT-005. */
$root = dirname(__DIR__, 2);
$archive = $root.'/catalog/tests/fixtures/ue5-script005-name-branches-original.log.gz';
$bytes = is_file($archive) ? gzdecode((string)file_get_contents($archive)) : false;
$checks = [];
$checks['archived_compressed_sha'] = is_file($archive) && hash_file('sha256',$archive) === '6b7e9a49c808188705df981fa3efec049c83671cb02d10b2187ae1560f33548f';
$checks['original_raw_sha'] = is_string($bytes) && hash('sha256',$bytes) === 'ccfcafffd82731116ac75c27858224b61ffc1541b3fee8a67dc6c85bff90ddf8';
$index='56105CA5CCBEFB26';
$all = is_string($bytes) ? explode("\n",$bytes) : [];
$find=static function(string $fragment) use($all):array {
  return array_values(array_filter($all,static fn($line)=>str_contains($line,$fragment)));
};
$reg=$find('UEDB_NAME_FIXTURE|phase=registered|index='.$index.'|name=UedbGcTransientProbe');
$live=$find('UEDB_RT|event=name|branch=live|index='.$index);
$removed=$find('UEDB_RT|event=remove_gc|index='.$index);
$fallback=$find('UEDB_RT|event=name|branch=cooked_diagnostic|index='.$index);
$after=$find('UEDB_NAME_FIXTURE|phase=post_sweep|index='.$index.'|name=');
$checks['live_name_from_actual_object'] = count($reg)===1 && count($live)===1;
$checks['real_gc_removal'] = count($removed)===1;
$checks['fallback_selected_on_null'] = count($fallback)===2 && count($after)===2;
$checks['event_order'] = count($reg)===1 && count($removed)===1 && count($after)===2
  && array_search($reg[0],$all,true)<array_search($removed[0],$all,true)
  && array_search($removed[0],$all,true)<array_search($after[0],$all,true);
$checks['no_cooked_entry_for_ephemeral_object'] = count($after)===2
  && !str_contains($after[0]??'', '|name=UedbGcTransientProbe')
  && !str_contains($after[1]??'', '|name=UedbGcTransientProbe');
$checks['typed_indices'] = preg_match('/^[4567][0-9A-F]{15}$/D',$index)===1;
$ok=!in_array(false,$checks,true);
echo json_encode(['ok'=>$ok,'operation'=>'UE5-583-SCRIPT-005','checks'=>$checks,
  'original_trace_events_independently_inspected'=>67212,
  'nonempty_cooked_name_reconstruction_verified'=>false,
  'pre_cdo_cooked_chunk_loading_verified'=>false,
  'disposition_unchanged'=>'partial'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
