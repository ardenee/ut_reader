<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogMaintenanceJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

$options = getopt('', ['queue::', 'max-jobs::', 'sleep-ms::', 'worker-id::']);
$queueName = (string)($options['queue'] ?? 'catalog');
$maxJobs = max(1, min((int)($options['max-jobs'] ?? 1), 10000));
$sleepMs = max(50, min((int)($options['sleep-ms'] ?? 1000), 60000));
$workerId = (string)($options['worker-id'] ?? (gethostname() . ':' . getmypid()));

$application = catalog_bootstrap();
$queue = new PdoJobQueue($application->db);
$worker = new JobWorker(
    $queue,
    [new CatalogMaintenanceJobHandler($application->db, $application->config)],
    $queueName,
    $workerId,
    120
);

$completed = 0;
for ($index = 0; $index < $maxJobs; $index++) {
    $result = $worker->runOne();
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);

    if ($result['status'] === 'idle') {
        break;
    }
    $completed++;

    if ($index + 1 < $maxJobs && $sleepMs > 0) {
        usleep($sleepMs * 1000);
    }
}

exit($completed >= 0 ? 0 : 1);
