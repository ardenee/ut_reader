<?php
declare(strict_types=1);
/** Exercises real V5 repair-handler resume transitions in isolated SQLite. */
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Application\Jobs\JobQueue;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobDeferred;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogCompactMetadataRepairJobHandler;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_files (id INTEGER PRIMARY KEY, game_id INTEGER, package_name TEXT, original_name TEXT, md5 TEXT, package_guid TEXT, scan_status TEXT)');
$db->exec('CREATE TABLE ue_background_jobs (id INTEGER PRIMARY KEY, parent_job_id INTEGER, workflow_unit_key TEXT, status TEXT, progress_json TEXT, result_json TEXT, last_error TEXT)');
$db->exec("INSERT INTO ue_files VALUES (321,7,'Fixture','Fixture.uasset','0123456789abcdef','', 'verified')");

$queue = new class implements JobQueue {
    public array $snapshots = [];
    public array $pending = [];
    public array $claims = [];
    public array $deferred = [];
    public function enqueue(string $queue, string $type, array $payload, int $priority=100, ?DateTimeImmutable $availableAt=null, ?string $dedupeKey=null, ?int $createdBy=null, int $maxAttempts=3, ?int $parentJobId=null, ?string $workflowUnitKey=null): int { throw new RuntimeException('Unexpected enqueue from isolated resume test'); }
    public function claim(string $queue, string $workerId, int $leaseSeconds, ?int $preferredRootJobId=null): ?ClaimedJob {
        $this->claims[] = $preferredRootJobId;
        if ($this->pending === []) { return null; }
        if ($preferredRootJobId !== null && $this->pending[0]->rootJobId() !== $preferredRootJobId) { return null; }
        return array_shift($this->pending);
    }
    public function complete(ClaimedJob $job, array $result=[]): string { return 'completed'; }
    public function fail(ClaimedJob $job, Throwable $exception, int $retryDelaySeconds): string { return 'dead_letter'; }
    public function defer(ClaimedJob $job, int $delaySeconds, array $progress=[]): void { $this->deferred[$job->id] = ['delay'=>$delaySeconds,'progress'=>$progress]; }
    public function heartbeat(ClaimedJob $job, int $leaseSeconds, array $progress=[]): string { $this->snapshots[]=$progress; return 'active'; }
    public function requestCancellation(int $jobId, ?int $requestedBy=null, string $reason=''): string { return 'not_found'; }
    public function cancelClaimed(ClaimedJob $job, string $reason=''): void {}
    public function recoverExpiredLeases(string $queue): array { return ['requeued'=>0,'cancelled'=>0,'dead_lettered'=>0]; }
    public function retryDeadLetter(int $jobId, ?DateTimeImmutable $availableAt=null): bool { return false; }
};

$handler = new CatalogCompactMetadataRepairJobHandler($db, []);
$results = [];
$run = static function (string $case, ?string $childStatus, string $expected) use ($db,$queue,$handler,&$results): void {
    $db->exec('DELETE FROM ue_background_jobs');
    if ($childStatus !== null) {
        $stmt=$db->prepare('INSERT INTO ue_background_jobs VALUES (444,77,?,?,"{}","{}","")');
        $stmt->execute(['dependencies',$childStatus]);
    }
    $job = new ClaimedJob(77,'isolated',JobType::REPAIR_COMPACT_METADATA_FILE,
        ['file_id'=>321],'isolated-lease',1,5,new DateTimeImmutable('+5 minutes'),
        resumeProgress:['stage'=>'compact_repair_dependency_wait','file_id'=>321,'repaired'=>true]);
    $ctx = new JobExecutionContext($queue,$job,120);
    try {
        $out=$handler->handle($job,$ctx);
        $results[$case]=($expected==='completed' && ($out['status']??'')==='completed'
            && ($out['repaired']??false)===true);
    } catch (JobDeferred $e) {
        $delay = match ($case) {
            'queued_child' => 2,
            'failed_child', 'cancelled_child', 'dead_letter_child' => 30,
            'missing_child' => 1,
            default => 0,
        };
        $terminalChild = in_array($case, ['failed_child', 'cancelled_child', 'dead_letter_child'], true);
        $results[$case] = ($expected === 'deferred' && $e->delaySeconds === $delay
            && $e->retainWorkerAffinity === !$terminalChild
            && ($case === 'missing_child' || ($e->progress['stage'] ?? '') === 'compact_repair_dependency_wait')
            && (!in_array($case, ['failed_child', 'cancelled_child', 'dead_letter_child'], true)
                || ($e->progress['dependency_status'] ?? '') === match ($case) {
                    'failed_child' => 'failed',
                    'cancelled_child' => 'cancelled',
                    'dead_letter_child' => 'dead_letter',
                }));
    } catch (Throwable $e) {
        $results[$case]=false;
        $results[$case.'_error']=$e->getMessage();
    }
};
$run('completed_child','completed','completed');
$run('queued_child','queued','deferred');
$run('failed_child','failed','deferred');
$run('cancelled_child','cancelled','deferred');
$run('dead_letter_child','dead_letter','deferred');
$run('missing_child',null,'deferred');
$removedJob = new ClaimedJob(78,'isolated',JobType::REPAIR_COMPACT_METADATA_FILE,
    ['file_id'=>999999],'isolated-lease',1,5,new DateTimeImmutable('+5 minutes'));
