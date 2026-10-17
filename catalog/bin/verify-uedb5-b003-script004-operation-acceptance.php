<?php
declare(strict_types=1);
/** SCRIPT-004: source contract + actual editor registration trace acceptance. */
$root=dirname(__DIR__,2);
$run=static function(string $name) use($root):array {
 $lines=[];$status=127;
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/catalog/bin/'.$name).' 2>&1',$lines,$status);
 return [$status,json_decode(implode("\n",$lines),true)];
};
[$s,$source]=$run('verify-uedb5-b003-script-registration-source.php');
[$b,$boundary]=$run('verify-uedb5-b003-script-registration-boundary-acceptance.php');
$checks=[];
$checks['script004_source_15'] = $s===0 && ($source['ok']??false)===true && count((array)($source['checks']??[]))===15 && !in_array(false,$source['checks'],true);
$checks['script004_boundary_14'] = $b===0 && ($boundary['ok']??false)===true && count((array)($boundary['checks']??[]))===14 && !in_array(false,$boundary['checks'],true);
$path='D:/Temp/uedb5-ue583-runtime/script003-postgc-direct.log';
$expected='cd3eed15774f748038f7c996cda5ea59834a61b2fecbf3bbb52f4eb35aa4cec6';
$log=is_file($path)?file_get_contents($path):false;
$checks['script004_original_trace_sha256'] = is_string($log) && hash('sha256',$log)===$expected;
$counts=['initial_object'=>0,'editor_package'=>0,'nested_public'=>0,'enumerated_public'=>0,'registration_complete'=>0,'remove_gc'=>0];
$indices=[];
if(is_string($log)) {
 foreach(explode("\n",$log) as $line) {
  if(!str_contains($line,'UEDB_RT|event='))continue;
  if(preg_match('/UEDB_RT\\|event=add\\|kind=(initial_object|editor_package|nested_public|enumerated_public)\\|index=([0-9A-F]{16})\\|path=(.+)$/',trim($line),$m)){
   $counts[$m[1]]++;
   if(!isset($indices[$m[1]]))$indices[$m[1]]=$m[2];
  }
  if(str_contains($line,'UEDB_RT|event=registration_complete|'))$counts['registration_complete']++;
  if(str_contains($line,'UEDB_RT|event=remove_gc|'))$counts['remove_gc']++;
 }
}
$checks['script004_live_initial_editor_package'] = $counts['initial_object']===14239 && $counts['editor_package']===14239;
$checks['script004_live_nested_public'] = $counts['nested_public']===9782 && $counts['enumerated_public']===23376;
$checks['script004_editor_completion_and_gc'] = $counts['registration_complete']===1 && $counts['remove_gc']===1;
$checks['script004_typed_indices'] = count($indices)===4 && array_reduce($indices,static fn(bool $ok,string $index):bool=>$ok && preg_match('/^[4567][0-9A-F]{15}$/D',$index)===1,true);
$inspectorFile='D:/Temp/uedb5-ue583-runtime/script003-postgc-inspection-utf8.json';
$inspector=is_file($inspectorFile)?json_decode((string)file_get_contents($inspectorFile),true):[];
$checks['script004_strict_trace_67206'] = ($inspector['ok']??false)===true && ($inspector['events_seen']??0)===67206 && ($inspector['events_passed']??0)===67206 && ($inspector['rejected_semantic_event_count']??-1)===0 && strtolower($inspector['log_sha256']??'')===$expected;
$ok=!in_array(false,$checks,true);
echo json_encode(['ok'=>$ok,'operation'=>'UE5-583-SCRIPT-004','checks'=>$checks,'trace_counts'=>$counts,'source_test_is_static_not_real_verse_execution'=>true,'editor_normal_exit_unverified'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
