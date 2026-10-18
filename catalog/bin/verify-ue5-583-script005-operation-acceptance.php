<?php
declare(strict_types=1);
/**
 * Original SCRIPT-005 operation acceptance across pinned Epic source,
 * real UE5.8.3 editor and cooked game, plus corrected UnrealDB offline reader.
 * A controlled CDB call invoked the ORIGINAL UnrealGame GetImportName function.
 * It is not a claim that this minimal game naturally imported this script.
 */
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenScriptObjectsReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenScriptImportDiagnosticPreview;

$root=dirname(__DIR__);
$fixture=$root.'/tests/fixtures/';
$read=static function(string $name,string $hash) use ($fixture):?string {
    $path=$fixture.$name;
    if(!is_file($path)||hash_file('sha256',$path)!==$hash){return null;}
    $raw=(string)file_get_contents($path);
    return str_starts_with($raw,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($raw,2)):$raw;
};
$checks=[];
$log=$read('ue5-583-script005-direct-getimportname-original.log',
    '045ea6aeeeace44c1c802f32622813ce11106a3955f303c31319c34dd4807c04');
$symbols=$read('ue5-583-script005-getimportname-symbols-original.log',
    'fa1f4d3d18c6e06ad90483a4ca2960fb0ceb1018dbc236fabb52115654da9dc8');
$checks['script005_original_cdb_log_sha256']=$log!==null;
$checks['script005_original_pdb_symbol_log_sha256']=$symbols!==null;
$log ??='';$symbols ??='';
$index='5635D6648A5A6CB7';
$expected='/Script/ActionableMessage.ActionableMessageSubsystem';
$checks['script005_pdb_symbol_getimportname_address']=str_contains($symbols,
    '00007ff6`55024990 UnrealGame!FPackageImportStore::GetImportName');
$checks['script005_compiled_function_rip_and_index']=str_contains($log,
    'rip=00007ff655024990 rsp=0000001a9617c728')
    &&str_contains($log,'r9='.strtolower($index))
    &&str_contains($log,'r8=0000016f68d40100');
$checks['script005_actual_cooked_fallback_breakpoint']=str_contains($log,
    'bp /1 UnrealGame!FCookedScriptObjectsDebug::GetPathName')
    &&preg_match('/^UEDB_SCRIPT005_GETPATHNAME_FALLBACK_HIT\r?$/m',$log)===1
    &&substr_count($log,"\nUEDB_SCRIPT005_GETPATHNAME_FALLBACK_HIT")===1;
$checks['script005_actual_return_fstring_nonempty']=str_contains($log,
    '0000016f`68d40400  00007fea`9f417d20 00000038`00000035')
    &&str_contains($log,'00007fea`9f417d20  "/Script/ActionableMessage.Action"')
    &&str_contains($log,'00007fea`9f417d60  "ableMessageSubsystem"')
    &&strlen($expected)+1===53; // FString Num includes terminating NUL
$checks['script005_restored_original_calling_context']=str_contains($log,
    'rip=00007ff6550382a0 rsp=0000001a9617c758 rcx=00007ff31f05e548');
$checks['script005_cooked_game_shutdown_after_probe']=str_contains($log,
    'LogExit: Game engine shut down') && str_contains($log,'LogExit: Exiting.')
    &&!str_contains($log,'Fatal error:');
$parsed=Uedb5ZenScriptObjectsReader::fromToc(
    new Uedb5IoStoreTocReader($fixture.'ue5-583-cooked-global.utoc'));
$objects=$parsed['objects']??[];
$checks['script005_cooked_index_offline_oracle']=$parsed['present']===true
    &&count($objects)===23672
    &&Uedb5ZenScriptImportDiagnosticPreview::getCookedDiagnosticPathName($objects,$index)===$expected;
$drivers=[
    'source'=>'verify-uedb5-b003-script-diagnostic-path-source.php',
    'boundary'=>'verify-uedb5-b003-script-lookup-boundary-acceptance.php',
    'live'=>'verify-uedb5-b003-script005-runtime-observation.php',
    'cooked'=>'verify-ue5-583-cooked-scriptobjects-artifact.php',
    'precdo'=>'verify-ue5-583-script005-cooked-precdo-runtime.php',
];
$expectedCounts=['source'=>21,'boundary'=>11,'live'=>8,'cooked'=>6,'precdo'=>13];
foreach($drivers as $name=>$script){
    $lines=[];$code=127;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/'.$script).' 2>&1',$lines,$code);
    $result=json_decode(implode("\n",$lines),true);
    $driverChecks=(array)($result['checks']??[]);
    $checks['script005_'.$name.'_prior_acceptance']=$code===0
        &&($result['ok']??false)===true
        &&count($driverChecks)===$expectedCounts[$name]
        &&!in_array(false,$driverChecks,true);
}
$ok=!in_array(false,$checks,true);
echo json_encode(['ok'=>$ok,'operation'=>'UE5-583-SCRIPT-005','checks'=>$checks,
    'controlled_real_engine_getimportname_nonempty'=>$checks['script005_actual_return_fstring_nonempty']
        &&$checks['script005_actual_cooked_fallback_breakpoint'],
    'minimal_game_natural_import_observed'=>false,
    'prior_assertions'=>array_sum($expectedCounts),
    'runtime_cooked_name'=>$expected,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
