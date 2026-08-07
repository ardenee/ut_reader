<?php
declare(strict_types=1);

function maintenance_projection_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$types = file_get_contents(__DIR__ . '/../src/Domain/Jobs/JobType.php');
$policy = file_get_contents(__DIR__ . '/../src/Domain/Jobs/JobResourcePolicy.php');
$factory = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$queue = file_get_contents(__DIR__ . '/../src/Application/Maintenance/CatalogProjectionReconciliationQueue.php');
$handler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogProjectionReconciliationJobHandler.php');
foreach ([$types, $policy, $factory, $queue, $handler] as $source) {
    maintenance_projection_expect(is_string($source), 'Projection reconciliation infrastructure could not be read.');
}

maintenance_projection_expect(
    str_contains($types, 'RECONCILE_CATALOG_PROJECTIONS')
        && str_contains($types, 'catalog.reconcile_catalog_projections'),
    'Projection reconciliation job type is not registered.'
);
maintenance_projection_expect(
    str_contains($policy, 'JobType::RECONCILE_CATALOG_PROJECTIONS')
        && str_contains($policy, 'self::DEPENDENCY_HEAVY'),
    'Projection reconciliation is not classified as dependency-heavy work.'
);
maintenance_projection_expect(
    str_contains($factory, 'new CatalogProjectionReconciliationJobHandler($db, $config)')
        && strpos($factory, 'CatalogProjectionReconciliationJobHandler') < strpos($factory, 'CatalogMaintenanceJobHandler'),
    'Projection reconciliation handler is not registered before the catch-all maintenance handler.'
);
maintenance_projection_expect(
    str_contains($queue, 'PdoJobQueue')
        && str_contains($queue, "CONCURRENCY_KEY = 'projection:catalog-maintenance'")
        && str_contains($queue, 'CatalogJobResourceLimitStore')
        && str_contains($queue, 'JobResourcePolicy::DEPENDENCY_HEAVY')
        && !str_contains($queue, 'CatalogDetachedWorker')
        && str_contains($queue, 'catalog-projections:')
        && str_contains($queue, "'game_ids' => \$gameIds")
        && str_contains($queue, "'package_names' => \$packageNames"),
    'Projection reconciliation queue is not globally serialized or still launches workers from the foreground request.'
);

foreach ([
    'GET_LOCK(?,45)',
    'RELEASE_LOCK(?)',
    'reconcileFile($fileId)',
    'rebuildFile($fileId)',
    'PdoDependencyPackageSummary',
    'PdoGameCatalogStats',
    'affectedFileIds(',
    'scanner_rebuild_dependencies(',
] as $fragment) {
    maintenance_projection_expect(str_contains($handler, $fragment), 'Projection handler is missing: ' . $fragment);
}
maintenance_projection_expect(
    str_contains($handler, "'file_exists' => \$file !== null")
        && str_contains($handler, "if (\$fileId > 0)")
        && str_contains($handler, "'stats_refreshed' => \$statsRefreshed"),
    'Projection handler does not support deleted files and game-only zero-state reconciliation.'
);
maintenance_projection_expect(
    !str_contains($handler, 'ue_search_documents')
        && !str_contains($handler, 'PdoSearchDocumentIndexer'),
    'Projection reconciliation still references the retired search-document projection.'
);

$provider = file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/PdoPackageProviderRepository.php');
$refreshHandler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogSearchIndexJobHandler.php');
maintenance_projection_expect(is_string($provider) && is_string($refreshHandler), 'Provider refresh sources could not be read.');
maintenance_projection_expect(
    str_contains($provider, 'function reconcileFile(')
        && str_contains($provider, 'DELETE FROM ue_package_providers WHERE file_id=?')
        && str_contains($refreshHandler, 'reconcileFile($fileId)')
        && str_contains($refreshHandler, 'PdoDependencyPackageSummary')
        && str_contains($refreshHandler, 'PdoGameCatalogStats'),
    'The retained file-projection job does not refresh all compact provider and summary rows.'
);
maintenance_projection_expect(
    !str_contains($refreshHandler, 'ue_search_documents')
        && !str_contains($refreshHandler, 'PdoSearchDocumentIndexer'),
    'The retained file-projection job still uses the retired search-document indexer.'
);

$hooks = [
    '../src/Infrastructure/Persistence/PdoPackageAliasRepository.php' => ['CatalogProjectionReconciliationQueue::enqueue(', '[$packageName]'],
    '../duplicates-keep.php' => ['CatalogProjectionReconciliationQueue::enqueue(', 'scan_status="duplicate"'],
    '../lib/CatalogSourceIdentity.php' => ['CatalogProjectionReconciliationQueue::enqueue(', '$allPackageNames'],
    '../package-normalize.php' => ['CatalogProjectionReconciliationQueue::enqueue(', "\$result['old_package']", "\$result['new_package']"],
    '../guid-normalize.php' => ['CatalogProjectionReconciliationQueue::enqueue(', "'package_name' => (string)\$file['package_name']"],
    '../lib/CatalogFileMaintenance.php' => ['CatalogProjectionReconciliationQueue::enqueue(', '$oldPackageName', '$newPackageName'],
    '../lib/GameManagerLifecycle.php' => [
        'CatalogProjectionReconciliationQueue::enqueue(',
        "'ue_dependency_package_summaries'",
        "'ue_package_providers'",
        "'ue_game_catalog_stats'",
    ],
];
foreach ($hooks as $relative => $fragments) {
    $source = file_get_contents(__DIR__ . '/' . $relative);
    maintenance_projection_expect(is_string($source), 'Maintenance hook source could not be read: ' . $relative);
    maintenance_projection_expect(
        !str_contains($source, 'ue_search_documents'),
        $relative . ' still references the retired search-document table.'
    );
    foreach ($fragments as $fragment) {
        maintenance_projection_expect(str_contains($source, $fragment), $relative . ' is missing reconciliation fragment: ' . $fragment);
    }
}

$workerQueue = file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/WorkerJobQueue.php');
maintenance_projection_expect(
    is_string($workerQueue) && str_contains($workerQueue, 'dedupe_key=NULL'),
    'Completed jobs no longer release projection reconciliation dedupe keys.'
);

fwrite(STDOUT, "Maintenance projection reconciliation contract tests passed.\n");
