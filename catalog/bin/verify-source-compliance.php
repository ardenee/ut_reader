<?php
declare(strict_types=1);
/** Local read-only verifier; does not claim to prove source-inventory completeness. */
$root=dirname(__DIR__,2);
$dir=$root.'/catalog/compliance/batches';
$strict=in_array('--strict',$argv,true);
$files=glob($dir.'/*.json')?:[];
$failures=[];$open=0;$verified=0;$entries=0;
if($files===[])$failures[]='No audit batches exist.';
foreach($files as $file){
    $data=json_decode((string)file_get_contents($file),true);
    if(!is_array($data)||($data['schema']??null)!==1||!is_array($data['operations']??null)){
        $failures[]=basename($file).': invalid batch schema';continue;
    }
    if(($data['complete_inventory']??false)!==true){$open++;}
    $seen=[];
    foreach($data['operations'] as $record){
        $entries++;
        $id=(string)($record['id']??'');
        if($id===''||isset($seen[$id])){$failures[]=basename($file).': missing/duplicate operation ID';continue;}
        $seen[$id]=true;
        $source=$record['source']??[];
        $impl=$record['implementation']??[];
        if(empty($source['path'])||empty($source['sha256'])||empty($source['symbols'])
           ||empty($impl['path'])||empty($impl['sha256'])||empty($record['tests'])){
            $failures[]=$id.': evidence incomplete';continue;
        }
        $implPath=$root.'/'.str_replace('\\','/',$impl['path']);
        $hash=is_file($implPath)?hash_file('sha256',$implPath):false;
        if($hash===false||!hash_equals(strtolower((string)$impl['sha256']),strtolower($hash))){
            $failures[]=$id.': implementation evidence missing or stale';
        }
        foreach($record['tests'] as $test){
            if(!is_file($root.'/'.str_replace('\\','/',$test)))$failures[]=$id.': test missing: '.$test;
        }
        if(($record['disposition']??'')==='verified'){$verified++;}else{$open++;}
    }
}
if($strict&&$open>0)$failures[]='Strict mode: '.$open.' open/partial inventory or behavior gates.';
echo json_encode(['ok'=>$failures===[],'batches'=>count($files),'operations'=>$entries,'verified'=>$verified,'open_gates'=>$open,'issues'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
