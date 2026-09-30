#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';
require_once $root . '/lib/GameProfiles.php';
require_once $root . '/lib/CatalogUE4ParserProfile.php';
require_once $root . '/lib/CatalogUE5ParserProfile.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceMigrationService;

$options=getopt('',[
    'game:', 'apply', 'continuous', 'limit::', 'progress-every::', 'preflight', 'workers::', 'worker-index::', 'skip-worker-preflight'
]);
$game=trim((string)($options['game'] ?? ''));
if($game===''){
    fwrite(STDERR,"Usage: php catalog/bin/migrate-uedb5-game.php --game=ut99 [--apply] [--continuous] [--limit=1000] [--progress-every=100] [--workers=4] [--preflight]\n");
    exit(1);
}
$apply=isset($options['apply']);
$continuous=isset($options['continuous']);
$limit=max(1,min(5000,(int)($options['limit'] ?? 1000)));
$progressEvery=max(1,(int)($options['progress-every'] ?? 100));
$workers=max(1,min(8,(int)($options['workers'] ?? 1)));
$workerIndex=array_key_exists('worker-index',$options)?(int)$options['worker-index']:null;
$skipWorkerPreflight=isset($options['skip-worker-preflight']);

if($workers>1 && $workerIndex===null && !isset($options['preflight'])){
    echo json_encode(['status'=>'pool_preflight_start','workers'=>$workers,'game'=>$game],JSON_UNESCAPED_SLASHES),PHP_EOL; fflush(STDOUT);
    $parentApp=catalog_bootstrap();
    $parentService=new Uedb5GameSourceMigrationService($parentApp->db,catalog_config());
    $parentPreflight=$parentService->preflight($game);
    if(empty($parentPreflight['v4_ready'])){ throw new RuntimeException('Game failed V4 readiness preflight before worker launch.'); }
    echo json_encode(['status'=>'pool_preflight_complete','workers'=>$workers,'staged_count'=>$parentPreflight['staged_count']??0,'verified_count'=>$parentPreflight['verified_count']??0],JSON_UNESCAPED_SLASHES),PHP_EOL; fflush(STDOUT);
    $children=[];
    for($index=0;$index<$workers;$index++){
        $command=[PHP_BINARY,__FILE__,'--game='.$game,'--workers='.$workers,'--worker-index='.$index,'--limit='.$limit,'--progress-every='.$progressEvery];
        $command[]='--skip-worker-preflight';
        if($apply){$command[]='--apply';}
        if($continuous){$command[]='--continuous';}
        $pipes=[];
        $process=proc_open($command,[0=>['file','NUL','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
        if(!is_resource($process)){throw new RuntimeException('Could not start UEDB5 worker #'.$index.'.');}
        stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $children[$index]=['process'=>$process,'stdout'=>$pipes[1],'stderr'=>$pipes[2],'stdout_buffer'=>'','stderr_buffer'=>''];
        $status=proc_get_status($process);
        echo json_encode(['status'=>'worker_spawned','worker_index'=>$index,'pid'=>(int)($status['pid']??0)],JSON_UNESCAPED_SLASHES),PHP_EOL; fflush(STDOUT);
    }
    $exitCodes=[];$lastHeartbeat=microtime(true);
    while($children!==[]){
        foreach(array_keys($children) as $index){
            foreach(['stdout','stderr'] as $streamName){
                $stream=$children[$index][$streamName];
                $data=stream_get_contents($stream);
                if($data!==false&&$data!==''){
                    $bufferKey=$streamName.'_buffer';$children[$index][$bufferKey].=$data;
                    while(($pos=strpos($children[$index][$bufferKey],"\n"))!==false){
                        $line=trim(substr($children[$index][$bufferKey],0,$pos));
                        $children[$index][$bufferKey]=substr($children[$index][$bufferKey],$pos+1);
                        if($line==='')continue;
                        $decoded=json_decode($line,true);
                        if(is_array($decoded)){
                            $decoded['worker_index']=$index;
                            echo json_encode($decoded,JSON_UNESCAPED_SLASHES),PHP_EOL;
                        }else{
                            fwrite($streamName==='stderr'?STDERR:STDOUT,'[worker '.$index.'] '.$line.PHP_EOL);
                        }
                    }
                }
            }
            $status=proc_get_status($children[$index]['process']);
            if(!$status['running']){
                foreach(['stdout','stderr'] as $streamName){
                    $tail=trim((string)stream_get_contents($children[$index][$streamName]));
                    if($tail!=='')fwrite($streamName==='stderr'?STDERR:STDOUT,'[worker '.$index.'] '.$tail.PHP_EOL);
                    fclose($children[$index][$streamName]);
                }
                $exitCodes[$index]=(int)$status['exitcode'];
                proc_close($children[$index]['process']);
                unset($children[$index]);
            }
        }
        if($children!==[] && microtime(true)-$lastHeartbeat>=30.0){
            echo json_encode(['status'=>'pool_heartbeat','alive_workers'=>array_map('intval',array_keys($children))],JSON_UNESCAPED_SLASHES),PHP_EOL; fflush(STDOUT);
            $lastHeartbeat=microtime(true);
        }
        if($children!==[])usleep(50000);
    }
    ksort($exitCodes);$ok=!array_filter($exitCodes,static fn(int $code):bool=>$code!==0);
    echo json_encode(['ok'=>$ok,'workers'=>$workers,'worker_exit_codes'=>$exitCodes],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit($ok?0:2);
}

if($workerIndex!==null && ($workerIndex<0 || $workerIndex>=$workers)){
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>'worker-index must be between 0 and workers-1'],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
$app=catalog_bootstrap();
$service=new Uedb5GameSourceMigrationService($app->db,catalog_config());
try{
    if(isset($options['preflight'])){
        echo json_encode(['ok'=>true,'preflight'=>$service->preflight($game)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit(0);
    }
    $emit=static function(array $row):void{
        $row['memory_mb']=round(memory_get_usage(true)/1048576,1);
        echo json_encode($row,JSON_UNESCAPED_SLASHES),PHP_EOL;
        fflush(STDOUT);
    };
    $result=$service->migrate($game,$apply,$limit,$continuous,$progressEvery,$emit,$workers,$workerIndex ?? 0,$skipWorkerPreflight);
    $jsonFlags=JSON_UNESCAPED_SLASHES|($workerIndex===null?JSON_PRETTY_PRINT:0);
    echo json_encode(['ok'=>$result['failed']===0,'summary'=>$result],$jsonFlags),PHP_EOL;
    exit($result['failed']===0?0:2);
}catch(Throwable $error){
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
