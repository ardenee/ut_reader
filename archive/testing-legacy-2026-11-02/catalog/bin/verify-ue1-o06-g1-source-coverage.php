#!/usr/bin/env php
<?php
/**
 * O06 G1 fixed exit: original UE1 v9/v10 source-to-reader reconciliation.
 *
 * 26 Epic v0.84a AUTOREGISTER_RESOURCE entries:
 * 23 saveable have production reader + named v9/v10 fixture verification;
 * UCamera, UPlayer, UTransBuffer are explicitly not saveable (Flip()).
 * Existing fixtures must each have independently journaled DEV/PRD PASS.
 *
 * This accepts mapping/byte-layout reconciliation G1, not G5 genuine
 * packages, in-memory pointer linking (O10), or bulk-content meaning (O07).
 */
declare(strict_types=1);
$root=dirname(__DIR__);
$repo=dirname($root);
$source='L:\Source\Games\Unreal\Unreal [v0.84a] [1996-12-11]\Src';
$readerDir=$root.'/src/Infrastructure/Readers/';
$prd='Z:\Apache24\htdocs\unrealdb';
$register=json_decode((string)file_get_contents($root.'/compliance/UE1-O06-SERIALIZER-REGISTER.json'),true,512,JSON_THROW_ON_ERROR);
$statusRaw=(string)file_get_contents($root.'/compliance/UE1-O06-TEST-STATUS.json');
$status=json_decode(substr($statusRaw,0,3)==="\xEF\xBB\xBF"?substr($statusRaw,3):$statusRaw,true,512,JSON_THROW_ON_ERROR);
$checks=[];$issues=[];
$check=static function(string $key,bool $ok,string $detail='')use(&$checks,&$issues):void{
    $checks[$key]=$ok;
    if(!$ok){$issues[]=$key.($detail!==''?': '.$detail:'');}
};
$registered=[];
$sourceFiles=[];
if(!is_dir($source)){throw new RuntimeException('Canonical 1996 Epic source inaccessible');}
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file){
    if(!$file->isFile()||strtolower($file->getExtension())!=='cpp')continue;
    $body=file_get_contents($file->getPathname());
    if(!is_string($body))throw new RuntimeException('1996 source read failed');
    preg_match_all('/AUTOREGISTER_RESOURCE\s*\(\s*(RES_[A-Za-z0-9_]+)\s*,\s*(U[A-Za-z0-9_]+)/',$body,$matches,PREG_SET_ORDER);
    if($matches!==[]){
        $sourceFiles[]=['file'=>$file->getPathname(),'sha256'=>hash('sha256',$body)];
        foreach($matches as $m){
            $class=$m[2];
            if(isset($registered[$class])){
                $check('single_register_'.$class,false,'source duplicate registration');
            }
            $registered[$class]=$m[1];
        }
    }
}
ksort($registered);
$map=[];
foreach($register['entries']??[] as $e){
    if(($e['branch']??'')==='R96'){
        $class=(string)$e['symbol'];
        if(isset($map[$class]))$check('unique_register_'.$class,false);
        $map[$class]=$e;
    }
}
ksort($map);
$excluded=['UCamera','UPlayer','UTransBuffer'];
$saveable=array_values(array_diff(array_keys($registered),$excluded));
$check('source_registration_count_exactly_26',count($registered)===26);
$check('source_registration_classes_match_register',array_keys($registered)===array_keys($map));
$check('source_saveable_registration_count_23',count($saveable)===23);
$check('excluded_families_source_identified',
    count($excluded)===3 && count(array_intersect($excluded,array_keys($registered)))===3);
