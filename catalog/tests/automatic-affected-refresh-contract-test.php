<?php
declare(strict_types=1);

function automatic_affected_refresh_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$service = file_get_contents(__DIR__ . '/../src/Application/Dependency/CatalogAffectedDependencyRefreshService.php');
$exactHandler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogDependencyRefreshJobHandler.php');
$affectedHandler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php');
$searchHandler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogSearchIndexJobHandler.php');
$factory = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
foreach (compact('service', 'exactHandler', 'affectedHandler', 'searchHandler', 'factory') as $name => $source) {
    automatic_affected_refresh_expect(is_string($source) && $source !== '', $name . ' could not be read.');
}

foreach ([
    'public static function enqueueIfNeeded(',
    'existingRefreshJobId(',
    'hasAffectedFiles(',
    "'source_summary_ready' => \$sourceSummaryReady",
    'JobType::REBUILD_AFFECTED_DEPENDENCIES',
    "'rebuild-affected-file:' . max(1, \$fileId)",
    'queued job remains durable',
] as $fragment) {
    automatic_affected_refresh_expect(str_contains($service, $fragment), 'Affected refresh service is missing: ' . $fragment);
}
automatic_affected_refresh_expect(
    !str_contains($service, 'CatalogSearchIndexQueue::enqueueFile('),
    'Affected dependency discovery still creates an independent legacy search job.'
);

automatic_affected_refresh_expect(
    str_contains($exactHandler, 'scanner_rebuild_dependencies(')
        && str_contains($exactHandler, 'PdoPackageProviderRepository')
        && str_contains($exactHandler, 'PdoDependencyPackageSummary')
        && str_contains($exactHandler, 'CatalogAffectedDependencyRefreshService::enqueueIfNeeded(')
        && str_contains($exactHandler, "!empty(\$job->payload['post_import'])")
        && str_contains($exactHandler, '$affectedJobId < 1'),
    'The exact-file job does not publish source dependencies and projections before affected work.'
);

foreach ([
    'CatalogAffectedDependencyRefreshService::findAffectedFileIds(',
    "job->payload['resume_offset']",
    "job->payload['source_summary_ready']",
    'array_slice($affectedIds, $resumeOffset, $chunkSize)',
    "'source_summary_ready' => true",
    "'rebuild-affected-file:' . \$fileId . ':offset:' . \$nextOffset",
    'catch (JobCancellationRequested',
    'gc_collect_cycles()',
] as $fragment) {
    automatic_affected_refresh_expect(str_contains($affectedHandler, $fragment), 'Affected handler is missing: ' . $fragment);
}
automatic_affected_refresh_expect(
    str_contains($affectedHandler, 'Preparing source dependency links')
        && str_contains($affectedHandler, 'new PdoPackageProviderRepository($this->db)'),
    'Affected jobs queued by older code do not repair their source before processing dependants.'
);

automatic_affected_refresh_expect(
    str_contains($searchHandler, 'PdoPackageProviderRepository')
        && !str_contains($searchHandler, 'PdoDependencyPackageSummary')
        && !str_contains($searchHandler, 'PdoGameCatalogStats')
        && str_contains($searchHandler, "'dependency_summary_rebuilt' => false")
        && str_contains($searchHandler, "'game_stats_refreshed' => false"),
    'Legacy search jobs can still publish dependency summaries or game counters from partial data.'
);

$affectedPosition = strpos($factory, 'new CatalogAffectedDependencyRefreshJobHandler(');
$maintenancePosition = strpos($factory, 'new CatalogMaintenanceJobHandler(');
automatic_affected_refresh_expect(
    $affectedPosition !== false && $maintenancePosition !== false && $affectedPosition < $maintenancePosition,
    'The resilient affected refresh handler must be registered before the broad maintenance handler.'
);

echo "Automatic affected dependency refresh contract tests passed.\n";
