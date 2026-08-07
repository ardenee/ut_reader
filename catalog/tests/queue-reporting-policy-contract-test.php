<?php
declare(strict_types=1);

function queue_reporting_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$status = file_get_contents(__DIR__ . '/../api/v1/job-worker-status.php');
$run = file_get_contents(__DIR__ . '/../api/v1/job-run.php');
$store = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobResourceLimitStore.php');
$projectionQueue = file_get_contents(__DIR__ . '/../src/Application/Maintenance/CatalogProjectionReconciliationQueue.php');
foreach (compact('status', 'run', 'store', 'projectionQueue') as $name => $source) {
    queue_reporting_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

queue_reporting_expect(
    str_contains($status, "'attempted' => \$attempted")
        && str_contains($status, "'processed'] = \$completedSinceStart")
        && str_contains($status, 'status="completed" AND completed_at>=?')
        && str_contains($status, 'job_worker_status_pool_started_at'),
    'Worker status still reports attempts/retries as completed processing.'
);

queue_reporting_expect(
    str_contains($store, 'synchronizeQueuedPolicies')
        && str_contains($store, "PROJECTION_CONCURRENCY_KEY = 'projection:catalog-maintenance'")
        && str_contains($store, 'JobType::RECONCILE_CATALOG_PROJECTIONS')
        && str_contains($store, 'resource_class=?,resource_limit=?,concurrency_key=?')
        && str_contains($store, 'Always synchronize queued rows'),
    'Queued rows are not repaired to the current resource policy.'
);

queue_reporting_expect(
    str_contains($run, '->synchronizeQueuedPolicies()')
        && str_contains($run, "'queue_policy_sync' => \$queuePolicySync"),
    'Starting/resizing the worker pool does not repair stale queued policy first.'
);

queue_reporting_expect(
    str_contains($projectionQueue, "CONCURRENCY_KEY = 'projection:catalog-maintenance'")
        && str_contains($projectionQueue, 'JobResourcePolicy::DEPENDENCY_HEAVY')
        && !str_contains($projectionQueue, 'CatalogDetachedWorker')
        && str_contains($projectionQueue, 'Queueing must remain a short foreground operation'),
    'Projection jobs can still occupy several workers on one global maintenance lock or launch workers from the foreground.'
);

echo "Queue reporting and policy contract tests passed.\n";