$removedResult = $handler->handle($removedJob, new JobExecutionContext($queue, $removedJob, 120));
$results['removed_file_noop'] = ($removedResult['status'] ?? '') === 'already_removed';
$invalidJob = new ClaimedJob(79,'isolated',JobType::REPAIR_COMPACT_METADATA_FILE,
    ['file_id'=>0],'isolated-lease',1,5,new DateTimeImmutable('+5 minutes'));
$invalidRejected = false;
try { $handler->handle($invalidJob, new JobExecutionContext($queue, $invalidJob, 120)); }
catch (RuntimeException $e) { $invalidRejected = str_contains($e->getMessage(), 'positive file_id'); }
$results['invalid_file_id_rejected'] = $invalidRejected;
// Execute the real JobWorker, not just the handler's deferred exception:
 // a blocked repair root must release affinity so unrelated work can proceed.
$db->exec('DELETE FROM ue_background_jobs');
$db->prepare('INSERT INTO ue_background_jobs VALUES (444,77,?,?,"{}","{}","")')
    ->execute(['dependencies','failed']);
$blocked = new ClaimedJob(77,'isolated',JobType::REPAIR_COMPACT_METADATA_FILE,
    ['file_id'=>321],'isolated-lease',1,5,new DateTimeImmutable('+5 minutes'),
    resumeProgress:['stage'=>'compact_repair_dependency_wait','file_id'=>321,'repaired'=>true]);
$unrelated = new ClaimedJob(78,'isolated',JobType::REPAIR_COMPACT_METADATA_FILE,
    ['file_id'=>999999],'isolated-lease',1,5,new DateTimeImmutable('+5 minutes'));
$queue->pending = [$blocked,$unrelated];
$handlers = array_fill_keys(JobType::all(),static function():array{return [];});
$handlers[JobType::REPAIR_COMPACT_METADATA_FILE] = $handler;
$worker = new JobWorker($queue,$handlers,'isolated','worker-isolated');
$first = $worker->runOne();
$second = $worker->runOne();
$results['worker_blocked_child_releases_affinity'] =
    ($first['status']??'')==='deferred'
    && ($first['affinity_held']??true)===false
    && ($queue->deferred[77]['delay']??0)===30;
$results['worker_runs_unrelated_root_after_terminal_child'] =
    ($second['status']??'')==='completed'
    && ($second['job_id']??0)===78
    && ($second['result']['status']??'')==='already_removed'
    && end($queue->claims)===null;
// An active child is different: preserve root affinity until its workflow advances.
$db->exec('DELETE FROM ue_background_jobs');
$db->prepare('INSERT INTO ue_background_jobs VALUES (445,77,? ,?,"{}","{}","")')
    ->execute(['dependencies','queued']);
$queue->pending = [$blocked,$unrelated];
$queue->claims = [];
$queue->deferred = [];
$worker = new JobWorker($queue,$handlers,'isolated','worker-isolated-active');
$activeFirst = $worker->runOne();
$activeSecond = $worker->runOne();
$results['worker_active_child_keeps_affinity'] =
    ($activeFirst['status']??'')==='deferred'
    && ($activeFirst['affinity_held']??false)===true
    && ($queue->deferred[77]['delay']??0)===2;
$results['worker_active_root_blocks_unrelated_claim'] =
    ($activeSecond['status']??'')==='idle'
    && ($activeSecond['affinity_held']??false)===true
    && ($activeSecond['root_job_id']??0)===77
    && count($queue->pending)===1
    && end($queue->claims)===77;
$failures=array_keys(array_filter($results,static fn($v):bool=>$v!==true));
echo json_encode(['ok'=>$failures===[],'sqlite_memory_only'=>true,'checks'=>$results,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failures===[]?0:2);