$check('source_registered_cpp_file_count_14',count($sourceFiles)===14);
$tests=[];
foreach($registered as $class=>$resource){
    $e=$map[$class]??[];
    $isExcluded=in_array($class,$excluded,true);
    $reader=(string)($e['reader']??'');
    $test=(string)($e['test']??'');
    $readerPath=$readerDir.$reader;
    $prdReader=$prd.'\catalog\src\Infrastructure\Readers\\'.$reader;
    $testPath=$root.'/bin/'.$test;
    $prdTestPath=$prd.'\catalog\bin\\'.$test;
    $check('registration_'.$class.'_source_id',
        str_starts_with($resource,'RES_'));
    $check('registration_'.$class.'_scope',
        ($e['status']??'')===($isExcluded?'EXCLUDED':'PARTIAL'));
    $check('registration_'.$class.'_reader',
        $isExcluded?$reader==='' : ($reader!==''&&is_file($readerPath)));
    if(!$isExcluded){
        $check('registration_'.$class.'_reader_deployed',
            is_file($prdReader)&&hash_file('sha256',$readerPath)===hash_file('sha256',$prdReader));
    }
    $check('registration_'.$class.'_test',
        $test!==''&&is_file($testPath)&&is_file($prdTestPath));
    if($test!==''){$tests[$test]=true;}
}
foreach([
    'verify-ue1-original-resource-headers.php',
    'verify-ue1-original-resource-tables.php',
    'verify-ue1-original-resource-actors.php',
    'verify-ue1-original-actor-class-values.php',
    'verify-ue1-original-native-actor-values.php',
    'verify-ue1-original-actor-resmap-classes.php',
    'verify-ue1-original-staging-integration.php',
    'verify-ue1-original-1996-msvc-abi.php',
    'verify-ue1-original-1996-actor-abi.php',
] as $extra){$tests[$extra]=true;}
ksort($tests);
$check('26_named_independent_verifiers',count($tests)===26);
$byKey=[];
foreach($status['tests']??[] as $item){$byKey[$item['key']??'']=$item;}
$fingerprints=[];
foreach(array_keys($tests) as $t){
    foreach(['DEV','PRD'] as $target){
        $row=$byKey[$target.':'.$t]??[];
        $prefix=$target==='DEV'?$root:$prd.'\catalog';
        $local=$prefix.'/bin/'.$t;
        $expected=is_file($local)?hash_file('sha256',$local):'';
        $actual=$row['test_sha256']??'';
        $sourceSha=(string)($row['source_sha256']??'');
        $fingerprints[$target][$sourceSha]=true;
        $check($target.'_test_'.$t.'_latest_pass',
            ($row['state']??'')==='PASS'&&($row['exit_code']??1)===0);
        $check($target.'_test_'.$t.'_sha256_matches_current',
            $expected!==''&&hash_equals($expected,(string)$actual));
        $logRelative=str_replace('\\','/',(string)($row['log']??''));
        // The acceptance runner deliberately keeps DEV and PRD journals
        // centrally in the DEV repository, not inside the PRD webroot.
        $journalRoot='C:/Users/arden/source/repos/ut_reader/';
        $log=$journalRoot.$logRelative;
        $check($target.'_test_'.$t.'_output_saved',is_file($log));
    }
}
$check('DEV_26_tests_single_source_fingerprint',count($fingerprints['DEV']??[])===1);
$check('PRD_26_tests_single_source_fingerprint',count($fingerprints['PRD']??[])===1);
$ok=$issues===[];
echo json_encode([
    'ok'=>$ok,'gate'=>'UE1-O06-G1','status'=>$ok?'SOURCE_MAPPING_ACCEPTED_G5_STILL_OPEN':'BLOCKED',
    'source_registered'=>count($registered),'saveable'=>count($saveable),
    'source_excluded'=>count($excluded),'source_cpp_files'=>count($sourceFiles),
    'mapped_saveable_readers'=>count(array_filter($saveable,static fn($x):bool=>
        isset($map[$x]['reader'])&&$map[$x]['reader']!=='')),
    'named_tests_each_target'=>count($tests),
    'passed_checks'=>count($checks)-count($issues),'total_checks'=>count($checks),
    'issues'=>$issues,'registered_classes'=>array_keys($registered),
    'source_sha256'=>$sourceFiles,
    'out_of_scope_for_G1'=>[
        'G5 real matching package corpus','O07 texture/audio payload interpretation',
        'O10 runtime reference fixup, object instantiation, class linking'
    ],
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
