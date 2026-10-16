<?php
declare(strict_types=1);
/** Negative/positive tests for the recovery audit's coverage invariants. */
if (PHP_SAPI !== 'cli') { exit(2); }
$root=dirname(__DIR__,2);
$baseline=json_decode((string)file_get_contents(
    $root.'/catalog/compliance/RECOVERY.json'),true,512,JSON_THROW_ON_ERROR);
$verifier=$root.'/catalog/bin/verify-compliance-recovery.php';
$tmp=tempnam(sys_get_temp_dir(),'uedb5-recovery-');
if(!is_string($tmp)){throw new RuntimeException('No temporary fixture path');}
$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{
    $checks[$name]=$ok;
    if(!$ok){$failures[]=$name;}
};
$run=static function(array $data,bool $strict=false)use($tmp,$verifier):array{
    file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    $lines=[];$code=127;
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($verifier)
       .' --baseline='.escapeshellarg($tmp).($strict?' --strict':'');
    exec($cmd.' 2>&1',$lines,$code);
    $json=json_decode(implode("\n",$lines),true);
    return [$code,is_array($json)?$json:[]];
};
try {
    [$exit,$report]=$run($baseline);
    $check('valid_inventory_passes_without_claiming_compliance',
        $exit===0&&($report['ok']??null)===true
        &&($report['compliance_accepted']??null)===false
        &&($report['scope_denominator_known']??null)===false
        &&($report['registered_operations']??null)===18
        &&($report['ledger_differences']??null)===14);
    $check('unregistered_iostore_behavior_exposed',
        ($report['unregistered_source_differences']??[])===['UE5-D007']);
    [$exit,$report]=$run($baseline,true);
    $check('strict_denies_unfinished_source_coverage',
        $exit!==0&&($report['ok']??null)===false);
    $mutation=$baseline;
    array_pop($mutation['original_operation_priority']);
    [$exit]=$run($mutation);
    $check('missing_original_operation_fails',$exit!==0);
    $mutation=$baseline;
    array_pop($mutation['known_differences']);
    [$exit]=$run($mutation);
    $check('orphan_ledger_difference_fails',$exit!==0);
    $mutation=$baseline;
    $mutation['known_differences'][8]['operations']=['NOT-AN-EPIC-OPERATION'];
    [$exit]=$run($mutation);
    $check('invalid_original_parent_fails',$exit!==0);
    $mutation=$baseline;
    array_pop($mutation['game_profiles']);
    [$exit]=$run($mutation);
    $check('missing_supported_game_fails',$exit!==0);
    $mutation=$baseline;
    $mutation['compliance_claim']='accepted';
    [$exit]=$run($mutation);
    $check('false_compliance_accepted_fails',$exit!==0);
    $mutation=$baseline;
    array_pop($mutation['required_workflow_paths']);
    [$exit]=$run($mutation);
    $check('missing_file_processing_route_fails',$exit!==0);
    $mutation=$baseline;
    $mutation['original_operation_priority'][]=$mutation['original_operation_priority'][0];
    [$exit]=$run($mutation);
    $check('repeated_original_operation_fails',$exit!==0);
    $mutation=$baseline;
    $mutation['known_differences'][4]['kind']='audit_control';
    $mutation['known_differences'][4]['operations']=[];
    [$exit]=$run($mutation);
    $check('original_operation_must_reciprocate_source_difference',$exit!==0);
} finally {
    @unlink($tmp);
}
echo json_encode(['ok'=>$failures===[],'count'=>count($checks),
    'checks'=>$checks,'failures'=>$failures],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
