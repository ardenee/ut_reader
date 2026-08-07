<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies game missing filter links behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function game_missing_filter_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$games = file_get_contents($root . '/games.php');
$filtered = file_get_contents($root . '/game-missing.php');

foreach (compact('games', 'filtered') as $name => $source) {
    game_missing_filter_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

game_missing_filter_expect(
    substr_count($games, "'game_id' => (int)\$game['id']") >= 2
        && str_contains($games, "'dependency_type' => 'all'")
        && str_contains($games, "'dependency_type' => 'base_game'")
        && str_contains($games, "'game-missing.php?' . http_build_query")
        && !str_contains($games, '<a href="missing.php" title="All missing dependency object rows')
        && !str_contains($games, '<a href="missing.php" title="Missing dependency rows that reference official base-game packages'),
    'Games dependency counts do not link to exact game/type drill-downs.'
);

game_missing_filter_expect(
    str_contains($filtered, "game_missing_int('game_id')")
        && str_contains($filtered, "\$_GET['dependency_type']")
        && str_contains($filtered, "return \$type === 'base_game' ? 'base_game' : 'all'")
        && str_contains($filtered, "base_game_package_exists_sql('s.required_package', 's.game_id')")
        && str_contains($filtered, "base_game_dependency_is_official_sql('f', 'd')"),
    'Filtered dependency page does not enforce game and dependency-type scope.'
);

game_missing_filter_expect(
    str_contains($filtered, 'Every count and table on this page is scoped to the selected game and dependency type.')
        && str_contains($filtered, 'Files with missing dependencies')
        && str_contains($filtered, 'Missing packages')
        && str_contains($filtered, 'Missing objects for package:')
        && str_contains($filtered, "game_missing_pagination(\$gameId, \$type")
        && str_contains($filtered, "game_missing_url(\$gameId, \$type, ['package' => \$name])")
        && str_contains($filtered, "game_missing_url(\$gameId, \$type, ['package' => \$package])"),
    'Filtered dependency counts, tables, package details or pagination do not preserve scope.'
);

fwrite(STDOUT, "Game missing-dependency filter link contract tests passed.\n");
