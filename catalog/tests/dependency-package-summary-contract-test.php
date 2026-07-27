<?php
declare(strict_types=1);

function dependency_summary_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$migration = file_get_contents(__DIR__ . '/../migrations/202607270004_dependency_package_summaries.php');
dependency_summary_expect(is_string($migration), 'Dependency summary migration could not be read.');
foreach ([
    'ue_dependency_package_summaries',
    'dependency_count',
    'missing_count',
    'provider_file_id',
    'idx_ue_dep_summary_game_status',
    'idx_ue_dep_summary_package_game',
    'GROUP BY f.game_id,d.file_id,d.required_package',
] as $fragment) {
    dependency_summary_expect(str_contains($migration, $fragment), 'Dependency summary migration is missing ' . $fragment);
}

$writer = file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/PdoDependencyPackageSummary.php');
dependency_summary_expect(is_string($writer), 'Dependency summary writer could not be read.');
foreach ([
    'DELETE FROM ue_dependency_package_summaries WHERE file_id=?',
    'SUM(d.status="missing")',
    'COUNT(DISTINCT d.resolved_file_id)=1',
    'GROUP BY f.game_id,d.file_id,d.required_package',
] as $fragment) {
    dependency_summary_expect(str_contains($writer, $fragment), 'Dependency summary writer is missing ' . $fragment);
}

$missingPage = file_get_contents(__DIR__ . '/../missing.php');
dependency_summary_expect(is_string($missingPage), 'Missing Files page could not be read.');
dependency_summary_expect(str_contains($missingPage, 'ue_dependency_package_summaries'), 'Missing Files does not use package summaries.');
dependency_summary_expect(str_contains($missingPage, 'FROM ue_dependencies d'), 'Missing Files lost authoritative object-level drill-down.');

$affected = file_get_contents(__DIR__ . '/../src/Application/Dependency/CatalogAffectedDependencyRefreshService.php');
dependency_summary_expect(is_string($affected), 'Affected refresh service could not be read.');
dependency_summary_expect(str_contains($affected, 'FROM ue_dependency_package_summaries s'), 'Affected discovery does not use package summaries.');
dependency_summary_expect(str_contains($affected, 'FROM ue_dependencies d'), 'Affected discovery lost its pre-migration fallback.');

$factory = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
dependency_summary_expect(is_string($factory), 'Worker factory could not be read.');
dependency_summary_expect(str_contains($factory, 'CatalogDependencyRefreshJobHandler'), 'Summary-aware dependency refresh handler is not registered.');

$searchHandler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogSearchIndexJobHandler.php');
dependency_summary_expect(is_string($searchHandler), 'Search index handler could not be read.');
dependency_summary_expect(str_contains($searchHandler, 'PdoDependencyPackageSummary'), 'Post-import search job does not maintain dependency summaries.');

echo "Dependency package summary contract tests passed.\n";
