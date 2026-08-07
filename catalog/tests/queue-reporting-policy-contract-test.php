<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies queue reporting policy behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function queue_reporting_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$workerScript = file_get_contents(__DIR__ . '/../bin/catalog-worker-detached.php');
$run = file_get_contents(__DIR__ . '/../api/v1/job-run.php');
$store = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobResourceLimitStore.php');
$projectionQueue = file_get_contents(__DIR__ . '/../src/Application/Maintenance/CatalogProjectionReconciliationQueue.php');
foreach (compact('workerScript', 'run', 'store', 'projectionQueue') as $name => $source) {
    queue_reporting_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

queue_reporting_expect(
    str_contains($workerScript, '$attempted = 0;')
        && str_contains($workerScript, 'while ($attempted < $maxJobs)')
        && str_contains($workerScript, '$attempted++;')
        && str_contains($workerScript, "if (\$status === 'completed')")
        && str_contains($workerScript, '$processed++;')
        && str_contains($workerScript, "'attempted' => \$attempted")
        && !str_contains($workerScript, 'while ($processed < $maxJobs)'),
    'Worker processed count still includes retries, cancellations or other non-completed attempts.'
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
