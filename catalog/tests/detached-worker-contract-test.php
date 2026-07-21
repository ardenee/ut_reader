<?php
declare(strict_types=1);

function detached_worker_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require_once __DIR__ . '/../src/Infrastructure/Jobs/CatalogDetachedWorker.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-detached-worker-' . bin2hex(random_bytes(5));
detached_worker_expect(mkdir($root, 0777, true), 'Could not create detached worker test storage.');
$controller = new UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker([
    'storage_path' => $root,
    'queue' => ['lease_seconds' => 120],
]);

$status = $controller->status('catalog');
detached_worker_expect($status['active'] === false, 'A new detached worker runtime was incorrectly reported active.');
detached_worker_expect($status['stop_requested'] === false, 'A new detached worker runtime has a stale stop request.');

$stopped = $controller->requestStop('catalog');
detached_worker_expect($stopped['stop_requested'] === true, 'Detached worker stop request was not persisted.');
detached_worker_expect($controller->stopRequested('catalog'), 'Detached worker did not observe its stop request.');
$controller->clearStopRequest('catalog');
detached_worker_expect(!$controller->stopRequested('catalog'), 'Detached worker stop request was not cleared.');

$lock = $controller->acquireWorkerLock('catalog');
detached_worker_expect(is_resource($lock), 'Detached worker lock could not be acquired.');
detached_worker_expect($controller->status('catalog')['active'] === true, 'Held detached worker lock was not reported active.');
flock($lock, LOCK_UN);
fclose($lock);
detached_worker_expect($controller->status('catalog')['active'] === false, 'Released detached worker lock was still reported active.');

$runner = file_get_contents(__DIR__ . '/../api/v1/job-run.php');
detached_worker_expect(is_string($runner), 'Detached job-run endpoint could not be read.');
detached_worker_expect(str_contains($runner, 'CatalogDetachedWorker'), 'Job-run endpoint does not launch a detached CLI worker.');
detached_worker_expect(!str_contains($runner, 'runOne()'), 'Job-run endpoint still executes jobs inside the HTTP request.');

$workerScript = file_get_contents(__DIR__ . '/../bin/catalog-worker-detached.php');
detached_worker_expect(is_string($workerScript), 'Detached worker CLI script could not be read.');
foreach (['acquireWorkerLock', 'stopRequested', 'CatalogJobWorkerFactory::create', 'queue_empty'] as $fragment) {
    detached_worker_expect(str_contains($workerScript, $fragment), 'Detached worker script is missing ' . $fragment);
}

$upload = file_get_contents(__DIR__ . '/../profiled-upload.php');
detached_worker_expect(is_string($upload), 'Profiled upload page could not be read.');
detached_worker_expect(str_contains($upload, 'CatalogDetachedWorker'), 'Profiled upload does not auto-start the detached worker.');
detached_worker_expect(str_contains($upload, '$store->delete('), 'Profiled upload does not explicitly delete staging when queue creation fails.');

$pak = file_get_contents(__DIR__ . '/../pak-import.php');
detached_worker_expect(is_string($pak), 'PAK import page could not be read.');
detached_worker_expect(str_contains($pak, 'CatalogDetachedWorker'), 'PAK import does not auto-start the detached worker.');
detached_worker_expect(str_contains($pak, '$store->delete('), 'PAK import does not explicitly delete staging when queue creation fails.');

$backups = file_get_contents(__DIR__ . '/../game-backups.php');
detached_worker_expect(is_string($backups), 'Game backups page could not be read.');
detached_worker_expect(str_contains($backups, 'CatalogDetachedWorker'), 'Game backup export/import does not auto-start the detached worker.');
detached_worker_expect(str_contains($backups, 'EXPORT_GAME_BACKUP'), 'Game backup export job is not queued.');
detached_worker_expect(str_contains($backups, 'IMPORT_GAME_BACKUP'), 'Game backup import job is not queued.');

$runtime = $root . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'worker';
foreach (glob($runtime . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
    @unlink($path);
}
@rmdir($runtime);
@rmdir(dirname($runtime));
@rmdir($root);

echo "Detached worker contract tests passed.\n";
