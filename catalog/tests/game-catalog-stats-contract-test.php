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
$migration = file_get_contents($root . '/migrations/202607270005_game_catalog_stats.php');
$writer = file_get_contents($root . '/src/Infrastructure/Persistence/PdoGameCatalogStats.php');
$dashboard = file_get_contents($root . '/src/Application/Dashboard/CatalogDashboardStats.php');
$games = file_get_contents($root . '/games.php');
$library = file_get_contents($root . '/library.php');
$api = file_get_contents($root . '/api/v1/game-missing-counts.php');
$searchJob = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogSearchIndexJobHandler.php');
$dependencyJob = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogDependencyRefreshJobHandler.php');
$affectedJob = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshJobHandler.php');

foreach (compact('migration', 'writer', 'dashboard', 'games', 'library', 'api', 'searchJob', 'dependencyJob', 'affectedJob') as $name => $source) {
    game_stats_expect(is_string($source) && $source !== '', 'Could not read ' . $name . ' source.');
}

game_stats_expect(str_contains($migration, "'version' => '202607270005'"), 'Game stats migration version is missing.');
game_stats_expect(str_contains($migration, 'ue_game_catalog_stats'), 'Game stats table is not created.');
game_stats_expect(str_contains($migration, 'PdoGameCatalogStats'), 'Migration does not perform the initial cache backfill.');
game_stats_expect(str_contains($writer, 'GET_LOCK(?,0)'), 'Per-game rebuilds are not protected by a non-blocking advisory lock.');
game_stats_expect(str_contains($writer, 'ue_dependency_package_summaries'), 'Game stats do not aggregate the compact dependency summaries.');
game_stats_expect(str_contains($writer, 'missing_base_game_dependency_count'), 'Base-game dependency counts are not cached.');
game_stats_expect(str_contains($writer, 'refreshStale'), 'Read-through stale reconciliation is missing.');
game_stats_expect(str_contains($writer, 'WHERE game_id IS NULL'), 'Global totals do not account for unassigned files.');

foreach ([$dashboard, $games, $library, $api] as $source) {
    game_stats_expect(str_contains($source, 'PdoGameCatalogStats'), 'A common catalogue page is not using the game stats cache.');
    game_stats_expect(str_contains($source, 'refreshStale(300)'), 'A common catalogue page is missing bounded stale reconciliation.');
}
game_stats_expect(str_contains($games, 'LEFT JOIN ue_game_catalog_stats'), 'Games page does not join cached counters.');
game_stats_expect(str_contains($library, 'LEFT JOIN ue_game_catalog_stats'), 'Library page does not join cached counters.');
game_stats_expect(str_contains($api, 'LEFT JOIN ue_game_catalog_stats'), 'Missing-count API does not join cached counters.');

foreach ([$searchJob, $dependencyJob, $affectedJob] as $source) {
    game_stats_expect(str_contains($source, 'PdoGameCatalogStats'), 'A dependency/import worker does not refresh cached game counters.');
    game_stats_expect(str_contains($source, 'rebuildGame'), 'A dependency/import worker does not rebuild the affected game row.');
}

fwrite(STDOUT, "Game catalog stats contract tests passed.\n");
