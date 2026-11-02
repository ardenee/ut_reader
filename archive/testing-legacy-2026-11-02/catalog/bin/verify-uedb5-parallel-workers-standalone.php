<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDependencyRefreshJobHandler;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$id=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$id['port']!==3339||strtolower(str_replace(chr(92),'/',$id['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Wrong standalone DB');
$queueName='u5-parallel-20261015';
$mode=$argv[1]??'';
if($mode==='prepare'){
 $stmt=$db->prepare('SELECT COUNT(*) FROM ue_background_jobs WHERE queue_name=?');$stmt->execute([$queueName]);
 if((int)$stmt->fetchColumn()!==0)throw new RuntimeException('Parallel fixture already exists');
 $q=new PdoJobQueue($db);
 for($n=0;$n<40;$n++)$q->enqueue($queueName,JobType::REBUILD_FILE_DEPENDENCIES,['file_id'=>950000001+$n,'workflow_parent_job_id'=>940000000],100,null,null,null,1);
 echo json_encode(['ok'=>true,'mode'=>'prepare','jobs'=>40,'queue'=>$queueName]),PHP_EOL;
}elseif($mode==='work'){
 $wid=trim((string)($argv[2]??''));
 if(!in_array($wid,['A','B'],true))throw new RuntimeException('Worker A or B required');
 $q=new PdoJobQueue($db);$handler=new CatalogDependencyRefreshJobHandler($db,[]);
 $routes=array_fill_keys(JobType::all(),static function():array{return [];});
 $routes[JobType::REBUILD_FILE_DEPENDENCIES]=$handler;
 $routes[JobType::REBUILD_GAME_DEPENDENCIES]=$handler;
 $worker=new JobWorker($q,$routes,$queueName,'parallel-worker-'.$wid,120);
 $completed=[];$failed=[];
 for($n=0;$n<100;$n++){
  $r=$worker->runOne();
  if(($r['status']??'')==='idle')break;
  if(($r['status']??'')==='completed')$completed[]=(int)$r['job_id'];
  else $failed[]=$r;
  usleep(60000);
 }
 echo json_encode(['worker'=>$wid,'completed'=>$completed,'failed'=>$failed],JSON_UNESCAPED_SLASHES),PHP_EOL;
}elseif($mode==='verify'){
 $st=$db->prepare('SELECT status,attempts,COUNT(*) n FROM ue_background_jobs WHERE queue_name=? GROUP BY status,attempts');$st->execute([$queueName]);$rows=$st->fetchAll();
 $total=$db->prepare('SELECT COUNT(*) FROM ue_background_jobs WHERE queue_name=?');$total->execute([$queueName]);
 $count=(int)$total->fetchColumn();
 $passed=$count===40&&count($rows)===1&&($rows[0]['status']??'')==='completed'&&(int)($rows[0]['attempts']??0)===1&&(int)($rows[0]['n']??0)===40;
 echo json_encode(['ok'=>$passed,'total'=>$count,'grouped_states'=>$rows],JSON_PRETTY_PRINT),PHP_EOL;
 exit($passed?0:2);
}else throw new RuntimeException('Use prepare, work A|B, or verify');
