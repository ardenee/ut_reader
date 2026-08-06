<?php
declare(strict_types=1);

function unverified_hot_path_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$action = file_get_contents(__DIR__ . '/../unverified-files-action.php');
$queue = file_get_contents(__DIR__ . '/../src/Application/Dependency/CatalogPostImportDependencyQueue.php');
$exact = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogDependencyRefreshJobHandler.php');
$affected = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php');
$legacyProjection = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogSearchIndexJobHandler.php');
foreach (compact('action', 'queue', 'exact', 'affected', 'legacyProjection') as $name => $source) {
    unverified_hot_path_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

unverified_hot_path_expect(
    str_contains($action, 'Reusing staged package tables')
        && str_contains($action, 'Loading staged file identity')
        && str_contains($action, '$storedSize === $size && $validMd5 && $validSha1')
        && !str_contains($action, "'dependency_cleanup'"),
    'Foreground import still repeats staging, hashing or dependency cleanup work.'
);
unverified_hot_path_expect(
    str_contains($queue, "'post_import' => true")
        && str_contains($queue, "'worker_started' => false")
        && !str_contains($queue, 'CatalogDetachedWorker')
        && !str_contains($queue, 'REBUILD_AFFECTED_DEPENDENCIES'),
    'Foreground import still launches workers or creates racing maintenance jobs.'
);
unverified_hot_path_expect(
    str_contains($exact, 'CatalogAffectedDependencyRefreshService::enqueueIfNeeded(')
        && str_contains($exact, 'PdoPackageProviderRepository')
        && str_contains($exact, 'PdoDependencyPackageSummary'),
    'The exact-file worker is not the ordered post-import coordinator.'
);
unverified_hot_path_expect(
    str_contains($affected, "'source_summary_ready' => true")
        && str_contains($affected, 'Preparing source dependency links'),
    'Affected refreshes do not preserve ordered source state or support old queued jobs.'
);
unverified_hot_path_expect(
    !str_contains($legacyProjection, 'PdoDependencyPackageSummary')
        && !str_contains($legacyProjection, 'PdoGameCatalogStats'),
    'Legacy projection jobs can still publish partial dependency totals.'
);

echo "Unverified import hot-path contract tests passed.\n";
