<?php
/**
 * Manual MySQL integration verification for durable queue admission/ownership.
 *
 * This test creates only uniquely named temporary queue rows/resource settings,
 * launches real concurrent PHP claimers, verifies the invariants, and cleans up
 * in a finally block. It is intentionally not wired to GitHub Actions.
 *
 * Usage:
 *   php catalog/bin/verify-job-claim-concurrency.php --run
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobClaimer;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoWorkerOwnership;

$options = getopt('', ['run', 'child', 'ownership-child', 'queue:', 'worker:', 'preferred-root:', 'start-at:']);
$application = catalog_bootstrap(false);
$db = $application->db;

if (isset($options['child'])) {
    $queue = trim((string)($options['queue'] ?? ''));
    $worker = trim((string)($options['worker'] ?? ''));
    $preferredRoot = max(0, (int)($options['preferred-root'] ?? 0));
    $startAt = (float)($options['start-at'] ?? 0);
    while ($startAt > 0 && microtime(true) < $startAt) {
        usleep(1000);
    }
    $job = (new PdoJobClaimer($db))->claim(
        $queue,
        $worker,
        120,
        $preferredRoot > 0 ? $preferredRoot : null
    );
    fwrite(STDOUT, json_encode([
        'job_id' => $job?->id ?? 0,
        'root_job_id' => $job?->rootJobId() ?? 0,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
}

if (isset($options['ownership-child'])) {
    $queue = trim((string)($options['queue'] ?? ''));
    $worker = trim((string)($options['worker'] ?? ''));
    $startAt = (float)($options['start-at'] ?? 0);
    while ($startAt > 0 && microtime(true) < $startAt) {
        usleep(1000);
    }
    $ownership = new PdoWorkerOwnership($db);
    $lock = $ownership->acquire($queue, $worker);
    fwrite(STDOUT, "owned\n");
    fflush(STDOUT);
    usleep(1500000);
    $ownership->release($lock);
    exit(0);
}

if (!isset($options['run'])) {
    fwrite(STDERR, "Refusing to modify the database without --run.\n");
    fwrite(STDERR, "Usage: php catalog/bin/verify-job-claim-concurrency.php --run\n");
    exit(64);
}

$token = strtolower(bin2hex(random_bytes(6)));
$queueName = 'verify-' . $token;
$resourceClass = 'verify-resource-' . $token;
$dynamicClass = 'verify-dynamic-' . $token;
$concurrencyKey = 'verify-key-' . $token;
$ownershipWorker = 'verify-owner-' . $token;
$script = __FILE__;
$queue = new PdoJobQueue($db);
$createdLimitClasses = [];
$checks = [];

/** @return list<int> */
function enqueueVerificationJobs(
    PDO $db,
    PdoJobQueue $queue,
    string $queueName,
    int $count,
    string $resourceClass,
    int $resourceLimit,
    ?string $concurrencyKey = null
): array {
    $ids = [];
    $update = $db->prepare(
        'UPDATE ue_background_jobs SET resource_class=?,resource_limit=?,concurrency_key=? WHERE id=?'
    );
    for ($i = 0; $i < $count; $i++) {
        $id = $queue->enqueue(
            $queueName,
            JobType::PRUNE_UPLOAD_PROGRESS,
            ['verification' => true, 'sequence' => $i],
            100 + $i
        );
        $update->execute([$resourceClass, $resourceLimit, $concurrencyKey, $id]);
        $ids[] = $id;
    }
    return $ids;
}

/** @return list<array{job_id:int,root_job_id:int}> */
function concurrentClaims(string $script, string $queueName, int $workers, ?int $preferredRoot = null): array
{
    $startAt = microtime(true) + 0.6;
    $processes = [];
    for ($i = 1; $i <= $workers; $i++) {
        $command = [
            PHP_BINARY,
            $script,
            '--child',
            '--queue=' . $queueName,
            '--worker=verify-claimer-' . getmypid() . '-' . $i,
            '--start-at=' . sprintf('%.6F', $startAt),
        ];
        if ($preferredRoot !== null && $preferredRoot > 0) {
            $command[] = '--preferred-root=' . $preferredRoot;
        }
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname($script)
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not launch verification claimer #' . $i . '.');
        }
        $processes[] = [$process, $pipes, $i];
    }

    $results = [];
    foreach ($processes as [$process, $pipes, $index]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException(
                'Verification claimer #' . $index . ' failed: ' . trim((string)$stderr)
            );
        }
        $decoded = json_decode(trim((string)$stdout), true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Verification claimer #' . $index . ' returned invalid JSON: ' . trim((string)$stdout)
            );
        }
        $results[] = [
            'job_id' => max(0, (int)($decoded['job_id'] ?? 0)),
            'root_job_id' => max(0, (int)($decoded['root_job_id'] ?? 0)),
        ];
    }
    return $results;
}

function claimedCount(array $claims): int
{
    return count(array_filter($claims, static fn(array $claim): bool => (int)$claim['job_id'] > 0));
}

function cleanupVerificationQueue(PDO $db, string $queueName): void
{
    $statement = $db->prepare('DELETE FROM ue_background_jobs WHERE queue_name=?');
    $statement->execute([$queueName]);
}

