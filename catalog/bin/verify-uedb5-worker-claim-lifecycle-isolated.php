<?php
declare(strict_types=1);
/** Exercise real dependency worker's safe file-unit gates without production writes. */
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Application\Jobs\JobQueue;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDependencyRefreshJobHandler;
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,package_name TEXT,original_name TEXT,scan_status TEXT)');
$db->exec('CREATE TABLE ue_background_jobs(id INTEGER PRIMARY KEY,job_type TEXT,parent_job_id INTEGER,workflow_unit_key TEXT)');
$db->prepare('INSERT INTO ue_background_jobs VALUES (100,?,NULL,NULL)')->execute([JobType::IMPORT_STAGED_PAK]);
$db->prepare('INSERT INTO ue_background_jobs VALUES (101,?,100,?)')->execute([JobType::REBUILD_GAME_DEPENDENCIES,'dependencies']);
$db->exec("INSERT INTO ue_files VALUES (321,7,'Fixture','Fixture.uasset','unverified')");
$queue=new class implements JobQueue{
 public array $progress=[]; public array $pending=[]; public array $states=[]; public array $heartbeatJobs=[];
 public function enqueue(string $queue,string $type,array $payload,int $priority=100,?DateTimeImmutable $availableAt=null,?string $dedupeKey=null,?int $createdBy=null,int $maxAttempts=3,?int $parentJobId=null,?string $workflowUnitKey=null):int{throw new RuntimeException('Unexpected enqueue');}
 public function claim(string $queue,string $workerId,int $leaseSeconds,?int $preferredRootJobId=null):?ClaimedJob{$job=array_shift($this->pending);if($job)$this->states[$job->id]='claimed';return $job;}
 public function complete(ClaimedJob $job,array $result=[]):string{$this->states[$job->id]='completed';return 'completed';}
 public function fail(ClaimedJob $job,Throwable $exception,int $retryDelaySeconds):string{$this->states[$job->id]='dead_letter';return 'dead_letter';}
 public function defer(ClaimedJob $job,int $delaySeconds,array $progress=[]):void{}
 public function heartbeat(ClaimedJob $job,int $leaseSeconds,array $progress=[]):string{$this->progress[]=$progress;$this->heartbeatJobs[$job->id]=true;return 'active';}
 public function requestCancellation(int $jobId,?int $requestedBy=null,string $reason=''):string{return 'not_found';}
 public function cancelClaimed(ClaimedJob $job,string $reason=''):void{}
 public function recoverExpiredLeases(string $queue):array{return ['requeued'=>0,'cancelled'=>0,'dead_lettered'=>0];}
 public function retryDeadLetter(int $jobId,?DateTimeImmutable $availableAt=null):bool{return false;}
};
$handler=new CatalogDependencyRefreshJobHandler($db,[]);
$case=static function(int $id,array $payload,?int $parent=null,?string $unit=null)use($handler,$queue):array{
 $job=new ClaimedJob($id,'isolated',JobType::REBUILD_FILE_DEPENDENCIES,$payload,
  'isolated-token',1,3,new DateTimeImmutable('+5 minutes'),parentJobId:$parent,workflowUnitKey:$unit);
 try{return ['result'=>$handler->handle($job,new JobExecutionContext($queue,$job,120))];}
 catch(Throwable $e){return ['exception'=>$e->getMessage()];}
};
$checks=[];
$removed=$case(501,['file_id'=>98765,'workflow_parent_job_id'=>100]);
$checks['removed_workflow_file_is_noop']=($removed['result']['status']??'')==='already_removed';
$orphan=$case(502,['file_id'=>98765]);
$checks['missing_manual_file_rejected']=str_contains((string)($orphan['exception']??''),'Verified file no longer exists: 98765');
$unverified=$case(503,['file_id'=>321,'workflow_parent_job_id'=>100]);
$checks['unverified_workflow_file_is_noop']=($unverified['result']['status']??'')==='already_removed';
$invalid=$case(504,['file_id'=>0]);
$checks['invalid_file_id_rejected']=str_contains((string)($invalid['exception']??''),'positive file_id');
$legacy=$case(505,['file_id'=>321],101,'dependency:321');
$checks['legacy_pak_dependency_is_superseded']=($legacy['result']['status']??'')==='superseded';
$checks['legacy_job_checkpoints_complete']=count($queue->progress)>0
 && ($queue->progress[array_key_last($queue->progress)]['stage']??'')==='complete';
$routes=array_fill_keys(JobType::all(),static function():array{return [];});
$routes[JobType::REBUILD_FILE_DEPENDENCIES]=$handler;
$routes[JobType::REBUILD_GAME_DEPENDENCIES]=$handler;
$worker=new JobWorker($queue,$routes,'isolated','isolated-worker');
$queue->pending[] = new ClaimedJob(601,'isolated',JobType::REBUILD_FILE_DEPENDENCIES,
 ['file_id'=>99999,'workflow_parent_job_id'=>100],'lease',1,1,new DateTimeImmutable('+5 minutes'));
$queue->pending[] = new ClaimedJob(602,'isolated',JobType::REBUILD_FILE_DEPENDENCIES,
 ['file_id'=>321],'lease',1,1,new DateTimeImmutable('+5 minutes'),
 parentJobId:101,workflowUnitKey:'dependency:321');
$queue->pending[] = new ClaimedJob(603,'isolated',JobType::REBUILD_FILE_DEPENDENCIES,
 ['file_id'=>0],'lease',1,1,new DateTimeImmutable('+5 minutes'));
$one=$worker->runOne();$two=$worker->runOne();$three=$worker->runOne();$idle=$worker->runOne();
$checks['worker_claim_and_complete_removed']=($one['status']??'')==='completed'
 && ($queue->states[601]??'')==='completed';
$checks['worker_claim_and_complete_superseded']=($two['status']??'')==='completed'
 && ($queue->states[602]??'')==='completed';
$checks['worker_records_invalid_failure']=($queue->states[603]??'')==='dead_letter';
$checks['worker_drained_queue_is_idle']=($idle['status']??'')==='idle';
$checks['worker_heartbeats_all_claims']=isset($queue->heartbeatJobs[601],$queue->heartbeatJobs[602],$queue->heartbeatJobs[603]);
$failures=array_keys(array_filter($checks,static fn(bool $ok):bool=>!$ok));
echo json_encode(['ok'=>$failures===[],'isolated_sqlite_only'=>true,'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:2);
