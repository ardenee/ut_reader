<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the command-line utility for catalog worker.
 * Why: It handles administrator, migration, verification, repair, generation, or worker work that should not execute
 *      as an interactive browser request.
 * Role: CLI/maintenance entry point used from the server shell or operational scripts.
 * Audit: Operational entry point; verify scheduled/manual usage before considering removal.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobWorkerFactory;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoWorkerOwnership;

$options = getopt('', ['queue::', 'max-jobs::', 'sleep-ms::', 'worker-id::', 'lease-seconds::']);
$application = catalog_bootstrap();
$queueName = (string)($options['queue'] ?? ($application->config['queue']['name'] ?? 'catalog'));
$maxJobs = max(1, min((int)($options['max-jobs'] ?? 1), 10000));
$sleepMs = max(50, min((int)($options['sleep-ms'] ?? 1000), 60000));
$workerId = (string)($options['worker-id'] ?? (gethostname() . ':' . getmypid()));
$leaseSeconds = max(15, min((int)($options['lease-seconds'] ?? ($application->config['queue']['lease_seconds'] ?? 120)), 3600));

$ownership = new PdoWorkerOwnership($application->db);
$ownershipLock = $ownership->acquire($queueName, $workerId);
register_shutdown_function(static function () use ($ownership, $ownershipLock): void {
    $ownership->release($ownershipLock);
});

$worker = CatalogJobWorkerFactory::create(
    $application->db,
    $application->config,
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
