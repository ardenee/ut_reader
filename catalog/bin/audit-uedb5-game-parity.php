#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameParityAuditService;

$options=getopt('',[
    'game:','preflight','max-details::','search-samples::','relation-samples::',
    'category:','list-categories','rerun','checkpoint::',
]);
$game=trim((string)($options['game']??''));
$categoryNames=[
    'dependencies',
    'requires_required_by',
    'base_game_missing',
    'package_aliases',
    'invalid_file_exclusions',
    'duplicate_provider_handling',
    'search_results',
    'verify_import_decisions',
];

if(isset($options['list-categories'])){
    echo implode(PHP_EOL,$categoryNames),PHP_EOL;
    exit(0);
}
if($game===''){
    fwrite(STDERR,
        "Usage: php catalog/bin/audit-uedb5-game-parity.php --game=ut99 [--preflight] "
        ."[--category=dependencies|all] [--category=search_results] [--rerun] "
        ."[--checkpoint=path] [--max-details=100] [--search-samples=150] [--relation-samples=500]\n"
    );
    exit(1);
}

$app=catalog_bootstrap();
$service=new Uedb5GameParityAuditService($app->db,catalog_config());

try{
    if(isset($options['preflight'])){
        echo json_encode(['ok'=>true,'preflight'=>$service->preflight($game)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit(0);
    }

    $maxDetails=max(1,min(5000,(int)($options['max-details']??100)));
    $searchSamples=max(3,min(1500,(int)($options['search-samples']??150)));
    $relationSamples=max(1,min(5000,(int)($options['relation-samples']??500)));

    $requested=$options['category']??[];
    if(!is_array($requested))$requested=[$requested];

    // Preserve the original CLI contract when no category mode is requested.
    if($requested===[]){
        $result=$service->audit($game,$maxDetails,$searchSamples,$relationSamples);
        echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit(!empty($result['ok'])?0:2);
    }

    $selected=[];
    foreach($requested as $raw){
        foreach(preg_split('/\s*,\s*/',(string)$raw,-1,PREG_SPLIT_NO_EMPTY)?:[] as $name){
            if($name==='all'){
                foreach($categoryNames as $category)$selected[$category]=true;
                continue;
            }
            if(!in_array($name,$categoryNames,true)){
                throw new InvalidArgumentException('Unknown parity category: '.$name.'. Use --list-categories.');
            }
            $selected[$name]=true;
        }
    }
    if($selected===[])throw new InvalidArgumentException('At least one parity category is required.');

    $preflight=$service->preflight($game);
    if(empty($preflight['ready'])){
        throw new RuntimeException('Game is not ready for Step 9 parity audit; require full V4/V5 coverage and current completed V5 dependency Pass 2.');
    }
    $gameId=(int)$preflight['game']['id'];
    $slug=(string)$preflight['game']['slug'];

    $definitions=[
        'dependencies'=>['auditDependencies',[$slug,$gameId,$maxDetails]],
        'requires_required_by'=>['auditRelations',[$slug,$gameId,$maxDetails,$relationSamples]],
        'base_game_missing'=>['auditBaseGameMissing',[$slug,$gameId,$maxDetails]],
        'package_aliases'=>['auditAliases',[$gameId,$maxDetails]],
        'invalid_file_exclusions'=>['auditInvalidExclusions',[$gameId,$maxDetails]],
        'duplicate_provider_handling'=>['auditDuplicateProviders',[$slug,$gameId,$maxDetails]],
        'search_results'=>['auditSearch',[$slug,$gameId,$searchSamples,$maxDetails]],
        'verify_import_decisions'=>['auditVerifyImportDecisions',[$slug,$gameId,$maxDetails]],
    ];

    $checkpoint=(string)($options['checkpoint']??'');
    if($checkpoint===''){
        $safeSlug=preg_replace('/[^a-z0-9_-]+/i','-',strtolower($slug))?:'game';
        $checkpoint=$root.'/storage/parity-audit-'.$safeSlug.'.json';
    }
    $checkpointDir=dirname($checkpoint);
    if(!is_dir($checkpointDir)&&!@mkdir($checkpointDir,0775,true)&&!is_dir($checkpointDir)){
        throw new RuntimeException('Could not create parity checkpoint directory: '.$checkpointDir);
    }

    $state=[
        'game'=>$preflight['game'],
        'preflight'=>$preflight,
        'started_at'=>date(DATE_ATOM),
        'categories'=>[],
    ];
    if(is_file($checkpoint)){
        $decoded=json_decode((string)file_get_contents($checkpoint),true);
        if(is_array($decoded)
            &&(int)($decoded['game']['id']??0)===$gameId
            &&isset($decoded['categories'])&&is_array($decoded['categories'])){
            $state['categories']=$decoded['categories'];
            $state['started_at']=$decoded['started_at']??$state['started_at'];
        }
    }

    $writeState=static function(string $path,array $state):void{
        $tmp=$path.'.tmp';
        $json=json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        if(!is_string($json)||file_put_contents($tmp,$json)===false){
            throw new RuntimeException('Could not write parity checkpoint: '.$tmp);
        }
        if(!@rename($tmp,$path)){
            @unlink($tmp);
            throw new RuntimeException('Could not replace parity checkpoint: '.$path);
        }
    };

    $rerun=isset($options['rerun']);
    foreach($categoryNames as $name){
        if(!isset($selected[$name]))continue;
        if(!$rerun&&!empty($state['categories'][$name]['completed'])){
            echo json_encode(['status'=>'skipped_checkpointed','category'=>$name],JSON_UNESCAPED_SLASHES),PHP_EOL;
            continue;
        }

        [$methodName,$args]=$definitions[$name];
        echo json_encode(['status'=>'started','category'=>$name,'at'=>date(DATE_ATOM)],JSON_UNESCAPED_SLASHES),PHP_EOL;
        $started=microtime(true);
        try{
            $method=new ReflectionMethod($service,$methodName);
            $result=$method->invokeArgs($service,$args);
            $entry=[
                'completed'=>true,
                'ok'=>!empty($result['ok']),
                'elapsed_seconds'=>round(microtime(true)-$started,3),
                'result'=>$result,
                'completed_at'=>date(DATE_ATOM),
            ];
        }catch(Throwable $error){
            $entry=[
                'completed'=>false,
                'ok'=>false,
                'elapsed_seconds'=>round(microtime(true)-$started,3),
                'error'=>$error->getMessage(),
                'failed_at'=>date(DATE_ATOM),
            ];
        }

        $state['categories'][$name]=$entry;
        $state['updated_at']=date(DATE_ATOM);
        $writeState($checkpoint,$state);
        echo json_encode([
            'status'=>$entry['completed']?'completed':'failed',
            'category'=>$name,
            'ok'=>$entry['ok'],
            'elapsed_seconds'=>$entry['elapsed_seconds'],
        ],JSON_UNESCAPED_SLASHES),PHP_EOL;

        if(!$entry['completed'])exit(2);
    }

    $selectedOk=true;
    foreach(array_keys($selected) as $name){
        $entry=$state['categories'][$name]??null;
        if(!is_array($entry)||empty($entry['completed'])||empty($entry['ok'])){
            $selectedOk=false;
            break;
        }
    }
    $allComplete=true;$allOk=true;
    foreach($categoryNames as $name){
        $entry=$state['categories'][$name]??null;
        if(!is_array($entry)||empty($entry['completed']))$allComplete=false;
        if(!is_array($entry)||empty($entry['ok']))$allOk=false;
    }
    $state['updated_at']=date(DATE_ATOM);
    $state['all_complete']=$allComplete;
    $state['ok']=$allComplete&&$allOk;
    $writeState($checkpoint,$state);

    echo json_encode([
        'status'=>'finished',
        'ok'=>$selectedOk,
        'all_complete'=>$allComplete,
        'all_categories_ok'=>$allComplete&&$allOk,
        'selected_categories'=>array_keys($selected),
        'rerun'=>$rerun,
        'checkpoint'=>$checkpoint,
        'categories'=>array_map(
            static fn(array $entry):array=>[
                'completed'=>(bool)($entry['completed']??false),
                'ok'=>(bool)($entry['ok']??false),
                'elapsed_seconds'=>$entry['elapsed_seconds']??null,
            ],
            $state['categories']
        ),
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit($selectedOk?0:2);
}catch(Throwable $error){
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
