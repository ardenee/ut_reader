<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $games = catalog_all($db, 'SELECT g.id, g.name, g.slug, g.description, p.engine_key profile_engine, COUNT(f.id) file_count, COALESCE(SUM(f.file_size),0) total_size FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 LEFT JOIN ue_files f ON f.game_id=g.id GROUP BY g.id, p.id ORDER BY g.name');

    catalog_head('Games');
    catalog_page_header('Games', 'Browse the public catalog by game.', ['Search' => 'index.php?page=search', 'Library' => 'library.php']);
    echo '<div class="card"><table><tr><th>Game</th><th>Profile engine</th><th>Files</th><th>Total size</th><th>Open</th></tr>';
    foreach ($games as $game) {
        $engine = $game['profile_engine'] ?: 'no profile';
        $class = $game['profile_engine'] ? 'good-pill' : 'bad-pill';
        echo '<tr><td><strong>' . catalog_h($game['name']) . '</strong><br><span class="muted small">' . catalog_h($game['slug']) . '</span></td><td><span class="pill ' . $class . '">' . catalog_h($engine) . '</span></td><td>' . (int)$game['file_count'] . '</td><td>' . catalog_h(catalog_bytes((int)$game['total_size'])) . '</td><td><a class="button" href="game-files.php?id=' . (int)$game['id'] . '">Open files</a></td></tr>';
    }
    echo '</table></div>';
    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
