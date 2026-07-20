<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogMaintenanceJobHandler;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogStagedImportJobHandler;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogStorageMaintenanceJobHandler;
use UnrealDb\Catalog\Infrastructure\Jobs\GeneratedPackageJobHandler;
use UnrealDb\Catalog\Infrastructure\Jobs\UnverifiedDuplicateCleanupJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\WorkerJobQueue;

$options = getopt('', ['queue::', 'max-jobs::', 'sleep-ms::', 'worker-id::', 'lease-seconds::']);
$application = catalog_bootstrap();
$queueName = (string)($options['queue'] ?? ($application->config['queue']['name'] ?? 'catalog'));
$maxJobs = max(1, min((int)($options['max-jobs'] ?? 1), 10000));
$sleepMs = max(50, min((int)($options['sleep-ms'] ?? 1000), 60000));
$workerId = (string)($options['worker-id'] ?? (gethostname() . ':' . getmypid()));
$leaseSeconds = max(15, min((int)($options['lease-seconds'] ?? ($application->config['queue']['lease_seconds'] ?? 120)), 3600));

$queue = new WorkerJobQueue($application->db);
$worker = new JobWorker(
    $queue,
    [
        new CatalogStagedImportJobHandler($application->db, $application->config),
        new CatalogMaintenanceJobHandler($application->db, $application->config),
        new CatalogStorageMaintenanceJobHandler($application->db, $application->config),
        new UnverifiedDuplicateCleanupJobHandler($application->db, $application->config),
        new GeneratedPackageJobHandler($application->db, $application->config),
    ],
    $queueName,
    $workerId,
    $leaseSeconds
);

$processed = 0;
for ($index = 0; $index < $maxJobs; $index++) {
    $result = $worker->runOne();
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);

    if ($result['status'] === 'idle') {
        break;
    }
    $processed++;

    if ($index + 1 < $maxJobs && $sleepMs > 0) {
        usleep($sleepMs * 1000);
    }
}

exit($processed >= 0 ? 0 : 1);
