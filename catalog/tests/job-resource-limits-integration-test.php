<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies job resource limits integration behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

require_once __DIR__ . '/../bootstrap/autoload.php';

function resource_test_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$dsn = (string)(getenv('UNREALDB_TEST_DSN') ?: '');
$user = (string)(getenv('UNREALDB_TEST_DB_USER') ?: '');
$password = (string)(getenv('UNREALDB_TEST_DB_PASSWORD') ?: '');
if ($dsn === '') {
    throw new RuntimeException('UNREALDB_TEST_DSN is required.');
}

$db = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$queue = new PdoJobQueue($db);
$queueNames = [];

try {
    putenv('UNREALDB_JOB_RESOURCE_LIMIT_DEPENDENCY_HEAVY=1');
    putenv('UNREALDB_JOB_RESOURCE_LIMIT_HOUSEKEEPING=2');
    $fairQueue = 'resource_fair_' . bin2hex(random_bytes(5));
    $queueNames[] = $fairQueue;

    $dependencyOne = $queue->enqueue($fairQueue, JobType::REBUILD_GAME_DEPENDENCIES, ['game_id' => 1], 10);
    $dependencyTwo = $queue->enqueue($fairQueue, JobType::REBUILD_GAME_DEPENDENCIES, ['game_id' => 2], 11);
    $housekeepingOne = $queue->enqueue($fairQueue, JobType::PRUNE_UPLOAD_PROGRESS, ['max_age_seconds' => 60], 20);
    $housekeepingTwo = $queue->enqueue($fairQueue, JobType::PRUNE_UPLOAD_PROGRESS, ['max_age_seconds' => 120], 21);
    $housekeepingThree = $queue->enqueue($fairQueue, JobType::PRUNE_UPLOAD_PROGRESS, ['max_age_seconds' => 180], 22);

    $claimedDependency = $queue->claim($fairQueue, 'resource-worker-1', 60);
    resource_test_expect($claimedDependency !== null && $claimedDependency->id === $dependencyOne, 'The first dependency job was not claimed.');
    resource_test_expect($claimedDependency->resourceClass === 'dependency-heavy' && $claimedDependency->resourceLimit === 1, 'Dependency resource metadata was not persisted on the claim.');

    $claimedHousekeepingOne = $queue->claim($fairQueue, 'resource-worker-2', 60);
    resource_test_expect($claimedHousekeepingOne !== null && $claimedHousekeepingOne->id === $housekeepingOne, 'A saturated dependency class blocked eligible housekeeping work.');
    $claimedHousekeepingTwo = $queue->claim($fairQueue, 'resource-worker-3', 60);
    resource_test_expect($claimedHousekeepingTwo !== null && $claimedHousekeepingTwo->id === $housekeepingTwo, 'The second housekeeping slot was not used.');
    resource_test_expect($queue->claim($fairQueue, 'resource-worker-4', 60) === null, 'A job was claimed after every eligible resource class reached capacity.');

    $queue->complete($claimedHousekeepingOne, ['test' => true]);
    $claimedHousekeepingThree = $queue->claim($fairQueue, 'resource-worker-4', 60);
    resource_test_expect($claimedHousekeepingThree !== null && $claimedHousekeepingThree->id === $housekeepingThree, 'Releasing a housekeeping slot did not admit the next job.');

    $queue->complete($claimedDependency, ['test' => true]);
    $claimedDependencyTwo = $queue->claim($fairQueue, 'resource-worker-5', 60);
    resource_test_expect($claimedDependencyTwo !== null && $claimedDependencyTwo->id === $dependencyTwo, 'Releasing the dependency slot did not admit the next heavy job.');

    foreach ([$claimedHousekeepingTwo, $claimedHousekeepingThree, $claimedDependencyTwo] as $job) {
        $queue->complete($job, ['test' => true]);
    }

    putenv('UNREALDB_JOB_RESOURCE_LIMIT_DEPENDENCY_HEAVY=2');
    $keyQueue = 'resource_key_' . bin2hex(random_bytes(5));
    $queueNames[] = $keyQueue;
    $sameTargetOne = $queue->enqueue($keyQueue, JobType::REBUILD_GAME_DEPENDENCIES, ['game_id' => 7], 10);
    $sameTargetTwo = $queue->enqueue($keyQueue, JobType::REBUILD_GAME_DEPENDENCIES, ['game_id' => 7], 11);
    $differentTarget = $queue->enqueue($keyQueue, JobType::REBUILD_GAME_DEPENDENCIES, ['game_id' => 8], 12);

    $targetClaimOne = $queue->claim($keyQueue, 'key-worker-1', 60);
    resource_test_expect($targetClaimOne !== null && $targetClaimOne->id === $sameTargetOne, 'The first target-specific job was not claimed.');
    resource_test_expect($targetClaimOne->concurrencyKey === 'dependency:game:7', 'The target concurrency key was not persisted.');

    $targetClaimTwo = $queue->claim($keyQueue, 'key-worker-2', 60);
    resource_test_expect($targetClaimTwo !== null && $targetClaimTwo->id === $differentTarget, 'A duplicate target key blocked an unrelated target despite available class capacity.');
    resource_test_expect($queue->claim($keyQueue, 'key-worker-3', 60) === null, 'Two jobs with the same concurrency key ran together.');

    $queue->complete($targetClaimOne, ['test' => true]);
    $sameTargetClaim = $queue->claim($keyQueue, 'key-worker-3', 60);
    resource_test_expect($sameTargetClaim !== null && $sameTargetClaim->id === $sameTargetTwo, 'The duplicate target did not start after its key was released.');
    $queue->complete($targetClaimTwo, ['test' => true]);
    $queue->complete($sameTargetClaim, ['test' => true]);

    fwrite(STDOUT, "Background job resource-limit integration tests passed.\n");
} finally {
    putenv('UNREALDB_JOB_RESOURCE_LIMIT_DEPENDENCY_HEAVY');
    putenv('UNREALDB_JOB_RESOURCE_LIMIT_HOUSEKEEPING');
    if ($queueNames !== []) {
        $placeholders = implode(',', array_fill(0, count($queueNames), '?'));
        $statement = $db->prepare('DELETE FROM ue_background_jobs WHERE queue_name IN (' . $placeholders . ')');
        $statement->execute($queueNames);
    }
}
