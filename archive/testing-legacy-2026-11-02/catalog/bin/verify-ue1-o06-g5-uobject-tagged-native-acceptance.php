#!/usr/bin/env php
<?php
/**
 * ONE method-level T99:UObject::Serialize latest acceptance review.
 * Reuse SHA-current native compiled engine load and prior 43/43 StateFrame
 * acceptance; independently re-run ONLY fresh PHP current-source parsing
 * against the 33 original loaded Entry.unr exports and 113 native values.
 * Explicitly BLOCKED until negative tag cases, full consumed-byte paths and
 * version/inheritance coverage are closed by compiled-oracle evidence.
 */
declare(strict_types=1);
$root=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents(
    $root.'/catalog/compliance/UE1-O06-G5-UOBJECT-NATIVE-TAGGED-113.json'),
    true,512,JSON_THROW_ON_ERROR);
$checks=0;$issues=[];
$assert=static function(string $what,bool $pass)use(&$checks,&$issues):void{
    $checks++;
    if(!$pass)$issues[]=$what;
};
$pin=static function(array $file,string $label)use($assert):void{
    $assert('SHA256_'.$label,
        is_file($file['path'])&&hash_file('sha256',$file['path'])===$file['sha256']);
};
foreach(['archive','compiled_Core','compiled_native_launcher','launcher_source',
    'compiled_loaded_values_log','source_Engine_region','source_Engine_csg_enum'] as $key){
    $pin($manifest[$key],$key);
}
$nativeLog=(string)file_get_contents($manifest['compiled_loaded_values_log']['path']);
preg_match_all('/G5_NATIVE_OBJECT_BEGIN ([^\r\n]+)/',$nativeLog,$starts);
preg_match_all('/G5_NATIVE_OBJECT_END ([^\r\n]+)/',$nativeLog,$ends);
$assert('original_native_compiled_load_succeeded',
    str_contains($nativeLog,'G5_NATIVE_DIRECT_PACKAGE_BEGIN editor=1 client=0 server=0')
    &&str_contains($nativeLog,'G5_NATIVE_OBJECT_COUNT 33')
    &&str_contains($nativeLog,'native_error=0'));
$assert('33_compiled_loaded_original_export_identifiers',
    count($starts[1])===33&&count($ends[1])===33
    &&array_map('trim',$starts[1])===array_map('trim',$ends[1])
    &&count(array_unique($starts[1]))===33);
$assert('real_native_exporter_linked_to_core',
    str_contains((string)file_get_contents($manifest['launcher_source']['path']),
        'UObject::ExportProperties(Out,O->GetClass(),(BYTE*)O,0,NULL,NULL)'));
$assert('source_expected_113_tag_values',
    array_sum($manifest['matched_by_source_defined_value_kind'])===113);
$assert('method_is_NOT_yet_accepted',$manifest['full_method_accepted']===false);

