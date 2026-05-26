<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $games = catalog_all($db, 'SELECT g.*, COUNT(f.id) file_count, COALESCE(SUM(f.file_size),0) total_size FROM ue_games g LEFT JOIN ue_files f ON f.game_id=g.id GROUP BY g.id ORDER BY g.name');

    catalog_head('Games');
    echo '<div class="card"><h1>Unreal Games</h1><p class="muted">Popup-enabled catalog view. Use this page while the old router is being split into smaller files.</p><p><a class="button" href="index.php">Old catalog home</a> <a class="button" href="sources.php">Sources</a> <a class="button" href="source-scan.php">Source scanner</a></p></div>';
    echo '<div class="card"><table><tr><th>Game</th><th>Engine</th><th>Files</th><th>Total size</th><th>Open</th></tr>';
    foreach ($games as $game) {
        echo '<tr><td>' . catalog_h($game['name']) . '</td><td class="mono">' . catalog_h($game['engine_key']) . '</td><td>' . (int)$game['file_count'] . '</td><td>' . catalog_h(catalog_bytes((int)$game['total_size'])) . '</td><td><a class="button" href="game-files.php?id=' . (int)$game['id'] . '">open files</a></td></tr>';
    }
    echo '</table></div>';
    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
