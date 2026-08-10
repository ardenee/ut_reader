<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the command-line utility for catalog worker detached.
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

// Detached workers are durable process hosts. Individual jobs own their own
// leases/cancellation checkpoints; a web-server max_execution_time must never
// terminate a multi-hour CLI Full Sync or another legitimate background job.
@ini_set('max_execution_time', '0');
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

require_once dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobWorkerFactory;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogOrphanedJobRecovery;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogWorkerPoolSelfHealer;

$options = getopt('', [
    'queue::',
    'max-jobs::',
    'sleep-ms::',
    'worker-id::',
    'lease-seconds::',
    'worker-slot::',
    'worker-count::',
]);
$application = catalog_bootstrap(false);
$queueName = trim((string)($options['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
$requestedMaxJobs = (int)($options['max-jobs'] ?? 1000000);
$maxJobs = $requestedMaxJobs >= 10000 ? 1000000 : max(1, min($requestedMaxJobs, 1000000));
$sleepMs = max(50, min((int)($options['sleep-ms'] ?? 250), 60000));
$leaseSeconds = max(15, min((int)($options['lease-seconds'] ?? ($application->config['queue']['lease_seconds'] ?? 120)), 3600));
$workerSlot = max(1, min((int)($options['worker-slot'] ?? 1), CatalogDetachedWorker::MAX_WORKERS));
$workerCount = max(1, min((int)($options['worker-count'] ?? CatalogDetachedWorker::DEFAULT_WORKERS), CatalogDetachedWorker::MAX_WORKERS));
$workerId = trim((string)($options['worker-id'] ?? (
    'detached:' . (gethostname() ?: 'host') . ':' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $queueName)
    . ':slot-' . $workerSlot . ':' . getmypid()
)));

/**
 * An unsuccessful claim does not prove the queue is empty. Ready jobs may be
 * temporarily blocked by a resource-class limit or a concurrency key while
 * another worker is still processing related work.
 */
function catalog_detached_queue_has_pending_work(PDO $db, string $queueName): bool
{
    try {
        $statement = $db->prepare(
            'SELECT EXISTS('
            . 'SELECT 1 FROM ue_background_jobs '
            . 'WHERE queue_name=? AND status IN ("queued","running") LIMIT 1'
            . ')'
        );
        $statement->execute([$queueName]);
        return (int)$statement->fetchColumn() === 1;
    } catch (Throwable $error) {
        error_log(
            '[UnrealDB worker pending-work check] '
            . get_class($error) . ': ' . $error->getMessage()
        );

        // A transient status-query failure must not collapse the configured pool.
        return true;
    }
}

$controller = new CatalogDetachedWorker($application->config);
$poolHealer = new CatalogWorkerPoolSelfHealer($application->db, $application->config);
$codeVersion = $controller->codeVersion(true);
$lock = $controller->acquireWorkerLock($queueName, $workerSlot);
if (!is_resource($lock)) {
    fwrite(STDOUT, json_encode([
        'status' => 'already_running',
        'queue' => $queueName,
        'worker_slot' => $workerSlot,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
}

$processed = 0;
$attempted = 0;
$exitReason = 'queue_empty';
$lastResult = null;
$idlePasses = 0;
$startedAt = gmdate('c');
$normalExit = false;
$nextCodeCheckAt = microtime(true) + 5.0;
$nextPoolHealAt = microtime(true) + 10.0;
$shutdownReserve = str_repeat('R', 1024 * 1024);

register_shutdown_function(static function () use (
    &$shutdownReserve,
    &$normalExit,
    $application,
    $controller,
    $queueName,
    $workerId,
    $workerSlot,
    $workerCount,
    $codeVersion,
    $startedAt,
    &$processed,
    &$attempted
): void {
    if ($normalExit) {
        return;
    }
    $shutdownReserve = '';
    $last = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!is_array($last) || !in_array((int)($last['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    $message = trim((string)($last['message'] ?? ''));
    if ($message === '' || $message === "''" || $message === '""') {
        $message = 'Detached PHP worker terminated with an empty fatal-error message.';
    }
    $file = str_replace('\\', '/', trim((string)($last['file'] ?? '')));
    $line = max(0, (int)($last['line'] ?? 0));
    $error = 'Fatal PHP worker error: ' . $message;
    if ($file !== '') {
        $error .= ' at ' . $file . ($line > 0 ? ':' . $line : '');
    }

    $recovery = null;
    try {
        $recovery = (new CatalogOrphanedJobRecovery($application->db, $application->config))
            ->recordWorkerCrash($queueName, $workerId, $error);
    } catch (Throwable $recoveryError) {
        error_log('[UnrealDB worker shutdown recovery] ' . get_class($recoveryError) . ': ' . $recoveryError->getMessage());
    }

    try {
        $controller->writeState($queueName, [
            'status' => 'failed',
            'queue' => $queueName,
            'worker_id' => $workerId,
            'worker_slot' => $workerSlot,
            'worker_count' => $workerCount,
            'pid' => getmypid(),
            'processed' => $processed,
            'attempted' => $attempted,
            'code_version' => $codeVersion,
            'started_at' => $startedAt,
            'ended_at' => gmdate('c'),
            'exit_reason' => 'fatal_shutdown',
            'error' => $error,
            'error_file' => $file,
            'error_line' => $line,
            'crash_recovery' => $recovery,
        ], $workerSlot);
    } catch (Throwable $stateError) {
        error_log('[UnrealDB worker shutdown state] ' . get_class($stateError) . ': ' . $stateError->getMessage());
    }
    error_log('[UnrealDB worker fatal][slot ' . $workerSlot . '] ' . $error);
});

try {
    $controller->writeState($queueName, [
        'status' => 'running',
        'queue' => $queueName,
        'worker_id' => $workerId,
        'worker_slot' => $workerSlot,
        'worker_count' => $workerCount,
        'pid' => getmypid(),
        'max_jobs' => $maxJobs,
        'processed' => 0,
        'attempted' => 0,
        'code_version' => $codeVersion,
        'started_at' => $startedAt,
    ], $workerSlot);

    $worker = CatalogJobWorkerFactory::create(
        $application->db,
        $application->config,
        $queueName,
        $workerId,
        $leaseSeconds
    );

    while ($attempted < $maxJobs) {
        if ($controller->stopRequested($queueName, $workerSlot)) {
            $exitReason = 'stop_requested';
            break;
        }
        $now = microtime(true);
        if ($now >= $nextCodeCheckAt) {
            $nextCodeCheckAt = $now + 5.0;
            if (!hash_equals($codeVersion, $controller->codeVersion(true))) {
                $exitReason = 'code_changed';
                break;
            }
        }
        if ($now >= $nextPoolHealAt) {
            $nextPoolHealAt = $now + 10.0;
            try {
                $heal = $poolHealer->heal($queueName, $maxJobs);
                if (!empty($heal['healed'])) {
                    error_log(
                        '[UnrealDB worker self-heal][slot ' . $workerSlot . '] restored pool to '
                        . (int)($heal['active_count'] ?? 0) . '/' . (int)($heal['desired_count'] ?? $workerCount)
                    );
                }
            } catch (Throwable $healError) {
                error_log(
                    '[UnrealDB worker self-heal][slot ' . $workerSlot . '] '
                    . get_class($healError) . ': ' . $healError->getMessage()
                );
            }
        }

        $lastResult = $worker->runOne();
        $status = (string)($lastResult['status'] ?? 'unknown');
        if ($status === 'idle') {
            if (catalog_detached_queue_has_pending_work($application->db, $queueName)) {
                $idlePasses = 0;
            } else {
                $idlePasses++;
                if ($idlePasses >= 4) {
                    $exitReason = 'queue_empty';
                    break;
                }
            }

            usleep($sleepMs * 1000);
            continue;
        }

        $idlePasses = 0;
        $attempted++;
        if ($status === 'completed') {
            $processed++;
        }
        $controller->writeState($queueName, [
            'status' => 'running',
            'queue' => $queueName,
            'worker_id' => $workerId,
            'worker_slot' => $workerSlot,
            'worker_count' => $workerCount,
            'pid' => getmypid(),
            'max_jobs' => $maxJobs,
            'processed' => $processed,
            'attempted' => $attempted,
            'code_version' => $codeVersion,
            'started_at' => $startedAt,
            'last_result' => $lastResult,
        ], $workerSlot);

        if ($attempted >= $maxJobs) {
            $exitReason = 'max_jobs_reached';
            break;
        }
        // Do not sleep after a completed or failed attempt. A delayed retry has
        // its own available_at timestamp; idle claim scans already back off.
    }

    $controller->writeState($queueName, [
        'status' => 'stopped',
        'queue' => $queueName,
        'worker_id' => $workerId,
        'worker_slot' => $workerSlot,
        'worker_count' => $workerCount,
        'pid' => getmypid(),
        'max_jobs' => $maxJobs,
        'processed' => $processed,
        'attempted' => $attempted,
        'code_version' => $codeVersion,
        'started_at' => $startedAt,
        'ended_at' => gmdate('c'),
        'exit_reason' => $exitReason,
        'last_result' => $lastResult,
    ], $workerSlot);
    $controller->clearSlotStopRequest($queueName, $workerSlot);
    fwrite(STDOUT, json_encode([
        'status' => 'stopped',
        'queue' => $queueName,
        'worker_slot' => $workerSlot,
        'processed' => $processed,
        'attempted' => $attempted,
        'exit_reason' => $exitReason,
        'code_version' => $codeVersion,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    $normalExit = true;
    exit(0);
} catch (Throwable $error) {
    $message = trim($error->getMessage());
    if ($message === '' || $message === "''" || $message === '""') {
        $message = get_class($error) . ' was thrown without an error message.';
    }
    $file = str_replace('\\', '/', $error->getFile());
    $errorText = get_class($error) . ': ' . $message . ' at ' . $file . ':' . $error->getLine();
    $recovery = null;
    try {
        $recovery = (new CatalogOrphanedJobRecovery($application->db, $application->config))
            ->recordWorkerCrash($queueName, $workerId, $errorText);
    } catch (Throwable $recoveryError) {
        error_log('[UnrealDB worker exception recovery] ' . get_class($recoveryError) . ': ' . $recoveryError->getMessage());
    }

    $controller->writeState($queueName, [
        'status' => 'failed',
        'queue' => $queueName,
        'worker_id' => $workerId,
        'worker_slot' => $workerSlot,
        'worker_count' => $workerCount,
        'pid' => getmypid(),
        'processed' => $processed,
        'attempted' => $attempted,
        'code_version' => $codeVersion,
        'started_at' => $startedAt,
        'ended_at' => gmdate('c'),
        'exit_reason' => 'uncaught_exception',
        'error' => $errorText,
        'error_file' => $file,
        'error_line' => $error->getLine(),
        'crash_recovery' => $recovery,
    ], $workerSlot);
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'queue' => $queueName,
        'worker_slot' => $workerSlot,
        'error' => $errorText,
        'error_file' => $file,
        'error_line' => $error->getLine(),
        'code_version' => $codeVersion,
        'crash_recovery' => $recovery,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    $normalExit = true;
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
