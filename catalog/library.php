<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_head('Library');

    $games = catalog_all($db, 'SELECT g.id, g.name, g.slug, g.description, p.engine_key profile_engine, COUNT(DISTINCT f.id) file_count, SUM(f.scan_status="verified") verified_count, SUM(f.scan_status="failed") failed_count FROM ue_games g LEFT JOIN ue_game_profiles p ON p.game_id=g.id AND p.is_active=1 LEFT JOIN ue_files f ON f.game_id=g.id GROUP BY g.id, p.id ORDER BY g.name');
    $totalFiles = catalog_count($db, 'SELECT COUNT(*) c FROM ue_files');
    $verified = catalog_count($db, 'SELECT COUNT(*) c FROM ue_files WHERE scan_status="verified"');
    $duplicates = catalog_count($db, 'SELECT COUNT(*) c FROM ue_files WHERE scan_status="duplicate"');

    catalog_page_header('Library', 'Browse local games and files, review duplicates, and check missing dependency status.', ['Games' => 'games.php', 'Search' => 'index.php?page=search', 'Duplicates' => 'duplicates.php', 'Missing Files' => 'missing.php']);

    echo '<div class="grid">';
    catalog_stat_card('Total files', $totalFiles);
    catalog_stat_card('Verified files', $verified, '', 'good');
    catalog_stat_card('Duplicate rows', $duplicates);
    catalog_stat_card('Games', count($games));
    echo '</div>';

    echo '<div class="card"><h2>Library tools</h2><div class="grid">';
    catalog_tool_card('Game browser', 'games.php', 'Browse the public game catalog and open file lists.', 'primary');
    catalog_tool_card('Search catalog', 'index.php?page=search', 'Search package names, filenames, hashes, GUIDs, imports, and exports.');
    catalog_tool_card('Duplicate files', 'duplicates.php', 'Review duplicate files and package GUID collisions.');
    catalog_tool_card('Missing files', 'missing.php', 'Review dependency gaps and request files from a parent site.');
    echo '</div></div>';

    echo '<div class="card"><h2>Games</h2>';
    if (!$games) {
        echo '<p class="muted">No games configured yet. Start in <a href="game-manager.php">Game Admin</a>.</p>';
    } else {
        echo '<table><tr><th>Game</th><th>Profile engine</th><th>Files</th><th>Verified</th><th>Failed</th><th>Open</th></tr>';
        foreach ($games as $game) {
            $engine = $game['profile_engine'] ?: 'missing profile';
            $engineClass = $game['profile_engine'] ? 'good-pill' : 'bad-pill';
            echo '<tr><td><strong>' . catalog_h($game['name']) . '</strong><br><span class="muted small">' . catalog_h($game['slug']) . '</span></td><td><span class="pill ' . $engineClass . '">' . catalog_h($engine) . '</span></td><td>' . (int)$game['file_count'] . '</td><td>' . (int)$game['verified_count'] . '</td><td>' . (int)$game['failed_count'] . '</td><td><a class="button" href="game-files.php?id=' . (int)$game['id'] . '">Files</a> <a class="button" href="profiled-upload.php?game_id=' . (int)$game['id'] . '">Upload</a> <a class="button" href="game-manager.php?game_id=' . (int)$game['id'] . '">Edit</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Library error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
