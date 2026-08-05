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
worker_pool_expect(trim($controller->resolvedPhpBinary()) !== '', 'The detached worker PHP binary could not be resolved.');

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

$controller->writeState('catalog', [
    'status' => 'launching',
    'requested_at' => gmdate('c'),
    'code_version' => $controller->codeVersion(true),
], 3);
$launching = $controller->status('catalog');
worker_pool_expect((int)$launching['active_count'] === 0, 'A state file without a held process lock was reported as an active worker.');
worker_pool_expect((int)$launching['launching_count'] === 1, 'A recent launch request was not reported separately from an active process.');

$workerScript = file_get_contents(__DIR__ . '/../bin/catalog-worker-detached.php');
$launcher = file_get_contents(__DIR__ . '/../api/v1/job-run.php');
$statusApi = file_get_contents(__DIR__ . '/../api/v1/job-worker-status.php');
$workerAction = file_get_contents(__DIR__ . '/../api/v1/job-worker-action.php');
$policy = file_get_contents(__DIR__ . '/../src/Domain/Jobs/JobResourcePolicy.php');
$bridge = file_get_contents(__DIR__ . '/../assets/background-jobs-cursor-bridge.js');
$detachedWorker = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogDetachedWorker.php');
$baseline = file_get_contents(__DIR__ . '/../install.sql');

foreach (compact('workerScript', 'launcher', 'statusApi', 'workerAction', 'policy', 'bridge', 'detachedWorker', 'baseline') as $name => $source) {
    worker_pool_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

worker_pool_expect(
    str_contains($workerScript, "'worker-slot::'")
        && str_contains($workerScript, 'acquireWorkerLock($queueName, $workerSlot)')
        && str_contains($workerScript, 'stopRequested($queueName, $workerSlot)')
        && str_contains($workerScript, 'Do not sleep after a completed job')
        && substr_count($workerScript, 'usleep($sleepMs * 1000)') === 1
        && str_contains($workerScript, '$requestedMaxJobs >= 10000 ? 1000000'),
    'The CLI runner is not a per-slot worker or still delays every completed job.'
);

worker_pool_expect(
    str_contains($launcher, "(\$payload['workers'] ?? \$launcher->configuredWorkerCount())")
        && str_contains($launcher, '$launcher->start($queueName, $maxJobs, $workerCount)')
        && str_contains($launcher, "\$mode === 'next' ? 1 : 1000000"),
    'The job-run endpoint does not accept a bounded worker count.'
);

worker_pool_expect(
    (str_contains($statusApi, "'active_count'") || str_contains($statusApi, "worker['active_count']"))
        && str_contains($workerAction, "'terminated_workers'")
        && str_contains($statusApi, 'Status polling must never start, stop, recover or otherwise mutate the queue')
        && !str_contains($statusApi, '$launcher->start(')
        && !str_contains($statusApi, 'recoverInactiveQueue('),
    'Worker status is not read-only or worker pool status/stop APIs do not report multiple processes.'
);

worker_pool_expect(
    str_contains($detachedWorker, 'php_ini_loaded_file()')
        && str_contains($detachedWorker, "ini_get('extension_dir')")
        && str_contains($detachedWorker, "'launching_count'")
        && str_contains($detachedWorker, 'assertPhpBinary($php)'),
    'Detached worker launch does not resolve the current PHP installation or distinguish launching from active.'
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
    str_contains($baseline, 'resource_class')
        && str_contains($baseline, 'resource_limit')
        && str_contains($baseline, 'concurrency_key'),
    'The consolidated baseline does not include background-job resource controls.'
);

$runtime = $root . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'worker';
foreach (glob($runtime . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
    @unlink($path);
}
@rmdir($runtime);
@rmdir(dirname($runtime));
@rmdir($root);

echo "Background job worker-pool contract tests passed.\n";
