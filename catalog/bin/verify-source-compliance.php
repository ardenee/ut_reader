<?php
declare(strict_types=1);
/** Local read-only verifier; does not claim to prove source-inventory completeness. */
$root=dirname(__DIR__,2);
$dir=$root.'/catalog/compliance/batches';
$strict=in_array('--strict',$argv,true);
$sourceRoot=getenv('UNREALDB_EPIC_SOURCE_ROOT')?:'';
$sourceChecks=0;
$files=glob($dir.'/*.json')?:[];
$failures=[];$open=0;$verified=0;$entries=0;$globalIds=[];$inventoryChecks=0;
if($strict && $sourceRoot==='')$failures[]='Strict mode requires UNREALDB_EPIC_SOURCE_ROOT for source hash verification.';
if($files===[])$failures[]='No audit batches exist.';
foreach($files as $file){
    $data=json_decode((string)file_get_contents($file),true);
    if(!is_array($data)||($data['schema']??null)!==1||!is_array($data['operations']??null)){
        $failures[]=basename($file).': invalid batch schema';continue;
    }
    if(($data['complete_inventory']??false)!==true){$open++;}
    if(($data['complete_inventory']??false)===true && empty($data['reconciliation_evidence']))$failures[]=basename($file).': complete inventory lacks reconciliation evidence';
    $seen=[];
    foreach($data['operations'] as $record){
        $entries++;
        $id=(string)($record['id']??'');
        if($id===''||isset($seen[$id])){$failures[]=basename($file).': missing/duplicate operation ID';continue;}
        $seen[$id]=true;
        if(isset($globalIds[$id]))$failures[]=$id.': duplicate operation across batches';
        $globalIds[$id]=true;
        $source=$record['source']??[];
        if(($record['disposition']??'')==='verified' && empty($record['test_results']))$failures[]=$id.': verified requires retained test results';
        if(($record['disposition']??'')==='verified' && empty($record['independent_inventory_evidence']))$failures[]=$id.': verified requires independent inventory evidence';
        $impl=$record['implementation']??[];
        if(empty($source['path'])||empty($source['sha256'])||empty($source['symbols'])
           ||empty($impl['path'])||empty($impl['sha256'])||empty($record['tests'])){
            $failures[]=$id.': evidence incomplete';continue;
        }
        if($sourceRoot!=='') {
            $sourcePath=rtrim($sourceRoot,'/').'/'.str_replace(chr(92),'/',$source['path']);
            $sourceHash=is_file($sourcePath)?hash_file('sha256',$sourcePath):false;
            $sourceChecks++;
            if(is_file($sourcePath)){
                $sourceText=file_get_contents($sourcePath);
                foreach($source['symbols'] as $symbol){
                    $inventoryChecks++;
                    if(!is_string($symbol)||$symbol===''||strpos((string)$sourceText,$symbol)===false)$failures[]=$id.': source symbol not found: '.(string)$symbol;
                }
            }
            if($sourceHash===false||!hash_equals(strtolower((string)$source['sha256']),strtolower($sourceHash))) {
                $failures[]=$id.': Epic source missing or changed';
            }
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
echo json_encode(['ok'=>$failures===[],'batches'=>count($files),'operations'=>$entries,'verified'=>$verified,'source_hash_checks'=>$sourceChecks,'source_symbol_checks'=>$inventoryChecks,'open_gates'=>$open,'issues'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
