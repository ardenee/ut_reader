<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies game manager read performance behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function game_manager_read_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$page = file_get_contents(__DIR__ . '/../game-manager.php');
game_manager_read_expect(is_string($page) && $page !== '', 'Could not read game-manager.php.');

foreach ([
    'function gm_game_rows(PDO $db): array',
    'PdoGameCatalogStats',
    'LEFT JOIN ue_game_catalog_stats gs ON gs.game_id=g.id',
    'SELECT game_id,COUNT(*) source_count FROM ue_sources GROUP BY game_id',
    'SELECT unverified_queue_game_id game_id,COUNT(*) unverified_count',
    '$csrfToken = catalog_csrf(\'game_manager\')',
    'session_write_close();',
    "catalog_flash(\$flash);",
] as $fragment) {
    game_manager_read_expect(
        str_contains($page, $fragment),
        'Game Manager non-blocking read implementation is missing: ' . $fragment
    );
}

game_manager_read_expect(
    !str_contains($page, 'COUNT(DISTINCT f.id)'),
    'Game Manager still uses the files-by-sources COUNT(DISTINCT) query.'
);
game_manager_read_expect(
    !str_contains($page, 'LEFT JOIN ue_files f ON (f.game_id=g.id OR'),
    'Game Manager still uses the unindexable OR file join.'
);
game_manager_read_expect(
    substr_count($page, "catalog_csrf('game_manager')") === 1,
    'Game Manager should precompute one GET CSRF token and reuse it in every form.'
);

$headPosition = strpos($page, "catalog_head('Game Admin');");
$closePosition = strpos($page, 'session_write_close();', $headPosition === false ? 0 : $headPosition);
$queryPosition = strpos($page, '$profileChoices = catalog_all', $headPosition === false ? 0 : $headPosition);
game_manager_read_expect(
    $headPosition !== false && $closePosition !== false && $queryPosition !== false
        && $headPosition < $closePosition && $closePosition < $queryPosition,
    'Game Manager does not release the session before its read queries.'
);

echo "Game Manager read performance contract tests passed.\n";
