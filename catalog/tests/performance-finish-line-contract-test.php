<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies performance finish line behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function performance_finish_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$core = file_get_contents(__DIR__ . '/../lib/CatalogSupportCore.php');
$performance = file_get_contents(__DIR__ . '/../lib/CatalogPerformance.php');
$migration = file_get_contents(__DIR__ . '/../migrations/202607270014_performance_finish_line.php');
$jobs = file_get_contents(__DIR__ . '/../api/v1/job-status-cursor.php');
$search = file_get_contents(__DIR__ . '/../lib/CatalogSearchService.php');
$readiness = file_get_contents(__DIR__ . '/../performance-readiness.php');
$navigation = file_get_contents(__DIR__ . '/../lib/CatalogNavigation.php');

foreach ([$core, $performance, $migration, $jobs, $search, $readiness, $navigation] as $source) {
    performance_finish_expect(is_string($source), 'A performance finish-line source file could not be read.');
}

performance_finish_expect(
    str_contains($core, "require_once __DIR__ . '/CatalogPerformance.php';")
        && str_contains($core, 'catalog_performance_statement($db, $sql, $args)')
        && str_contains($core, 'catalog_performance_count($db, $sql, $args)')
        && str_contains($core, 'catalog_performance_finish();'),
    'Shared SQL and page-time instrumentation is not wired through CatalogSupportCore.'
);
performance_finish_expect(
    str_contains($performance, 'ue_exact_count_cache')
        && str_contains($performance, 'UNREALDB_COUNT_CACHE_DISABLED')
        && str_contains($performance, 'Server-Timing: app;dur=')
        && str_contains($performance, 'ue_request_performance'),
    'Count caching or page-time diagnostics are incomplete.'
);
performance_finish_expect(
    str_contains($migration, "'version' => '202607270014'")
        && str_contains($migration, 'ue_background_job_search')
        && str_contains($migration, 'ft_ue_job_search_text')
        && str_contains($migration, 'ue_request_performance'),
    'Performance finish-line migration is incomplete.'
);
performance_finish_expect(
    str_contains($jobs, 'JOIN ue_background_job_search js ON js.job_id=j.id')
        && str_contains($jobs, 'MATCH(js.search_text) AGAINST (? IN BOOLEAN MODE)')
        && str_contains($jobs, 'catalog_performance_sync_job_search'),
    'Background Jobs search is not routed through the compact FULLTEXT projection.'
);
performance_finish_expect(
    str_contains($search, 'private const MAX_GLOBAL_GAMES = 64;')
        && str_contains($search, 'SELECT g.id FROM ue_games g WHERE EXISTS')
        && str_contains($search, 'CatalogSearchService::findFiles('),
    'Administrator all-game broad search is not split into bounded game-scoped searches.'
);
performance_finish_expect(
    str_contains($readiness, 'Confirmed slow exact counts')
        && str_contains($readiness, 'Page-time diagnostics')
        && str_contains($readiness, 'Synchronise job search'),
    'Performance readiness validation page is incomplete.'
);
performance_finish_expect(
    str_contains($navigation, "'Performance Readiness' => " . '$root' . " . 'performance-readiness.php'"),
    'Performance Readiness is missing from Maintenance navigation.'
);

fwrite(STDOUT, "Performance finish-line contract tests passed.\n");
