<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDependencyRefreshJobHandler;
$make=static function():PDO{
 $db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $id=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
 if((int)$id['port']!==3339||strtolower(str_replace(chr(92),'/',$id['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Wrong MySQL');
 return $db;
};
$a=$make();$b=$make();$q1=new PdoJobQueue($a);$q2=new PdoJobQueue($b);
$name='u5-concurrent-'.bin2hex(random_bytes(6));
$ids=[];foreach([901100001,901100002] as $file)$ids[]=$q1->enqueue($name,JobType::REBUILD_FILE_DEPENDENCIES,['file_id'=>$file,'workflow_parent_job_id'=>100000],100,null,null,null,2);
$first=$q1->claim($name,'worker-A',120);
$second=$q2->claim($name,'worker-B',120);
$heartbeat=$first!==null?$q1->heartbeat($first,120,['fixture'=>'heartbeat']):'missing';
$complete=$first!==null?$q1->complete($first,['status'=>'first_completed']):'missing';
$complete2=$second!==null?$q2->complete($second,['status'=>'second_completed']):'missing';
$failing=$q1->enqueue($name,JobType::REBUILD_FILE_DEPENDENCIES,['file_id'=>0],100,null,null,null,1);
$handler=new CatalogDependencyRefreshJobHandler($a,[]);
$routes=array_fill_keys(JobType::all(),static function():array{return [];});
$routes[JobType::REBUILD_FILE_DEPENDENCIES]=$handler;
$routes[JobType::REBUILD_GAME_DEPENDENCIES]=$handler;
$worker=new JobWorker($q1,$routes,$name,'worker-error',120);
$errorRun=$worker->runOne();
$dead=$a->prepare('SELECT status,attempts FROM ue_background_jobs WHERE id=?');$dead->execute([$failing]);$deadRow=$dead->fetch();
$retried=$q1->retryDeadLetter($failing);
$retryRun=$worker->runOne();
$dead->execute([$failing]);$retryRow=$dead->fetch();
$passed=[
 'distinct_connections_claim_distinct_jobs'=>$first!==null&&$second!==null&&$first->id!==$second->id,
 'heartbeat_persisted'=>is_string($heartbeat)&&$heartbeat!=='missing',
 'both_complete'=>is_string($complete)&&is_string($complete2)&&$complete==='completed'&&$complete2==='completed',
 'invalid_job_dead_lettered'=>($deadRow['status']??'')==='dead_letter',
 'dead_letter_retry_accepted'=>$retried===true,
 'retried_job_dead_lettered_again'=>($retryRow['status']??'')==='dead_letter'
];
$ok=!in_array(false,$passed,true);
echo json_encode(['ok'=>$ok,'checks'=>$passed,'claimed_job_ids'=>[$first?->id,$second?->id],'heartbeat'=>$heartbeat,'completions'=>[$complete,$complete2],'error_run'=>$errorRun,'retry_run'=>$retryRun,'dead_letter_before'=>$deadRow,'after'=>$retryRow],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
