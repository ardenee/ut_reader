<?php
declare(strict_types=1);

function worker_pool_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require_once __DIR__ . '/../src/Infrastructure/Jobs/CatalogDetachedWorker.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-worker-pool-' . bin2hex(random_bytes(5));
worker_pool_expect(mkdir($root, 0777, true), 'Could not create worker-pool test storage.');
$controller = new CatalogDetachedWorker([
    'storage_path' => $root,
    'queue' => ['lease_seconds' => 120],
]);

worker_pool_expect($controller->configuredWorkerCount() === 4, 'The worker pool does not default to four processes.');
worker_pool_expect($controller->normalizeWorkerCount(0) === 1, 'Worker count lower bound is not one.');
worker_pool_expect($controller->normalizeWorkerCount(99) === 8, 'Worker count upper bound is not eight.');

$first = $controller->acquireWorkerLock('catalog', 1);
$second = $controller->acquireWorkerLock('catalog', 2);
worker_pool_expect(is_resource($first) && is_resource($second), 'Independent worker-slot locks could not be acquired.');
$status = $controller->status('catalog');
worker_pool_expect((int)$status['active_count'] === 2, 'The aggregate worker status did not count two active slots.');
worker_pool_expect(count((array)$status['workers']) >= 4, 'The aggregate worker status does not expose the configured pool slots.');

$controller->requestSlotStop('catalog', 2);
worker_pool_expect($controller->stopRequested('catalog', 2), 'A worker-slot stop request was not persisted.');
worker_pool_expect(!$controller->stopRequested('catalog', 1), 'A slot stop incorrectly stopped another worker.');
$controller->clearSlotStopRequest('catalog', 2);
worker_pool_expect(!$controller->stopRequested('catalog', 2), 'A worker-slot stop request was not cleared.');

flock($first, LOCK_UN);
fclose($first);
flock($second, LOCK_UN);
fclose($second);

$workerScript = file_get_contents(__DIR__ . '/../bin/catalog-worker-detached.php');
$launcher = file_get_contents(__DIR__ . '/../api/v1/job-run.php');
$statusApi = file_get_contents(__DIR__ . '/../api/v1/job-worker-status.php');
$workerAction = file_get_contents(__DIR__ . '/../api/v1/job-worker-action.php');
$policy = file_get_contents(__DIR__ . '/../src/Domain/Jobs/JobResourcePolicy.php');
$bridge = file_get_contents(__DIR__ . '/../assets/background-jobs-cursor-bridge.js');
$migration = file_get_contents(__DIR__ . '/../migrations/202607310003_background_job_worker_pool.php');

foreach (compact('workerScript', 'launcher', 'statusApi', 'workerAction', 'policy', 'bridge', 'migration') as $name => $source) {
    worker_pool_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

worker_pool_expect(
    str_contains($workerScript, "'worker-slot::'")
        && str_contains($workerScript, 'acquireWorkerLock($queueName, $workerSlot)')
        && str_contains($workerScript, 'stopRequested($queueName, $workerSlot)')
        && str_contains($workerScript, 'Do not sleep after a completed job')
        && substr_count($workerScript, 'usleep($sleepMs * 1000)') === 1,
    'The CLI runner is not a per-slot worker or still delays every completed job.'
);

worker_pool_expect(
    str_contains($launcher, "(\$payload['workers'] ?? \$launcher->configuredWorkerCount())")
        && str_contains($launcher, '$launcher->start($queueName, $maxJobs, $workerCount)'),
    'The job-run endpoint does not accept a bounded worker count.'
);

worker_pool_expect(
    (str_contains($statusApi, "'active_count'") || str_contains($statusApi, "worker['active_count']"))
        && str_contains($workerAction, "'terminated_workers'"),
    'Worker pool status/stop APIs do not report multiple processes.'
);

worker_pool_expect(
    str_contains($policy, "BUCKET_PROCESSING = 'bucket-processing'")
        && str_contains($policy, 'configuredLimit(self::BUCKET_PROCESSING, 8)')
        && str_contains($policy, 'bucketFileKey($payload)')
        && !str_contains($policy, "? 'bucket-processing'"),
    'Upload Bucket jobs are not protected by an eight-slot per-file resource policy.'
);

worker_pool_expect(
    str_contains($bridge, "workerCountSelect.id = 'jobs-worker-count'")
        && str_contains($bridge, 'for (let count = 1; count <= 8; count++)')
        && str_contains($bridge, "body.workers = selectedWorkers()")
        && str_contains($bridge, "applyWorkersButton.textContent = 'Apply workers'"),
    'Background Jobs does not expose and apply a 1-8 worker selector.'
);

worker_pool_expect(
    str_contains($migration, "'version' => '202607310003'")
        && str_contains($migration, 'resource_class="bucket-processing"')
        && str_contains($migration, 'resource_limit=8')
        && str_contains($migration, 'SHA2('),
    'Existing queued Upload Bucket jobs are not migrated to per-file concurrency.'
);

$runtime = $root . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'worker';
foreach (glob($runtime . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
    @unlink($path);
}
@rmdir($runtime);
@rmdir(dirname($runtime));
@rmdir($root);

echo "Background job worker-pool contract tests passed.\n";