$events=$root.'/catalog/compliance/UE1-O06-TEST-EVENTS.jsonl';
$old=$manifest['previous_complete_stateframe_acceptance'];$journal=null;
foreach(file($events,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
    $entry=json_decode(preg_replace('/^\xEF\xBB\xBF/','',$line),true,512,JSON_THROW_ON_ERROR);
    if(($entry['run_id']??'')===$old['journal']
        &&($entry['state']??'')==='PASS'&&($entry['test']??'')===$old['test'])
        $journal=$entry;
}
$assert('original_43_check_pass_journal_exists',is_array($journal));
if(is_array($journal)){
    $logPath=$root.'/'.str_replace('\\','/',$journal['log']);
    $originalSource=$root.'/catalog/bin/'.$old['test'];
    $assert('sealed_prior_43_output_sha256',
        is_file($logPath)&&hash_file('sha256',$logPath)===$journal['output_sha256']);
    $assert('sealed_prior_43_verifier_sha256',
        is_file($originalSource)&&hash_file('sha256',$originalSource)===$journal['test_sha256']);
    if(is_file($logPath)){
        $prior=json_decode(preg_replace('/^\xEF\xBB\xBF/','',(string)file_get_contents($logPath)),true);
        $assert('original_43_checks_passed_without_replay',
            ($prior['checks']??0)===$old['checks']
            &&($prior['passed']??0)===$old['checks']
            &&($prior['native_malformed_offsets_php_exact_error_matches']??0)===3);
    }
}

$reconcile=json_decode((string)file_get_contents(
    $root.'/catalog/compliance/UE1-O06-G5-RECONCILIATION.json'),true,
    512,JSON_THROW_ON_ERROR);
$assert('source_scoped_region_only_no_old_positive_retests',
    ($reconcile['source_region_pointregion_exact_codec_only']??false)===true
    &&($reconcile['source_uobject_positive_branch_equivalence']??false)===true);

$bin=PHP_BINARY;
$compare=$root.'/catalog/bin/ue1-o06-g5-uobject-compare-loaded-values.py';
$tasks=[
    [$bin,$root.'/catalog/bin/ue1-o06-g5-uobject-extract-current-tags.php'],
    [$bin,$root.'/catalog/bin/ue1-o06-g5-uobject-extract-native-ref-map.php'],
    ['python',$compare],
];
$comparison=null;$completed=0;
foreach($tasks as [$program,$programFile]){
    $out=[];$code=255;
    exec(escapeshellarg($program).' '.escapeshellarg($programFile).' 2>&1',$out,$code);
    $assert('current_reader_semantic_comparison_tool_'.$completed,$code===0);
    if($code!==0){$issues[]='tool '.$completed.': '.substr(implode("\n",$out),0,350);break;}
    if($completed===2){
        try{$comparison=json_decode(implode("\n",$out),true,512,JSON_THROW_ON_ERROR);}
        catch(\Throwable $e){$issues[]='comparison invalid JSON: '.$e->getMessage();}
    }
    $completed++;
}
if(is_array($comparison)){
    $assert('33_source_parsed_genuine_export_rows',
        ($comparison['original_33_loaded_classes']??-1)===$manifest['expected_native_loaded_original_export_count']);
    $assert('exactly_113_genuine_serialized_loaded_value_matches',
        ($comparison['exactly_compared']??-1)===$manifest['expected_complete_tag_comparisons']
        &&($comparison['total_tags']??-1)===$manifest['expected_complete_tag_comparisons']
        &&($comparison['problems']??null)===[]);
    foreach($manifest['matched_by_source_defined_value_kind'] as $kind=>$count){
        $assert('native_value_kind_'.$kind,
            ($comparison['kinds']['matched_'.$kind]??0)===$count);
    }
    $assert('native_original_sha_same_as_package',
        ($comparison['genuine_original_sha256']??'')===$manifest['archive']['sha256']);
    $assert('native_stdout_sha_same_as_pinned_oracle',
        ($comparison['compiled_native_log_sha256']??'')===$manifest['compiled_loaded_values_log']['sha256']);
}
$assert('all_original_33_matching_bodies_have_native_outputs',
    is_array($comparison)&&$completed===3);
$assert('full_method_acceptance_BLOCKED_on_other_paths',
    $manifest['full_method_accepted']===false
    &&count($manifest['limitations'])>=3);
$out=['ok'=>$issues===[],'checks'=>$checks,'passed'=>$checks-count($issues),
    'issues'=>$issues,
    'method'=>$manifest['method'],
    'genuine_original_native_loaded_exports'=>33,
    'complete_saved_tagged_value_comparisons'=>is_array($comparison)?($comparison['exactly_compared']??0):0,
    'previous_compiled_StateFrame_checks_reused_not_rerun'=>$old['checks'],
    'compiled_native_executable_replays'=>0,
    'current_source_parser_runs'=>1,
    'independent_full_method_acceptance'=>'BLOCKED',
    'remaining_gate'=>'Other malformed property and reference errors, archive consumption, applicable version branches and independent final review'];
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($issues===[]?0:1);
