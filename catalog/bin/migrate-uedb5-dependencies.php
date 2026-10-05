#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';
require_once $root . '/lib/GameProfiles.php';
require_once $root . '/lib/CatalogUE4ParserProfile.php';
require_once $root . '/lib/CatalogUE5ParserProfile.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;

$options=getopt('',[
    'game-id:', 'game:', 'file-id::', 'apply', 'continuous', 'limit::', 'progress-every::', 'preflight',
    'workers::', 'worker-index::', 'skip-worker-preflight', 'force'
]);
$gameId=(int)($options['game-id'] ?? 0);
$fileId=max(0,(int)($options['file-id'] ?? 0));
$legacyGameSlug=trim((string)($options['game'] ?? ''));
if($gameId<1 && $legacyGameSlug===''){
    fwrite(STDERR,"Usage: php catalog/bin/migrate-uedb5-dependencies.php --game-id=3 [--file-id=143868] [--apply] [--force] [--continuous] [--limit=500] [--progress-every=50] [--workers=4] [--preflight]\n");
    exit(1);
}

$app=catalog_bootstrap();
if($gameId<1){
    $statement=$app->db->prepare('SELECT id FROM ue_games WHERE slug=? LIMIT 1');
    $statement->execute([$legacyGameSlug]);
    $value=$statement->fetchColumn();
    if($value===false){
        fwrite(STDERR,json_encode(['ok'=>false,'error'=>'Unknown game slug: '.$legacyGameSlug],JSON_UNESCAPED_SLASHES).PHP_EOL);
        exit(1);
    }
    $gameId=(int)$value;
}
$service=new Uedb5GameDependencyPassService($app->db,catalog_config());
$apply=isset($options['apply']);
$continuous=isset($options['continuous']);
$limit=max(1,min(5000,(int)($options['limit'] ?? 500)));
$progressEvery=max(1,(int)($options['progress-every'] ?? 50));
$workers=max(1,min(8,(int)($options['workers'] ?? 1)));
$workerIndex=array_key_exists('worker-index',$options)?(int)$options['worker-index']:null;
$skipWorkerPreflight=isset($options['skip-worker-preflight']);
$force=isset($options['force']);
if($force&&!$apply){fwrite(STDERR,json_encode(['ok'=>false,'error'=>'--force requires --apply'],JSON_UNESCAPED_SLASHES).PHP_EOL);exit(1);}
if($fileId>0){
    if($continuous||$workers!==1||$workerIndex!==null||isset($options['preflight'])||$skipWorkerPreflight){
        fwrite(STDERR,json_encode(['ok'=>false,'error'=>'--file-id cannot be combined with --continuous, worker-pool options, --skip-worker-preflight, or --preflight'],JSON_UNESCAPED_SLASHES).PHP_EOL);
        exit(1);
    }
    if($apply&&!$force){
        fwrite(STDERR,json_encode(['ok'=>false,'error'=>'Targeted --file-id writes require --force together with --apply'],JSON_UNESCAPED_SLASHES).PHP_EOL);
        exit(1);
    }
    try{
        $result=$service->runFile($gameId,$fileId,$apply,$skipWorkerPreflight);
        echo json_encode(['ok'=>true,'targeted'=>true,'summary'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit(0);
    }catch(Throwable $error){
        fwrite(STDERR,json_encode(['ok'=>false,'targeted'=>true,'file_id'=>$fileId,'error'=>$error->getMessage()],JSON_UNESCAPED_SLASHES).PHP_EOL);
        exit(1);
    }
}

if($workers>1 && $workerIndex===null && !isset($options['preflight'])){
    echo json_encode(['status'=>'pool_preflight_start','workers'=>$workers,'game_id'=>$gameId],JSON_UNESCAPED_SLASHES),PHP_EOL;
    fflush(STDOUT);
    $parentPreflight=$service->preflight($gameId);
    if(empty($parentPreflight['pass2_ready'])){
        throw new RuntimeException('Game failed UEDB5 Pass-2 readiness preflight before worker launch.');
    }
    echo json_encode([
        'status'=>'pool_preflight_complete','workers'=>$workers,'game_id'=>$gameId,
        'source_key'=>$parentPreflight['game']['source_key']??null,
        'dependency_complete_count'=>$parentPreflight['dependency_complete_count']??0,
        'dependency_remaining_count'=>$parentPreflight['dependency_remaining_count']??0,
        'verified_count'=>$parentPreflight['verified_count']??0,
    ],JSON_UNESCAPED_SLASHES),PHP_EOL;
    fflush(STDOUT);
    $children=[];
    for($index=0;$index<$workers;$index++){
        $command=[
            PHP_BINARY,__FILE__,'--game-id='.$gameId,'--workers='.$workers,'--worker-index='.$index,
            '--limit='.$limit,'--progress-every='.$progressEvery,'--skip-worker-preflight'
        ];
        if($apply){$command[]='--apply';}
        if($force){$command[]='--force';}
        if($continuous){$command[]='--continuous';}
        $process=proc_open($command,[0=>['file','NUL','r'],1=>STDOUT,2=>STDERR],$pipes,$root);
        if(!is_resource($process)){throw new RuntimeException('Could not start UEDB5 dependency worker #'.$index.'.');}
        $children[$index]=['process'=>$process];
        $status=proc_get_status($process);
        echo json_encode(['status'=>'worker_spawned','worker_index'=>$index,'pid'=>(int)($status['pid']??0)],JSON_UNESCAPED_SLASHES),PHP_EOL;
        fflush(STDOUT);
    }
    $exitCodes=[];$lastHeartbeat=microtime(true);
    while($children!==[]){
        foreach(array_keys($children) as $index){
            $status=proc_get_status($children[$index]['process']);
            if(!$status['running']){
                $exitCodes[$index]=(int)$status['exitcode'];
                proc_close($children[$index]['process']);
                unset($children[$index]);
            }
        }
        if($children!==[] && microtime(true)-$lastHeartbeat>=30.0){
            echo json_encode(['status'=>'pool_heartbeat','alive_workers'=>array_map('intval',array_keys($children))],JSON_UNESCAPED_SLASHES),PHP_EOL;
            fflush(STDOUT);
            $lastHeartbeat=microtime(true);
        }
        if($children!==[])usleep(50000);
    }
    ksort($exitCodes);
    $ok=!array_filter($exitCodes,static fn(int $code):bool=>$code!==0);
    echo json_encode(['ok'=>$ok,'workers'=>$workers,'worker_exit_codes'=>$exitCodes],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit($ok?0:2);
}

if($workerIndex!==null && ($workerIndex<0 || $workerIndex>=$workers)){
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>'worker-index must be between 0 and workers-1'],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
try{
    if(isset($options['preflight'])){
        echo json_encode(['ok'=>true,'preflight'=>$service->preflight($gameId)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit(0);
    }
    $emit=static function(array $row) use ($workerIndex):void{
        if($workerIndex!==null){$row['worker_index']=$workerIndex;}
        $row['memory_mb']=round(memory_get_usage(true)/1048576,1);
        echo json_encode($row,JSON_UNESCAPED_SLASHES),PHP_EOL;
        fflush(STDOUT);
    };
    $result=$service->run(
        $gameId,$apply,$limit,$continuous,$progressEvery,$emit,
        $workers,$workerIndex ?? 0,$skipWorkerPreflight,$force
    );
    $jsonFlags=JSON_UNESCAPED_SLASHES|($workerIndex===null?JSON_PRETTY_PRINT:0);
    echo json_encode(['ok'=>$result['failed']===0,'summary'=>$result],$jsonFlags),PHP_EOL;
    exit($result['failed']===0?0:2);
}catch(Throwable $error){
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
