<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

require_once __DIR__ . '/../bootstrap/autoload.php';

function job_test_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function job_test_db(): PDO
{
    $dsn = (string)(getenv('UNREALDB_TEST_DSN') ?: '');
    $user = (string)(getenv('UNREALDB_TEST_DB_USER') ?: '');
    $password = (string)(getenv('UNREALDB_TEST_DB_PASSWORD') ?: '');
    if ($dsn === '') {
        throw new RuntimeException('UNREALDB_TEST_DSN is required.');
    }
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function job_test_option(array $arguments, string $name): string
{
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with((string)$argument, $prefix)) {
            return substr((string)$argument, strlen($prefix));
        }
    }
    return '';
}

if (in_array('--claim-child', $argv, true)) {
    $queueName = job_test_option($argv, 'queue');
    $workerId = job_test_option($argv, 'worker');
    $startPath = job_test_option($argv, 'start');
    $readyPath = job_test_option($argv, 'ready');
    $resultPath = job_test_option($argv, 'result');
    if ($queueName === '' || $workerId === '' || $startPath === '' || $readyPath === '' || $resultPath === '') {
        exit(2);
    }
    file_put_contents($readyPath, 'ready');
    $deadline = microtime(true) + 15;
    while (!is_file($startPath) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (!is_file($startPath)) {
        file_put_contents($resultPath, 'timeout');
        exit(3);
    }
    $job = (new PdoJobQueue(job_test_db()))->claim($queueName, $workerId, 60);
    file_put_contents($resultPath, $job === null ? 'idle' : (string)$job->id);
    exit(0);
}

$dbA = job_test_db();
$dbB = job_test_db();
$queueA = new PdoJobQueue($dbA);
$queueB = new PdoJobQueue($dbB);
$prefix = 'ci_job_' . bin2hex(random_bytes(6));
$queues = [];

try {
    $queueName = $prefix . '_cancel';
    $queues[] = $queueName;
    $jobId = $queueA->enqueue($queueName, 'test.cancel', ['value' => 1], 100, null, 'cancel-active', null, 3);
    $job = $queueA->claim($queueName, 'worker-a', 60);
    job_test_expect($job !== null && $job->id === $jobId, 'Worker A did not claim the cancellation test job.');
    job_test_expect($queueA->heartbeat($job, 60, ['stage' => 'working', 'done' => 1, 'total' => 3]) === 'active', 'Active heartbeat failed.');
    $row = $dbA->query('SELECT progress_json,last_heartbeat_at FROM ue_background_jobs WHERE id=' . $jobId)->fetch();
    job_test_expect(is_array($row) && str_contains((string)$row['progress_json'], 'working'), 'Heartbeat progress was not persisted.');
    job_test_expect(!empty($row['last_heartbeat_at']), 'Heartbeat timestamp was not persisted.');
    job_test_expect($queueB->requestCancellation($jobId, null, 'Operator cancellation test.') === 'cancel_requested', 'Running cancellation was not requested.');
    job_test_expect($queueA->heartbeat($job, 60, ['stage' => 'stopping']) === 'cancel_requested', 'Worker did not observe cancellation at heartbeat.');
    $queueA->cancelClaimed($job, 'Operator cancellation test.');
    $row = $dbA->query('SELECT status,dedupe_key,worker_id,lease_token FROM ue_background_jobs WHERE id=' . $jobId)->fetch();
    job_test_expect(is_array($row) && $row['status'] === 'cancelled', 'Claimed job was not cancelled.');
    job_test_expect($row['dedupe_key'] === null && $row['worker_id'] === null && $row['lease_token'] === null, 'Cancelled job retained active ownership fields.');

    $queuedName = $prefix . '_queued_cancel';
    $queues[] = $queuedName;
    $queuedId = $queueA->enqueue($queuedName, 'test.queued_cancel', [], 100, null, 'queued-cancel');
    job_test_expect($queueB->requestCancellation($queuedId, null, 'Cancel before claim.') === 'cancelled', 'Queued job was not cancelled immediately.');
    job_test_expect($queueA->claim($queuedName, 'worker-a', 60) === null, 'Cancelled queued job was still claimable.');

    $retryName = $prefix . '_retry';
    $queues[] = $retryName;
    $retryId = $queueA->enqueue($retryName, 'test.retry', [], 100, null, 'retry-job', null, 2);
    $attemptOne = $queueA->claim($retryName, 'worker-a', 60);
    job_test_expect($attemptOne !== null && $attemptOne->attempt === 1, 'First retry attempt was not claimed.');
    job_test_expect($queueA->fail($attemptOne, new RuntimeException('attempt one'), 1) === 'retry_queued', 'First failure was not queued for retry.');
    $row = $dbA->query('SELECT status,worker_id,leased_at,lease_token FROM ue_background_jobs WHERE id=' . $retryId)->fetch();
    job_test_expect(is_array($row) && $row['status'] === 'queued', 'Retry job did not return to queued state.');
    job_test_expect($row['worker_id'] === null && $row['leased_at'] === null && $row['lease_token'] === null, 'Retry job retained previous lease ownership.');
    $dbA->exec('UPDATE ue_background_jobs SET available_at=UTC_TIMESTAMP() WHERE id=' . $retryId);
    $attemptTwo = $queueA->claim($retryName, 'worker-a', 60);
    job_test_expect($attemptTwo !== null && $attemptTwo->attempt === 2, 'Second retry attempt was not claimed.');
    job_test_expect($queueA->fail($attemptTwo, new RuntimeException('attempt two'), 1) === 'dead_letter', 'Exhausted job did not enter dead-letter state.');
    $row = $dbA->query('SELECT status,dead_lettered_at,dedupe_key FROM ue_background_jobs WHERE id=' . $retryId)->fetch();
    job_test_expect(is_array($row) && $row['status'] === 'dead_letter' && !empty($row['dead_lettered_at']), 'Dead-letter metadata was not recorded.');
    job_test_expect($row['dedupe_key'] === null, 'Dead-letter job retained its active dedupe key.');
    job_test_expect($queueB->retryDeadLetter($retryId), 'Dead-letter job could not be requeued.');
    $retried = $queueA->claim($retryName, 'worker-b', 60);
    job_test_expect($retried !== null && $retried->attempt === 1, 'Retried dead-letter job did not restart attempts.');
    job_test_expect($queueA->complete($retried, ['ok' => true]) === 'completed', 'Retried dead-letter job did not complete.');

    $recoveryName = $prefix . '_recovery';
    $queues[] = $recoveryName;
    $recoveryId = $queueA->enqueue($recoveryName, 'test.recovery', [], 100, null, null, null, 3);
    $staleLease = $queueA->claim($recoveryName, 'worker-stale', 60);
    job_test_expect($staleLease !== null, 'Recovery job was not claimed.');
    $dbA->exec('UPDATE ue_background_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 SECOND) WHERE id=' . $recoveryId);
    $recoveredCounts = $queueB->recoverExpiredLeases($recoveryName);
    job_test_expect($recoveredCounts['requeued'] === 1, 'Expired lease was not requeued.');
    $newLease = $queueB->claim($recoveryName, 'worker-new', 60);
    job_test_expect($newLease !== null && $newLease->id === $recoveryId, 'Recovered job was not claimed by the new worker.');
    $staleRejected = false;
    try {
        $queueA->complete($staleLease, ['stale' => true]);
    } catch (RuntimeException $error) {
        $staleRejected = str_contains($error->getMessage(), 'lease');
    }
    job_test_expect($staleRejected, 'Stale worker was allowed to complete a recovered job.');
    job_test_expect($queueB->complete($newLease, ['owner' => 'worker-new']) === 'completed', 'New lease owner could not complete recovered job.');

    $deadExpiryName = $prefix . '_dead_expiry';
    $queues[] = $deadExpiryName;
    $deadExpiryId = $queueA->enqueue($deadExpiryName, 'test.expired', [], 100, null, null, null, 1);
    job_test_expect($queueA->claim($deadExpiryName, 'worker-a', 60) !== null, 'Maximum-attempt expiry job was not claimed.');
    $dbA->exec('UPDATE ue_background_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 SECOND) WHERE id=' . $deadExpiryId);
    $expiredCounts = $queueB->recoverExpiredLeases($deadExpiryName);
    job_test_expect($expiredCounts['dead_lettered'] === 1, 'Expired maximum-attempt lease was not dead-lettered.');

    $cancelExpiryName = $prefix . '_cancel_expiry';
    $queues[] = $cancelExpiryName;
    $cancelExpiryId = $queueA->enqueue($cancelExpiryName, 'test.cancel_expiry', [], 100, null, null, null, 3);
    job_test_expect($queueA->claim($cancelExpiryName, 'worker-a', 60) !== null, 'Cancellation expiry job was not claimed.');
    job_test_expect($queueB->requestCancellation($cancelExpiryId, null, 'Worker disappeared after cancellation.') === 'cancel_requested', 'Cancellation was not recorded before lease expiry.');
    $dbA->exec('UPDATE ue_background_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 SECOND) WHERE id=' . $cancelExpiryId);
    $cancelCounts = $queueB->recoverExpiredLeases($cancelExpiryName);
    job_test_expect($cancelCounts['cancelled'] === 1, 'Expired cancelled lease was not finalized as cancelled.');

    $raceName = $prefix . '_race';
    $queues[] = $raceName;
    $raceId = $queueA->enqueue($raceName, 'test.race', [], 100, null, null, null, 3);
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-job-race-' . bin2hex(random_bytes(5));
    mkdir($dir, 0775, true);
    $start = $dir . DIRECTORY_SEPARATOR . 'start';
    $processes = [];
    $resultPaths = [];
    for ($index = 1; $index <= 2; $index++) {
        $ready = $dir . DIRECTORY_SEPARATOR . 'ready-' . $index;
        $result = $dir . DIRECTORY_SEPARATOR . 'result-' . $index;
        $resultPaths[] = $result;
        $command = [
            PHP_BINARY,
            __FILE__,
            '--claim-child',
            '--queue=' . $raceName,
            '--worker=race-worker-' . $index,
            '--start=' . $start,
            '--ready=' . $ready,
            '--result=' . $result,
        ];
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']], $pipes);
        job_test_expect(is_resource($process), 'Could not start competing claim process.');
        $processes[] = ['process' => $process, 'ready' => $ready];
    }
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        if (is_file($processes[0]['ready']) && is_file($processes[1]['ready'])) {
            break;
        }
        usleep(10000);
    }
    job_test_expect(is_file($processes[0]['ready']) && is_file($processes[1]['ready']), 'Competing claim processes did not reach the barrier.');
    file_put_contents($start, 'go');
    foreach ($processes as $entry) {
        job_test_expect(proc_close($entry['process']) === 0, 'Competing claim process failed.');
    }
    $claims = array_map(static fn(string $path): string => trim((string)file_get_contents($path)), $resultPaths);
    sort($claims);
    job_test_expect($claims === [(string)$raceId, 'idle'] || $claims === ['idle', (string)$raceId], 'Competing workers did not produce exactly one lease owner.');
    $dbA->exec('UPDATE ue_background_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 SECOND) WHERE id=' . $raceId);
    $queueA->recoverExpiredLeases($raceName);
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);

    fwrite(STDOUT, "Background job queue integration tests passed.\n");
} finally {
    if ($queues !== []) {
        $placeholders = implode(',', array_fill(0, count($queues), '?'));
        $statement = $dbA->prepare('DELETE FROM ue_background_jobs WHERE queue_name IN (' . $placeholders . ')');
        $statement->execute($queues);
    }
}
