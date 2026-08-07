<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies game missing counts behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function game_missing_count_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$games = file_get_contents($root . '/games.php');
$library = file_get_contents($root . '/library.php');
$support = file_get_contents($root . '/lib/CatalogSupport.php');
$endpoint = file_get_contents($root . '/api/v1/game-missing-counts.php');
$javascript = file_get_contents($root . '/assets/game-manager-missing-counts.js');

foreach ([
    'games.php' => $games,
    'library.php' => $library,
    'CatalogSupport.php' => $support,
    'game-missing-counts.php' => $endpoint,
    'game-manager-missing-counts.js' => $javascript,
] as $name => $content) {
    game_missing_count_expect(is_string($content), $name . ' is missing.');
}

foreach ([$games, $library] as $page) {
    game_missing_count_expect(str_contains($page, 'd.status="missing" AND df.game_id=g.id'), 'Per-game missing dependency query is absent.');
    game_missing_count_expect(str_contains($page, 'missing_dependency_count'), 'Per-game missing dependency result is not selected.');
    game_missing_count_expect(str_contains($page, 'Missing dependencies'), 'Missing dependency column is not rendered.');
}

game_missing_count_expect(str_contains($library, "SELECT COUNT(*) c FROM ue_dependencies WHERE status=\"missing\""), 'Library global missing dependency total is absent.');
game_missing_count_expect(str_contains($library, "catalog_stat_card('Missing dependencies'"), 'Library missing dependency stat card is absent.');

game_missing_count_expect(str_contains($support, "basename((string)(\$_SERVER['SCRIPT_NAME'] ?? '')) === 'game-manager.php'"), 'Game Admin enhancement is not page-scoped.');
game_missing_count_expect(str_contains($support, 'game-manager-missing-counts.js'), 'Game Admin count script is not attached.');
game_missing_count_expect(str_contains($support, "str_replace('</body>',"), 'Game Admin count script is not injected into the HTML response.');

game_missing_count_expect(str_contains($endpoint, 'catalog_api_require_admin(false)'), 'Game Admin count endpoint is not admin-only.');
game_missing_count_expect(str_contains($endpoint, 'COUNT(*) missing_dependency_count'), 'Game Admin endpoint does not aggregate missing rows.');
game_missing_count_expect(str_contains($endpoint, 'GROUP BY f.game_id'), 'Game Admin endpoint does not group counts by game.');
game_missing_count_expect(str_contains($endpoint, "'counts' => \$counts"), 'Game Admin endpoint does not return the per-game map.');

game_missing_count_expect(str_contains($javascript, "header.textContent = 'Missing dependencies'"), 'Game Admin table header is not added.');
game_missing_count_expect(str_contains($javascript, 'input[name="game_id"]'), 'Game Admin rows are not matched to game IDs.');
game_missing_count_expect(str_contains($javascript, "fetch('api/v1/game-missing-counts.php'"), 'Game Admin does not load count data.');
game_missing_count_expect(str_contains($javascript, "count > 0 ? 'amber' : 'good-pill'"), 'Game Admin count status is not visually distinguished.');

echo "Game missing dependency count contract tests passed.\n";
