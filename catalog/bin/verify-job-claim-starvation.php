#!/usr/bin/env php
<?php
/**
 * Manual MySQL regression for blocked-prefix queue starvation.
 *
 * The old claimer examined at most 32 blocked rows. If 33+ higher-priority jobs
 * shared a saturated resource class or occupied concurrency key, unrelated
 * runnable work later in the queue could never be claimed. This verifier builds
 * that exact shape with 64 blocked rows and proves the claimer reaches the next
 * runnable job.
 *
 * Usage:
 *   php catalog/bin/verify-job-claim-starvation.php --run
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobClaimer;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

$options = getopt('', ['run']);
if (!isset($options['run'])) {
    fwrite(STDERR, "Refusing to modify the database without --run.\n");
    fwrite(STDERR, "Usage: php catalog/bin/verify-job-claim-starvation.php --run\n");
    exit(64);
}

$application = catalog_bootstrap(false);
$db = $application->db;
$queue = new PdoJobQueue($db);
$token = strtolower(bin2hex(random_bytes(6)));
$queueName = 'verify-starvation-' . $token;
$checks = [];

/** @return int */
function starvationEnqueue(
    PDO $db,
    PdoJobQueue $queue,
    string $queueName,
    int $priority,
    string $resourceClass,
    int $resourceLimit,
    ?string $concurrencyKey,
    string $label
): int {
    $id = $queue->enqueue(
        $queueName,
        JobType::PRUNE_UPLOAD_PROGRESS,
        ['verification' => 'blocked-prefix-starvation', 'label' => $label],
        $priority
    );
    $statement = $db->prepare(
        'UPDATE ue_background_jobs SET resource_class=?,resource_limit=?,concurrency_key=? WHERE id=?'
    );
    $statement->execute([$resourceClass, $resourceLimit, $concurrencyKey, $id]);
    return $id;
}

function starvationMakeRunning(PDO $db, int $jobId, string $workerId): void
{
    $statement = $db->prepare(
        'UPDATE ue_background_jobs SET status="running",attempts=1,worker_id=?,lease_token=?,'
        . 'leased_at=UTC_TIMESTAMP(),lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 120 SECOND),'
        . 'last_heartbeat_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?'
    );
    $statement->execute([$workerId, bin2hex(random_bytes(16)), $jobId]);
}

function starvationCleanup(PDO $db, string $queueName): void
{
    $statement = $db->prepare('DELETE FROM ue_background_jobs WHERE queue_name=?');
    $statement->execute([$queueName]);
}

try {
    // 1. More than the historical 32-row scan window is blocked by a saturated
    // resource class. The lower-priority job in another class must still run.
    $blockedClass = 'verify-blocked-resource-' . $token;
    $runnableClass = 'verify-runnable-resource-' . $token;
    $blockerId = starvationEnqueue(
        $db,
        $queue,
        $queueName,
        1,
        $blockedClass,
        1,
        null,
        'resource-blocker'
    );
    starvationMakeRunning($db, $blockerId, 'verify-resource-blocker-' . $token);
    for ($index = 0; $index < 64; $index++) {
        starvationEnqueue(
            $db,
            $queue,
            $queueName,
            10 + $index,
            $blockedClass,
            1,
            null,
            'resource-blocked-' . $index
        );
    }
    $resourceFallbackId = starvationEnqueue(
        $db,
        $queue,
        $queueName,
        1000,
        $runnableClass,
        1,
        null,
        'resource-fallback'
    );
    $claimed = (new PdoJobClaimer($db))->claim(
        $queueName,
        'verify-resource-claimer-' . $token,
        120
    );
    $checks['resource_blocked_prefix_64'] = [
        'ok' => $claimed?->id === $resourceFallbackId,
        'claimed_job_id' => $claimed?->id ?? 0,
        'expected_job_id' => $resourceFallbackId,
        'blocked_rows' => 64,
    ];
    starvationCleanup($db, $queueName);

    // 2. More than 32 higher-priority rows share an occupied concurrency key.
    // The fallback deliberately uses the SAME resource class with no key, which
    // proves the claimer skips only the blocked key rather than the whole class.
    $sharedClass = 'verify-shared-resource-' . $token;
    $blockedKey = 'verify-blocked-key-' . $token;
    $keyBlockerId = starvationEnqueue(
        $db,
        $queue,
        $queueName,
        1,
        $sharedClass,
        100,
        $blockedKey,
        'key-blocker'
    );
    starvationMakeRunning($db, $keyBlockerId, 'verify-key-blocker-' . $token);
    for ($index = 0; $index < 64; $index++) {
        starvationEnqueue(
            $db,
            $queue,
            $queueName,
            10 + $index,
            $sharedClass,
            100,
            $blockedKey,
            'key-blocked-' . $index
        );
    }
    $keyFallbackId = starvationEnqueue(
        $db,
        $queue,
        $queueName,
        1000,
        $sharedClass,
        100,
        null,
        'key-fallback'
    );
    $claimed = (new PdoJobClaimer($db))->claim(
        $queueName,
        'verify-key-claimer-' . $token,
        120
    );
    $checks['concurrency_key_blocked_prefix_64'] = [
        'ok' => $claimed?->id === $keyFallbackId,
        'claimed_job_id' => $claimed?->id ?? 0,
        'expected_job_id' => $keyFallbackId,
        'blocked_rows' => 64,
        'same_resource_class' => true,
    ];
} finally {
    starvationCleanup($db, $queueName);
}

$ok = !in_array(false, array_map(
    static fn(array $check): bool => !empty($check['ok']),
    $checks
), true);

fwrite(STDOUT, json_encode([
    'ok' => $ok,
    'queue' => $queueName,
    'verified_at' => gmdate(DATE_ATOM),
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($ok ? 0 : 2);
