#!/usr/bin/env php
<?php
/**
 * Frozen UE1 O05 *file-metadata dependency projection* acceptance driver.
 *
 * Source-defined deterministic lookup and real V4 SQL / V5 provider routes
 * must pass together. Original engine process/global ResArray internment and
 * native transient fallback cannot be reconstructed from serialized packages;
 * these remain explicitly unverified, not a claimed successful binding.
 *
 * No permanent SQL mutation: nested integration tests use only session
 * TEMPORARY tables and isolated UEDB5 container paths.
 */
declare(strict_types=1);
$base=dirname(__DIR__);
$tests=[
    'original_resource_lookup' => 'verify-ue1-original-resource-import-lookup.php',
    'original_resource_stage' => 'verify-ue1-original-staging-integration.php',
    'original_native_mysql' => 'verify-ue1-original-staging-mysql-isolated.php',
    'original_resmap' => 'verify-ue1-original-unrealfile-object-bases.php',
    'classic_verify_import' => 'verify-ue1-verify-import-profile-contract.php',
    'v5_provider_resolution' => 'verify-uedb5-unreal-classic-dependency-resolution.php',
    'v4_source_root_mysql' => 'verify-ue1-v4-root-mysql-isolated.php',
    'v4_binary_provider_mysql' => 'verify-ue1-v4-provider-sql-isolated.php',
    'genuine_ut99_provider_maps' => 'verify-ue1-genuine-dependency-bindings.php',
    'genuine_ue1_corpus_workflow' => 'verify-ue1-genuine-production-paths.php',
];
$report=[];$failed=[];
foreach($tests as $label=>$script){
    $command=[PHP_BINARY,$base.'/bin/'.$script];
    $proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$base);
    if(!is_resource($proc)){throw new RuntimeException('Could not launch '.$script);}
    fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $status=proc_close($proc);
    $decoded=is_string($stdout)?json_decode($stdout,true):null;
    $ok=$status===0&&is_array($decoded)&&($decoded['ok']??false)===true;
    if($label==='genuine_ut99_provider_maps'){
        $ok=$ok && ($decoded['genuine_ut99_packages']??0)>=27
            && ($decoded['genuine_provider_resolutions']??0)>=75
            && ($decoded['wrong_class_rejections']??0)>=75;
    }elseif($label==='genuine_ue1_corpus_workflow'){
        $ok=$ok && ($decoded['passed']??0)===92;
    }
    $report[$label]=[
        'passed'=>$ok,
        'exit_code'=>$status,
        'checks'=>$decoded['total']
            ?? (isset($decoded['checks']) && is_array($decoded['checks']) ? count($decoded['checks']) : null)
            ?? $decoded['passed'] ?? $decoded['genuine_provider_resolutions'] ?? null,
    ];
    if(!$ok){
        $report[$label]['details']=is_array($decoded)?($decoded['failures']??$decoded):$stdout;
        $report[$label]['stderr']=$stderr;
        $failed[]=$label;
    }
}
echo json_encode([
 'ok'=>$failed===[],
 'operation'=>'UE1 O05 file metadata dependency projection',
 'accepted_scope'=>'source-determined serialized import/export and provider resolution; explicit unknown runtime states',
 'runtime_engine_oracle'=>'not_claimed',
 'gates_passed'=>count($tests)-count($failed),
 'gates_total'=>count($tests),
 'checks'=>$report,
 'failed'=>$failed,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($failed===[]?0:1);
