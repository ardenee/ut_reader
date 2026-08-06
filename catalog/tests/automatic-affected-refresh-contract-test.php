<?php
declare(strict_types=1);

function automatic_affected_refresh_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$service = file_get_contents(__DIR__ . '/../src/Application/Dependency/CatalogAffectedDependencyRefreshService.php');
automatic_affected_refresh_expect(is_string($service), 'Affected refresh service could not be read.');
foreach ([
    'new PdoJobQueue($db)',
    'JobType::REBUILD_AFFECTED_DEPENDENCIES',
    "'rebuild-affected-file:' . max(1, \$fileId)",
    'hasAffectedFiles(',
    'isActiveRefreshJob(',
    'existingRefreshJobId(',
    'dedupe_key=?',
    'new CatalogDetachedWorker($config)',
    'using synchronous fallback',
    'queued job remains durable',
] as $fragment) {
    automatic_affected_refresh_expect(
        str_contains($service, $fragment),
        'Affected refresh service is missing: ' . $fragment
    );
}

automatic_affected_refresh_expect(
    str_contains($service, 'status="running"')
        && str_contains($service, 'status IN ("queued","running")')
        && str_contains($service, "\$GLOBALS['catalog_affected_dependency_refresh_job_id']"),
    'Normal imports do not hand affected dependency work to a running/queued durable job.'
);
automatic_affected_refresh_expect(
    str_contains($service, "if (empty(\$worker['active'])")
        && str_contains($service, "(int)(\$worker['launching_count'] ?? 0) === 0")
        && str_contains($service, 'return $jobId;'),
    'Normal imports can still reconcile an already active worker pool or lose a durable job after launch failure.'
);

$handler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php');
automatic_affected_refresh_expect(is_string($handler), 'Affected refresh job handler could not be read.');
foreach ([
    'CatalogAffectedDependencyRefreshService::findAffectedFileIds(',
    '\\scanner_rebuild_dependencies(',
    'catch (JobCancellationRequested',
    "'failure_count' => \$failureCount",
    "'failures_truncated' => \$failureCount > count(\$failures)",
] as $fragment) {
    automatic_affected_refresh_expect(
        str_contains($handler, $fragment),
        'Affected refresh handler is missing: ' . $fragment
    );
}

$factory = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
automatic_affected_refresh_expect(is_string($factory), 'Job worker factory could not be read.');
$affectedPosition = strpos($factory, 'new CatalogAffectedDependencyRefreshJobHandler(');
$maintenancePosition = strpos($factory, 'new CatalogMaintenanceJobHandler(');
automatic_affected_refresh_expect(
    $affectedPosition !== false && $maintenancePosition !== false && $affectedPosition < $maintenancePosition,
    'The resilient affected refresh handler must be registered before the broad maintenance handler.'
);

echo "Automatic affected dependency refresh contract tests passed.\n";
