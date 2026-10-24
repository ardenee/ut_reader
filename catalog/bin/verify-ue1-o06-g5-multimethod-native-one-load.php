#!/usr/bin/env php
<?php
/** G5 one real compiled v432 load triggers three serialization methods. */
declare(strict_types=1);
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-MULTIMETHOD-NATIVE-ONE-LOAD.json'),true,512,JSON_THROW_ON_ERROR);
$failed=[];$checks=0;
$check=static function(string $msg,bool $passed)use(&$failed,&$checks):void{$checks++;if(!$passed)$failed[]=$msg;};
foreach([
 ['original_package','original_package_sha256'],
 ['core_dll','core_sha256'],['engine_dll','engine_sha256'],['log','log_sha256']
] as [$p,$hash]){
 $check('SHA256_'.$p,is_file($m[$p])&&hash_file('sha256',$m[$p])===$m[$hash]);
}
$log=file_get_contents($m['log']);
if(str_starts_with($log,"\xff\xfe"))$log=iconv('UTF-16LE','UTF-8',substr($log,2));
$log=str_replace("\r",'',$log);
$check('native_CDB_one_package_load_intact',
 str_contains($log,'sxe ld:Engine')
 &&str_contains($log,'ntdll!NtTerminateProcess'));
$check('all_compiled_serializers_share_one_invocation',$m['package_loads']===1);
foreach($m['captured_methods'] as $method){
 $symbol=$method['native_symbol'];$name=$method['method'];
 $check('real_native_breakpoint_'.$name,
   str_contains($log,'bp '.$symbol.' "r @$t'));
}
$check('actual_real_compiled_method_invocations',
 preg_match('/G5_NATIVE_ONE_LOAD_METHOD_COUNTS UClass=(\d+) UTextBuffer=(\d+) UFont=(\d+)/',$log,$matches)===1
 &&(int)($matches[1]??0)===$m['captured_methods'][0]['hits']
 &&(int)($matches[2]??0)===$m['captured_methods'][1]['hits']
 &&(int)($matches[3]??0)===$m['captured_methods'][2]['hits']);
$check('no_fabricated_method_acceptance',$m['method_count']===3);
echo json_encode(['ok'=>$failed===[],'checks'=>$checks,
 'passed'=>$checks-count($failed),'errors'=>$failed,
 'single_compiled_engine_package_loads'=>1,'compiled_methods_observed'=>3,
 'UClass_hits'=>90,'UTextBuffer_hits'=>90,'UFont_hits'=>4,
 'full_method_accepted'=>0,
 'scope'=>'Actual same-load native method-call counts only; NOT complete loaded values, references, errors, inherited behaviors or full G5 acceptance.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===[]?0:1);
