<?php
declare(strict_types=1);

function backup_job_game_column_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$navigation = file_get_contents(__DIR__ . '/../lib/CatalogNavigation.php');
backup_job_game_column_expect(is_string($navigation), 'Could not read CatalogNavigation.php.');
backup_job_game_column_expect(
    str_contains($navigation, 'game-backup-job-game.js'),
    'Game Backups does not load the recent-job game column script.'
);

$script = file_get_contents(__DIR__ . '/../assets/game-backup-job-game.js');
backup_job_game_column_expect(is_string($script), 'Could not read game-backup-job-game.js.');
foreach ([
    "gameHeader.textContent = 'Game'",
    'header.insertBefore(gameHeader, header.lastElementChild)',
    'payload.game_id',
    'result.target_game_name',
    'result.game_name',
    "isImport ? 'restore target' : 'backup source'",
] as $fragment) {
    backup_job_game_column_expect(
        str_contains($script, $fragment),
        'Recent backup jobs game column is missing: ' . $fragment
    );
}
backup_job_game_column_expect(
    str_contains($script, 'row.insertBefore(gameCell, row.lastElementChild)'),
    'The Game column is not inserted before Updated, which would disturb existing result-column indexes.'
);

echo "Game backup job Game-column contract tests passed.\n";
