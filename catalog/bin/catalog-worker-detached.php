<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobWorkerFactory;

$options = getopt('', ['queue::', 'max-jobs::', 'sleep-ms::', 'worker-id::', 'lease-seconds::']);
$application = catalog_bootstrap();
$queueName = trim((string)($options['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
$maxJobs = max(1, min((int)($options['max-jobs'] ?? 10000), 10000));
$sleepMs = max(50, min((int)($options['sleep-ms'] ?? 250), 60000));
$leaseSeconds = max(15, min((int)($options['lease-seconds'] ?? ($application->config['queue']['lease_seconds'] ?? 120)), 3600));
$workerId = trim((string)($options['worker-id'] ?? ('detached:' . (gethostname() ?: 'host') . ':' . getmypid())));

$controller = new CatalogDetachedWorker($application->config);
$codeVersion = $controller->codeVersion();
$lock = $controller->acquireWorkerLock($queueName);
if (!is_resource($lock)) {
    fwrite(STDOUT, json_encode(['status' => 'already_running', 'queue' => $queueName], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
}

$processed = 0;
$exitReason = 'queue_empty';
$lastResult = null;
$idlePasses = 0;
$startedAt = gmdate('c');

try {
    $controller->writeState($queueName, [
        'status' => 'running',
        'queue' => $queueName,
        'worker_id' => $workerId,
        'pid' => getmypid(),
        'max_jobs' => $maxJobs,
        'processed' => 0,
        'code_version' => $codeVersion,
        'started_at' => $startedAt,
    ]);

    $worker = CatalogJobWorkerFactory::create(
        $application->db,
        $application->config,
        $queueName,
        $workerId,
        $leaseSeconds
    );

    for ($index = 0; $index < $maxJobs; $index++) {
        if ($controller->stopRequested($queueName)) {
            $exitReason = 'stop_requested';
            break;
        }
        if (!hash_equals($codeVersion, $controller->codeVersion())) {
            $exitReason = 'code_changed';
            break;
        }

        $lastResult = $worker->runOne();
        $status = (string)($lastResult['status'] ?? 'unknown');
        if ($status === 'idle') {
            $idlePasses++;
            if ($idlePasses >= 4) {
                $exitReason = 'queue_empty';
                break;
            }
            usleep($sleepMs * 1000);
            continue;
        }

        $idlePasses = 0;
        $processed++;
        $controller->writeState($queueName, [
            'status' => 'running',
            'queue' => $queueName,
            'worker_id' => $workerId,
            'pid' => getmypid(),
            'max_jobs' => $maxJobs,
            'processed' => $processed,
            'code_version' => $codeVersion,
            'started_at' => $startedAt,
            'last_result' => $lastResult,
        ]);

        if ($processed >= $maxJobs) {
            $exitReason = 'max_jobs_reached';
            break;
        }
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    $controller->writeState($queueName, [
        'status' => 'stopped',
        'queue' => $queueName,
        'worker_id' => $workerId,
        'pid' => getmypid(),
        'max_jobs' => $maxJobs,
        'processed' => $processed,
        'code_version' => $codeVersion,
        'started_at' => $startedAt,
        'ended_at' => gmdate('c'),
        'exit_reason' => $exitReason,
        'last_result' => $lastResult,
    ]);
    $controller->clearStopRequest($queueName);
    fwrite(STDOUT, json_encode([
        'status' => 'stopped',
        'queue' => $queueName,
        'processed' => $processed,
        'exit_reason' => $exitReason,
        'code_version' => $codeVersion,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    $controller->writeState($queueName, [
        'status' => 'failed',
        'queue' => $queueName,
        'worker_id' => $workerId,
        'pid' => getmypid(),
        'processed' => $processed,
        'code_version' => $codeVersion,
        'started_at' => $startedAt,
        'ended_at' => gmdate('c'),
        'error' => $error->getMessage(),
        'error_file' => str_replace('\\', '/', $error->getFile()),
        'error_line' => $error->getLine(),
    ]);
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'queue' => $queueName,
        'error' => $error->getMessage(),
        'error_file' => str_replace('\\', '/', $error->getFile()),
        'error_line' => $error->getLine(),
        'code_version' => $codeVersion,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
