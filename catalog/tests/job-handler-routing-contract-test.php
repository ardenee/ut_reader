<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies that durable job dispatch has one explicit handler route for every registered job type.
 * Why: Worker dispatch previously depended on handler array order when multiple handlers claimed the same type.
 * Role: Regression contract for deterministic worker routing and complete factory registration.
 * Audit: Keep while JobWorker owns route validation and CatalogJobWorkerFactory owns the production route map.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Application\Jobs\JobQueue;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobWorkerFactory;

final class RoutingTestQueue implements JobQueue
{
    public function enqueue(string $queue, string $type, array $payload, int $priority = 100, ?\DateTimeImmutable $availableAt = null, ?string $dedupeKey = null, ?int $createdBy = null, int $maxAttempts = 3): int { return 1; }
    public function claim(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob { return null; }
    public function complete(ClaimedJob $job, array $result = []): string { return 'completed'; }
    public function fail(ClaimedJob $job, Throwable $exception, int $retryDelaySeconds): string { return 'dead_letter'; }
    public function heartbeat(ClaimedJob $job, int $leaseSeconds, array $progress = []): string { return 'active'; }
    public function requestCancellation(int $jobId, ?int $requestedBy = null, string $reason = ''): string { return 'not_found'; }
    public function cancelClaimed(ClaimedJob $job, string $reason = ''): void {}
    public function recoverExpiredLeases(string $queue): array { return ['requeued' => 0, 'cancelled' => 0, 'dead_lettered' => 0]; }
    public function retryDeadLetter(int $jobId, ?\DateTimeImmutable $availableAt = null): bool { return false; }
}

final class RoutingTestHandler implements JobHandler
{
    /** @param list<string> $types */
    public function __construct(private readonly array $types) {}
    public function supports(string $jobType): bool { return in_array($jobType, $this->types, true); }
    public function handle(ClaimedJob $job, JobExecutionContext $context): array { return []; }
}

$queue = new RoutingTestQueue();
$all = JobType::all();
$handler = new RoutingTestHandler($all);
$routes = array_fill_keys($all, $handler);
new JobWorker($queue, $routes, 'catalog', 'test-worker');

$missingRejected = false;
try {
    $missing = $routes;
    unset($missing[$all[0]]);
    new JobWorker($queue, $missing, 'catalog', 'test-worker');
} catch (LogicException $error) {
    $missingRejected = str_contains($error->getMessage(), 'Missing job handler route');
}
if (!$missingRejected) {
    throw new RuntimeException('JobWorker accepted incomplete handler routing.');
}

$unknownRejected = false;
try {
    $unknown = $routes;
    $unknown['catalog.unknown_test_type'] = $handler;
    new JobWorker($queue, $unknown, 'catalog', 'test-worker');
} catch (LogicException $error) {
    $unknownRejected = str_contains($error->getMessage(), 'Unknown job handler route');
}
if (!$unknownRejected) {
    throw new RuntimeException('JobWorker accepted an unknown job-type route.');
}

$mismatchRejected = false;
try {
    $mismatch = $routes;
    $mismatch[$all[0]] = new RoutingTestHandler([$all[1]]);
    new JobWorker($queue, $mismatch, 'catalog', 'test-worker');
} catch (LogicException $error) {
    $mismatchRejected = str_contains($error->getMessage(), 'does not support routed job type');
}
if (!$mismatchRejected) {
    throw new RuntimeException('JobWorker accepted a route to a handler that does not support its job type.');
}

$pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
CatalogJobWorkerFactory::create(
    $pdo,
    ['storage_path' => sys_get_temp_dir(), 'queue' => []],
    'catalog',
    'routing-contract',
    120
);

echo "Deterministic job-handler routing contract tests passed.\n";
