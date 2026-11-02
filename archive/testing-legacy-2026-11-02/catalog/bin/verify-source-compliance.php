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
$verifiedContractGates=0;$verifiedImplementationGates=0;$contractChecks=0;$contractTestRuns=[];
$verifiedConsumerGates=0;$consumerChecks=0;$closedReachabilityGates=0;$reachabilityAssertions=0;
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
        // Mixed-status original batches (B015 has two original operations)
        // carry per-operation acceptance without falsely closing siblings.
        $operationStatus=(string)($record['operation_status']??$data['status']??'partial');
        $operationComplete=($record['complete_inventory']??$data['complete_inventory']??false)===true;
        $gates=(array)($record['gate_status']??$data['gate_status']??[]);
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
        // A parser's behavioral gate may live in a different Epic source
        // file than the version-structure serializer. Verify every pinned
        // additional source anchor, not just the first/source-symbol presence.
        foreach ((array)($record['additional_sources']??[]) as $additional) {
            if (!is_array($additional)
                || empty($additional['path'])
                || empty($additional['sha256'])
                || empty($additional['symbols'])) {
                $failures[]=$id.': additional source evidence incomplete';
                continue;
            }
            if ($sourceRoot==='') { continue; }
            $additionalPath=rtrim($sourceRoot,'/').'/'
                .str_replace(chr(92),'/',(string)$additional['path']);
            $additionalHash=is_file($additionalPath)
                ? hash_file('sha256',$additionalPath) : false;
            $sourceChecks++;
            if($additionalHash===false
                ||!hash_equals(strtolower((string)$additional['sha256']),
                    strtolower((string)$additionalHash))){
                $failures[]=$id.': additional Epic source missing or changed: '
                    .$additional['path'];
            }
            if(is_file($additionalPath)){
                $additionalText=(string)file_get_contents($additionalPath);
                foreach($additional['symbols'] as $symbol){
                    $inventoryChecks++;
                    if(!is_string($symbol)||$symbol===''
                        ||strpos($additionalText,$symbol)===false){
                        $failures[]=$id.': additional Epic symbol absent: '.(string)$symbol;
                    }
                }
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
        // B009/B010 source reachability is bounded to the Zen header
        // contract. Verify the exhaustive production callsite scanner and
        // its negative controls before accepting an inventory gate.
        if(($gates['inventory']??false)===true){
            $reach=(array)($record['source_reachability_evidence']??[]);
            $driver=(string)($reach['test_driver']??'');
            $want=(array)($reach['expected_checks']??[]);
            $resolved=realpath($root.'/'.str_replace(chr(92),'/',$driver));
            $rootPath=realpath($root);
            if(!$operationComplete
                ||!is_string($resolved)||!is_string($rootPath)
                ||!str_starts_with(strtolower(str_replace(chr(92),'/',$resolved)),
                    strtolower(str_replace(chr(92),'/',$rootPath)).'/')
                ||$want===[]||!array_is_list($want)){
                $failures[]=$id.': closed inventory lacks verified bounded reachability evidence';
            } else {
                if(!array_key_exists($resolved,$contractTestRuns)){
                    $lines=[];$result=127;
                    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($resolved).' 2>&1',
                        $lines,$result);
                    $parsed=json_decode(implode("\n",$lines),true);
                    $contractTestRuns[$resolved]=[
                        'exit'=>$result,'result'=>is_array($parsed)?$parsed:[]
                    ];
                }
                $test=$contractTestRuns[$resolved];
                $pass=$test['exit']===0&&($test['result']['ok']??false)===true;
                foreach($want as $named){
                    $reachabilityAssertions++;
                    if(!is_string($named)
                        ||($test['result']['checks'][$named]??null)!==true){
                        $pass=false;
                        $failures[]=$id.': source/production caller evidence missing: '.(string)$named;
                    }
                }
                if(!$pass){$failures[]=$id.': reachability scanner did not verify inventory';}
                else{$closedReachabilityGates++;}
            }
        }
        // The original manifest gates are not self-attested. If a contract
        // or implementation gate is marked closed, execute every declared
        // source-branch regression and match its exact named outcome.
        if(($gates['contracts']??false)===true){
            $contract=(array)($record['source_contract_evidence']??[]);
            $driver=(string)($contract['test_driver']??'');
            $expected=(array)($contract['expected_checks']??[]);
            if($driver===''||$expected===[]||empty($contract['source_branches'])
                ||!array_is_list($expected)){
                $failures[]=$id.': closed source-contract gate lacks branch/check evidence';
            } else {
                $driverPath=realpath($root.'/'.str_replace(chr(92),'/',$driver));
                $safeRoot=realpath($root);
                if(!is_string($driverPath)||!is_string($safeRoot)
                    ||!str_starts_with(strtolower(str_replace(chr(92),'/',$driverPath)),
                        strtolower(str_replace(chr(92),'/',$safeRoot)).'/')){
                    $failures[]=$id.': unsafe or missing source-contract test driver';
                } else {
                    if(!array_key_exists($driverPath,$contractTestRuns)){
                        $lines=[];$result=127;
                        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($driverPath).' 2>&1',
                            $lines,$result);
                        $parsed=json_decode(implode("\n",$lines),true);
                        $contractTestRuns[$driverPath]=[
                            'exit'=>$result,'result'=>is_array($parsed)?$parsed:[]
                        ];
                    }
                    $test=$contractTestRuns[$driverPath];
                    if($test['exit']!==0||($test['result']['ok']??false)!==true){
                        $failures[]=$id.': source-contract test driver failed';
                    } else {
                        $passed=true;
                        foreach($expected as $check){
                            $contractChecks++;
                            if(!is_string($check)||$check===''
                                ||($test['result']['checks'][$check]??null)!==true){
                                $failures[]=$id.': source-contract branch not proven: '
                                    .(string)$check;
                                $passed=false;
                            }
                        }
                        if($passed){$verifiedContractGates++;}
                    }
                }
            }
        }
        if(($gates['implementation']??false)===true){
            // A source comparison can find NO discrepancy. Do not require
            // inventing a fix commit; require an independently authored,
            // pinned source-oracle driver with executed named assertions.
            $equiv=(array)($record['source_equivalence_evidence']??[]);
            $noChange=($equiv['conclusion']??'')==='no_source_difference_found'
                &&($equiv['independent_test_driver']??'')===
                    ($record['source_contract_evidence']['test_driver']??null)
                &&count((array)($equiv['required_source_checks']??[]))>=3
                &&!empty($record['source_contract_evidence']['expected_checks']);
            $driverPath=realpath($root.'/'.str_replace(chr(92),'/',
                (string)($equiv['independent_test_driver']??'')));
            $test=$driverPath!==false ? ($contractTestRuns[$driverPath]??null) : null;
            $evidenceOk=false;
            if($noChange&&is_array($test)
                &&($test['exit']??1)===0
                &&($test['result']['ok']??false)===true){
                $evidenceOk=true;
                foreach($equiv['required_source_checks'] as $case){
                    if(!is_string($case)||
                        ($test['result']['checks'][$case]??null)!==true){
                        $evidenceOk=false;
                        $failures[]=$id.': no-change implementation oracle missing '.(string)$case;
                    }
                }
            }
            if(($gates['contracts']??false)!==true
                ||empty($record['source_contract_evidence'])
                ||(empty($record['correction_commits'])&&!$evidenceOk)){
                $failures[]=$id.': implementation gate lacks proven correction or independent no-change oracle';
            } elseif($hash!==false && hash_equals(
                strtolower((string)$impl['sha256']),strtolower((string)$hash))){
                $verifiedImplementationGates++;
            } else {
                $failures[]=$id.': implementation hash differs from verified source contract';
            }
        }
        // A source-equivalent shared arbitrator can still be bypassed if
        // a physical selector or V5 rebuilder changes. Bind all declared
        // downstream implementations to the original gate's source hashes.
        if(($gates['implementation']??false)===true){
            foreach((array)($record['guarded_implementation_files']??[]) as $related){
                $relative=(string)($related['path']??'');
                $expected=strtolower((string)($related['sha256']??''));
                $candidate=realpath($root.'/'.str_replace(chr(92),'/',$relative));
                $safe=strtolower(str_replace(chr(92),'/',(string)realpath($root))).'/';
                if(!is_string($candidate)||!str_starts_with(
                    strtolower(str_replace(chr(92),'/',$candidate)),$safe)
                    ||strlen($expected)!==64||!ctype_xdigit($expected)
                    ||!hash_equals($expected,(string)hash_file('sha256',$candidate))){
                    $failures[]=$id.': downstream implementation evidence changed: '.$relative;
                }
            }
        }
        // B009/B010 consumer closure must survive both code changes and
        // regenerated test outcomes. Never count a manually toggled flag.
        if(($gates['consumer_validation']??false)===true){
            $consumer=(array)($record['consumer_validation_evidence']??[]);
            $file=(string)($consumer['implementation_path']??'');
            $sha=strtolower((string)($consumer['implementation_sha256']??''));
            $driver=(string)($consumer['test_driver']??'');
            $expected=(array)($consumer['expected_checks']??[]);
            $implReal=realpath($root.'/'.str_replace(chr(92),'/',$file));
            $testReal=realpath($root.'/'.str_replace(chr(92),'/',$driver));
            $safeRoot=strtolower(str_replace(chr(92),'/',(string)realpath($root))).'/';
            $inside=static fn($path):bool=>is_string($path)
                &&str_starts_with(strtolower(str_replace(chr(92),'/',$path)),$safeRoot);
            if(!$inside($implReal)||!$inside($testReal)||$expected===[]
                ||!array_is_list($expected)||!ctype_xdigit($sha)||strlen($sha)!==64){
                $failures[]=$id.': missing or unsafe consumer validation evidence';
            } elseif(!hash_equals($sha,(string)hash_file('sha256',$implReal))){
                $failures[]=$id.': consumer implementation changed; invalidate gate';
            }else {
                if(!array_key_exists($testReal,$contractTestRuns)){
                    $out=[];$code=127;
                    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($testReal).' 2>&1',
                        $out,$code);
                    $result=json_decode(implode("\n",$out),true);
                    $contractTestRuns[$testReal]=[
                        'exit'=>$code,'result'=>is_array($result)?$result:[]
                    ];
                }
                $test=$contractTestRuns[$testReal];
                $passed=$test['exit']===0&&($test['result']['ok']??false)===true;
                foreach($expected as $check){
                    $consumerChecks++;
                    if(!is_string($check)||($test['result']['checks'][$check]??null)!==true){
                        $failures[]=$id.': failed consumer field assertion '.(string)$check;
                        $passed=false;
                    }
                }
                if(!$passed){$failures[]=$id.': consumer validation test failed';}
                else{$verifiedConsumerGates++;}
            }
        }
        // Operation-level acceptance is executable, not a reviewer-shaped
        // blank field. Every declared independent source oracle, caller
        // inventory and SQL fixture must be rechecked on the current code.
        $accepted=($record['disposition']??'')==='verified';
        $independent=($gates['independent_verification']??false)===true;
        if($accepted||$independent){
            $proof=(array)($record['test_results']??[]);
            $evidence=(array)($record['independent_inventory_evidence']??[]);
            $driver=(string)($proof['test_driver']??'');
            $required=(array)($proof['expected_checks']??[]);
            $driverPath=realpath($root.'/'.str_replace(chr(92),'/',$driver));
            $safeRoot=realpath($root);
            $valid=$accepted&&$independent
                &&$operationStatus==='verified'
                &&$operationComplete
                &&($gates['inventory']??false)===true
                &&($gates['contracts']??false)===true
                &&($gates['implementation']??false)===true
                &&($gates['change_integrity']??false)===true
                &&!empty($record['source_reachability_evidence'])
                &&!empty($record['source_contract_evidence'])
                &&!empty($evidence['source_review'])
                &&!empty($evidence['scope_boundary'])
                &&$sourceRoot!==''
                &&count($required)>=5&&array_is_list($required)
                &&is_string($driverPath)&&is_string($safeRoot)
                &&str_starts_with(strtolower(str_replace(chr(92),'/',$driverPath)),
                    strtolower(str_replace(chr(92),'/',$safeRoot)).'/');
            if(!$valid){
                $failures[]=$id.': entire-operation acceptance lacks verified source scope, gates or independent driver';
            }else{
                if(!array_key_exists($driverPath,$contractTestRuns)){
                    $lines=[];$result=127;
                    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($driverPath).' 2>&1',
                        $lines,$result);
                    $parsed=json_decode(implode("\n",$lines),true);
                    $contractTestRuns[$driverPath]=[
                        'exit'=>$result,'result'=>is_array($parsed)?$parsed:[]
                    ];
                }
                $test=$contractTestRuns[$driverPath];
                $pass=($test['exit']??1)===0
                    &&($test['result']['ok']??false)===true
                    &&($test['result']['operation']??'')===$id;
                foreach($required as $case){
                    if(!is_string($case)||($test['result']['checks'][$case]??false)!==true){
                        $pass=false;
                        $failures[]=$id.': operation acceptance regression failed: '.(string)$case;
                    }
                }
                if(!$pass){$failures[]=$id.': operation-level independent acceptance driver failed';}
                else{$verified++;}
            }
        }else{$open++;}
    }
}
$recovery=null;
if (in_array('--all',$argv,true)) {
    // Source symbol/hash checks alone are not a compliance inventory. Reject
    // missing or internally inconsistent recovery accountability in --all.
    $validator=$root.'/catalog/bin/verify-compliance-recovery.php';
    $output=[];$code=127;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($validator).' 2>&1',$output,$code);
    $parsed=json_decode(implode("\n",$output),true);
    if($code!==0||!is_array($parsed)||($parsed['ok']??false)!==true){
        $failures[]='Recovery accountability failed: '.implode(' | ',
            array_slice(is_array($parsed)?(array)($parsed['issues']??[]):$output,0,4));
    } else {
        $recovery=[
            'scope_denominator_known'=>$parsed['scope_denominator_known']??false,
            'compliance_accepted'=>$parsed['compliance_accepted']??false,
            'registered_game_profiles'=>$parsed['game_profiles']??null,
            'unregistered_source_differences'=>$parsed['unregistered_source_differences']??[],
            'workflow_paths_unverified'=>$parsed['workflow_paths_unverified']??null,
            'coverage_gaps_open'=>$parsed['coverage_gaps_open']??null,
        ];
    }
}
if($strict&&$open>0)$failures[]='Strict mode: '.$open.' open/partial inventory or behavior gates.';
echo json_encode(['ok'=>$failures===[],'batches'=>count($files),'operations'=>$entries,'verified'=>$verified,
    'source_hash_checks'=>$sourceChecks,'source_symbol_checks'=>$inventoryChecks,
    'open_gates'=>$open,'closed_source_contract_gates'=>$verifiedContractGates,
    'closed_implementation_gates'=>$verifiedImplementationGates,
    'executed_contract_assertions'=>$contractChecks,
    'closed_source_reachability_gates'=>$closedReachabilityGates,
    'executed_reachability_assertions'=>$reachabilityAssertions,
    'closed_consumer_validation_gates'=>$verifiedConsumerGates,
    'executed_consumer_assertions'=>$consumerChecks,
    'recovery'=>$recovery,'issues'=>$failures],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
