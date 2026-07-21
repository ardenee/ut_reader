<?php
declare(strict_types=1);

function game_manager_lifecycle_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$lifecycle = file_get_contents(__DIR__ . '/../lib/GameManagerLifecycle.php');
game_manager_lifecycle_expect(is_string($lifecycle), 'Could not read GameManagerLifecycle.php.');
foreach ([
    'OPTIMIZE TABLE',
    'gm_lifecycle_reset_game',
    'gm_lifecycle_delete_game',
    'DELETE FROM ue_games WHERE id=?',
    'DELETE FROM ue_pak_archives WHERE game_id=?',
    'unverified_queue_game_id=?',
    'DELETE FROM ue_base_game_files WHERE game_id=?',
    "'ue_names'",
    "'ue_imports'",
    "'ue_exports'",
    "'ue_asset_registry_assets'",
    "'ue_asset_registry_dependencies'",
    "'ue_files'",
] as $fragment) {
    game_manager_lifecycle_expect(
        str_contains($lifecycle, $fragment),
        'Game lifecycle implementation is missing: ' . $fragment
    );
}
game_manager_lifecycle_expect(
    str_contains($lifecycle, 'gm_lifecycle_remove_staged_storage'),
    'Game reset does not remove game-associated staged storage.'
);
game_manager_lifecycle_expect(
    str_contains($lifecycle, "error_log('[UnrealDB game lifecycle] OPTIMIZE TABLE"),
    'Table optimisation failures are not logged.'
);

$page = file_get_contents(__DIR__ . '/../game-manager.php');
game_manager_lifecycle_expect(is_string($page), 'Could not read game-manager.php.');
foreach ([
    "require_once __DIR__ . '/lib/GameManagerLifecycle.php';",
    "if (\$action === 'delete_game')",
    'class="game-delete-form"',
    'confirm_delete',
    'Permanently delete ',
    'This cannot be undone.',
    'Deletion and optimisation in progress',
    'Reset and optimisation in progress',
    'window.CatalogLongJob.poll',
] as $fragment) {
    game_manager_lifecycle_expect(
        str_contains($page, $fragment),
        'Game Manager lifecycle UI is missing: ' . $fragment
    );
}

game_manager_lifecycle_expect(
    str_contains($page, 'gm_lifecycle_reset_game($db, $config, $gameId'),
    'Game reset does not use the optimising lifecycle implementation.'
);
game_manager_lifecycle_expect(
    str_contains($page, 'gm_lifecycle_delete_game($db, $config, $gameId'),
    'Game deletion does not use the lifecycle implementation.'
);

echo "Game Manager lifecycle contract tests passed.\n";
