<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies game catalog stats behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function game_stats_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$install = file_get_contents($root . '/install.sql');
$writer = file_get_contents($root . '/src/Infrastructure/Persistence/PdoGameCatalogStats.php');
$dashboardUseCase = file_get_contents($root . '/src/Application/Dashboard/CatalogDashboardStats.php');
$dashboardQuery = file_get_contents($root . '/src/Infrastructure/Persistence/PdoDashboardStatsQuery.php');
$dashboardPage = file_get_contents($root . '/dashboard.php');
$games = file_get_contents($root . '/games.php');
$library = file_get_contents($root . '/library.php');
$api = file_get_contents($root . '/api/v1/game-missing-counts.php');
$searchJob = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogSearchIndexJobHandler.php');
$dependencyJob = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogDependencyRefreshJobHandler.php');
$affectedJob = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php');

foreach (compact('install', 'writer', 'dashboardUseCase', 'dashboardQuery', 'dashboardPage', 'games', 'library', 'api', 'searchJob', 'dependencyJob', 'affectedJob') as $name => $source) {
    game_stats_expect(is_string($source) && $source !== '', 'Could not read ' . $name . ' source.');
}

game_stats_expect(str_contains($install, '-- 202607270005: official base-game files and cached per-game counters.'), 'Consolidated schema is missing the game stats migration boundary.');
game_stats_expect(str_contains($install, 'CREATE TABLE ue_game_catalog_stats'), 'Game stats table is not created by the consolidated schema.');
game_stats_expect(str_contains($writer, 'GET_LOCK(?,0)'), 'Per-game rebuilds are not protected by a non-blocking advisory lock.');
game_stats_expect(str_contains($writer, 'ue_dependency_package_summaries'), 'Game stats do not aggregate the compact dependency summaries.');
game_stats_expect(str_contains($writer, 'missing_base_game_dependency_count'), 'Base-game dependency counts are not cached.');
game_stats_expect(str_contains($writer, 'refreshStale'), 'Explicit stale-reconciliation support is missing from the stats repository.');
game_stats_expect(str_contains($writer, 'WHERE game_id IS NULL'), 'Global totals do not account for unassigned files.');

game_stats_expect(str_contains($dashboardUseCase, 'DashboardStatsQuery'), 'Dashboard use case does not depend on the application query port.');
game_stats_expect(!str_contains($dashboardUseCase, 'PDO'), 'Dashboard use case must not depend on PDO.');
game_stats_expect(str_contains($dashboardQuery, 'PdoGameCatalogStats'), 'Dashboard PDO adapter is not using the game stats cache.');
game_stats_expect(!str_contains($dashboardQuery, 'refreshStale('), 'Dashboard PDO adapter must not rebuild stale game projections in the request.');
game_stats_expect(str_contains($dashboardPage, 'PdoDashboardStatsQuery'), 'Dashboard page is not composing the PDO stats adapter.');

foreach ([$games, $library, $api] as $source) {
    game_stats_expect(str_contains($source, 'ue_game_catalog_stats'), 'A common catalogue read path is not using cached game statistics.');
    game_stats_expect(!str_contains($source, 'refreshStale('), 'A common catalogue read path must not rebuild stale projections synchronously.');
}

foreach ([$searchJob, $dependencyJob, $affectedJob] as $source) {
    game_stats_expect(str_contains($source, 'PdoGameCatalogStats'), 'A dependency/import worker does not refresh cached game counters.');
    game_stats_expect(str_contains($source, 'rebuildGame'), 'A dependency/import worker does not rebuild the affected game row.');
}

fwrite(STDOUT, "Game catalog stats contract tests passed.\n");
