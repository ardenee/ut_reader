<?php
declare(strict_types=1);
/** Exercises real V5 repair-handler resume transitions in isolated SQLite. */
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Application\Jobs\JobQueue;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobDeferred;
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
    public function enqueue(string $queue, string $type, array $payload, int $priority=100, ?DateTimeImmutable $availableAt=null, ?string $dedupeKey=null, ?int $createdBy=null, int $maxAttempts=3, ?int $parentJobId=null, ?string $workflowUnitKey=null): int { throw new RuntimeException('Unexpected enqueue from isolated resume test'); }
    public function claim(string $queue, string $workerId, int $leaseSeconds, ?int $preferredRootJobId=null): ?ClaimedJob { return null; }
    public function complete(ClaimedJob $job, array $result=[]): string { return 'completed'; }
    public function fail(ClaimedJob $job, Throwable $exception, int $retryDelaySeconds): string { return 'dead_letter'; }
    public function defer(ClaimedJob $job, int $delaySeconds, array $progress=[]): void {}
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
            'failed_child' => 30,
            'missing_child' => 1,
            default => 0,
        };
        $results[$case] = ($expected === 'deferred' && $e->delaySeconds === $delay
            && ($case === 'missing_child' || ($e->progress['stage'] ?? '') === 'compact_repair_dependency_wait')
            && ($case !== 'failed_child' || ($e->progress['dependency_status'] ?? '') === 'failed'));
    } catch (Throwable $e) {
        $results[$case]=false;
        $results[$case.'_error']=$e->getMessage();
    }
};
$run('completed_child','completed','completed');
$run('queued_child','queued','deferred');
$run('failed_child','failed','deferred');
$run('missing_child',null,'deferred');
$failures=array_keys(array_filter($results,static fn($v):bool=>$v!==true));
echo json_encode(['ok'=>$failures===[],'sqlite_memory_only'=>true,'checks'=>$results,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failures===[]?0:2);