try {
    // 1. Eight simultaneous claimers competing for a resource class limited to 2.
    enqueueVerificationJobs($db, $queue, $queueName, 8, $resourceClass, 2);
    $claims = concurrentClaims($script, $queueName, 8);
    $claimed = claimedCount($claims);
    $checks['resource_limit_two'] = [
        'ok' => $claimed === 2,
        'claimed' => $claimed,
        'expected' => 2,
    ];
    cleanupVerificationQueue($db, $queueName);

    // 2. Eight simultaneous claimers competing for one concurrency key.
    enqueueVerificationJobs($db, $queue, $queueName, 8, $resourceClass, 8, $concurrencyKey);
    $claims = concurrentClaims($script, $queueName, 8);
    $claimed = claimedCount($claims);
    $checks['exclusive_concurrency_key'] = [
        'ok' => $claimed === 1,
        'claimed' => $claimed,
        'expected' => 1,
    ];
    cleanupVerificationQueue($db, $queueName);

    // 3. Saved limit overrides the persisted row snapshot without rewriting rows.
    $upsert = $db->prepare(
        'INSERT INTO ue_job_resource_limits (resource_class,limit_value,updated_by) VALUES (?,?,NULL) '
        . 'ON DUPLICATE KEY UPDATE limit_value=VALUES(limit_value),updated_by=NULL,updated_at=CURRENT_TIMESTAMP'
    );
    $upsert->execute([$dynamicClass, 4]);
    $createdLimitClasses[] = $dynamicClass;
    enqueueVerificationJobs($db, $queue, $queueName, 8, $dynamicClass, 1);
    $claims = concurrentClaims($script, $queueName, 8);
    $claimed = claimedCount($claims);
    $checks['dynamic_saved_limit'] = [
        'ok' => $claimed === 4,
        'claimed' => $claimed,
        'expected' => 4,
        'persisted_row_limit' => 1,
        'saved_limit' => 4,
    ];
    cleanupVerificationQueue($db, $queueName);

    // 4. A blocked preferred root must not hide unrelated runnable global work.
    $rootId = $queue->enqueue($queueName, JobType::PRUNE_UPLOAD_PROGRESS, ['verification' => 'preferred-root'], 1);
    $blockerId = $queue->enqueue($queueName, JobType::PRUNE_UPLOAD_PROGRESS, ['verification' => 'blocker'], 2);
    $globalId = $queue->enqueue($queueName, JobType::PRUNE_UPLOAD_PROGRESS, ['verification' => 'global'], 3);
    $update = $db->prepare(
        'UPDATE ue_background_jobs SET resource_class=?,resource_limit=?,concurrency_key=? WHERE id=?'
    );
    $update->execute([$resourceClass, 8, $concurrencyKey, $rootId]);
    $update->execute([$resourceClass . '-blocker', 8, $concurrencyKey, $blockerId]);
    $update->execute([$resourceClass . '-global', 8, null, $globalId]);
    $running = $db->prepare(
        'UPDATE ue_background_jobs SET status="running",worker_id=?,lease_token=?,leased_at=UTC_TIMESTAMP(),'
        . 'lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 120 SECOND),last_heartbeat_at=UTC_TIMESTAMP() WHERE id=?'
    );
    $running->execute(['verify-blocker', bin2hex(random_bytes(16)), $blockerId]);
    $claims = concurrentClaims($script, $queueName, 1, $rootId);
    $claimedId = (int)($claims[0]['job_id'] ?? 0);
    $checks['preferred_root_falls_back'] = [
        'ok' => $claimedId === $globalId,
        'claimed_job_id' => $claimedId,
        'expected_job_id' => $globalId,
    ];
    cleanupVerificationQueue($db, $queueName);

    // 5. Worker ownership is connection lifetime, not elapsed lease time.
    $startAt = microtime(true) + 0.5;
    $pipes = [];
    $process = proc_open(
        [
            PHP_BINARY,
            $script,
            '--ownership-child',
            '--queue=' . $queueName,
            '--worker=' . $ownershipWorker,
            '--start-at=' . sprintf('%.6F', $startAt),
        ],
        [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname($script)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not launch worker ownership verification process.');
    }
    while (microtime(true) < $startAt + 0.2) {
        usleep(1000);
    }
    $ownership = new PdoWorkerOwnership($db);
    $aliveWhileConnected = $ownership->isAlive($queueName, $ownershipWorker);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('Ownership child failed: ' . trim((string)$stderr));
    }
    $aliveAfterExit = $ownership->isAlive($queueName, $ownershipWorker);
    $checks['worker_connection_ownership'] = [
        'ok' => $aliveWhileConnected && !$aliveAfterExit,
        'alive_while_connected' => $aliveWhileConnected,
        'alive_after_exit' => $aliveAfterExit,
        'child_output' => trim((string)$stdout),
    ];
} finally {
    cleanupVerificationQueue($db, $queueName);
    if ($createdLimitClasses !== []) {
        $delete = $db->prepare('DELETE FROM ue_job_resource_limits WHERE resource_class=?');
        foreach ($createdLimitClasses as $class) {
            $delete->execute([$class]);
        }
    }
}

$ok = !in_array(false, array_map(
    static fn(array $check): bool => !empty($check['ok']),
    $checks
), true);
$result = [
    'ok' => $ok,
    'queue' => $queueName,
    'verified_at' => gmdate(DATE_ATOM),
    'checks' => $checks,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($ok ? 0 : 2);
