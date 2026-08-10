#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies that queue contention cannot turn successfully completed handler work into a dead letter.
 * Role: Read-only regression gate for claim/recovery/completion contention handling; no database required.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$files = [
    'src/Application/Jobs/JobWorker.php',
    'src/Infrastructure/Persistence/PdoJobQueue.php',
    'src/Infrastructure/Persistence/PdoJobQueueSupport.php',
    'src/Infrastructure/Persistence/PdoJobRecovery.php',
];
$syntaxFailures = [];
foreach ($files as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $pipes = [];
    $process = function_exists('proc_open')
        ? proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes)
        : false;
    if (!is_resource($process)) {
        $syntaxFailures[] = $relative . ' could not be linted';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$worker = $read('src/Application/Jobs/JobWorker.php');
$queue = $read('src/Infrastructure/Persistence/PdoJobQueue.php');
$recovery = $read('src/Infrastructure/Persistence/PdoJobRecovery.php');

$handlerCall = strpos($worker, '$result = $handler->handle($job, $context);');
$handlerGenericCatch = $handlerCall === false ? false : strpos($worker, 'catch (\\Throwable $exception)', $handlerCall);
$completeCall = $handlerGenericCatch === false ? false : strpos($worker, '$this->queue->complete($job, $result)', $handlerGenericCatch);
$completionCatch = $completeCall === false ? false : strpos($worker, 'catch (\\Throwable $completionError)', $completeCall);
$recordFailureAfterComplete = $completionCatch === false
    ? false
    : strpos($worker, '$this->recordFailure(', $completionCatch);
$record(
    'handler_success_is_separate_from_completion_persistence',
    $handlerCall !== false
        && $handlerGenericCatch !== false
        && $completeCall !== false
        && $completionCatch !== false
        && $handlerCall < $handlerGenericCatch
        && $handlerGenericCatch < $completeCall
        && $recordFailureAfterComplete === false,
    'Once a handler returns successfully, failure to persist terminal queue state must never be routed through fail()/dead_letter.'
);

$record(
    'completion_deadlocks_retry_only_on_contention',
    str_contains($queue, 'COMPLETE_CONTENTION_ATTEMPTS')
        && str_contains($queue, 'PdoJobQueueSupport::retryableContention($exception)')
        && str_contains($queue, 'PdoJobQueueSupport::contentionBackoffMicros($attempt)')
        && str_contains($queue, 'return $this->leases->complete($job, $result);'),
    'Completion retries must be bounded and activated only by actual MySQL deadlock/lock-wait errors.'
);

$record(
    'claim_deadlocks_retry_only_on_contention',
    str_contains($queue, 'CLAIM_CONTENTION_ATTEMPTS')
        && str_contains($queue, 'return $this->claimer->claim($queue, $workerId, $leaseSeconds);')
        && substr_count($queue, 'PdoJobQueueSupport::retryableContention($exception)') >= 2,
    'MySQL choosing the claim/recovery transaction as deadlock victim must not crash a worker.'
);

$existsCheck = strpos($recovery, 'SELECT 1 FROM ue_background_jobs');
$beginTransaction = strpos($recovery, '$this->db->beginTransaction();');
$record(
    'lease_recovery_fast_path_is_read_only',
    $existsCheck !== false
        && $beginTransaction !== false
        && $existsCheck < $beginTransaction
        && str_contains($recovery, "return ['requeued' => 0, 'cancelled' => 0, 'dead_lettered' => 0];"),
    'When no lease is expired, claiming must avoid the three recovery UPDATE scans and any recovery write transaction.'
);

$record(
    'no_time_based_claim_throttle',
    !str_contains($queue, 'nextRecovery')
        && !str_contains($queue, 'lastRecovery')
        && !str_contains($recovery, 'nextRecovery')
        && !str_contains($recovery, 'lastRecovery')
        && !str_contains($recovery, 'sleep(')
        && !str_contains($recovery, 'usleep('),
    'Lease recovery must not be delayed by a timer/cadence throttle; an actually expired lease remains immediately recoverable.'
);

try {
    require_once $root . '/src/Infrastructure/Persistence/PdoJobQueueSupport.php';
    $deadlock = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
    $ordinary = new RuntimeException('Package parser rejected an invalid package');
    $class = UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueueSupport::class;
    $record(
        'contention_classifier',
        $class::retryableContention($deadlock) && !$class::retryableContention($ordinary),
        'Only database lock contention should trigger the queue retry backoff.'
    );
} catch (Throwable $error) {
    $record('contention_classifier', false, get_class($error) . ': ' . $error->getMessage());
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
