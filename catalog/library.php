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

    $games = catalog_all($db, 'SELECT g.*, COUNT(f.id) file_count, SUM(f.scan_status="verified") verified_count, SUM(f.scan_status="failed") failed_count FROM ue_games g LEFT JOIN ue_files f ON f.game_id=g.id GROUP BY g.id ORDER BY g.name');
    $totalFiles = catalog_count($db, 'SELECT COUNT(*) c FROM ue_files');
    $verified = catalog_count($db, 'SELECT COUNT(*) c FROM ue_files WHERE scan_status="verified"');
    $duplicates = catalog_count($db, 'SELECT COUNT(*) c FROM ue_files WHERE scan_status="duplicate"');

    echo '<div class="card hero"><h1>Library</h1><p class="muted">Browse games, inspect files, search imports/exports, and review duplicate/stability status.</p>';
    catalog_page_links(['Games' => 'games.php', 'Search' => 'index.php?page=search', 'Duplicates' => 'duplicates.php', 'Setup' => 'setup.php']);
    echo '</div>';

    echo '<div class="grid">';
    catalog_stat_card('Total files', $totalFiles);
    catalog_stat_card('Verified files', $verified, '', 'good');
    catalog_stat_card('Duplicate rows', $duplicates);
    catalog_stat_card('Games', count($games));
    echo '</div>';

    echo '<div class="card"><h2>Library tools</h2><div class="grid">';
    catalog_tool_card('Game browser', 'games.php', 'Browse all configured games and open each game file list.', 'primary');
    catalog_tool_card('Search catalog', 'index.php?page=search', 'Search package names, file names, MD5, SHA1, GUID, imports, and exports.');
    catalog_tool_card('Duplicate manager', 'duplicates.php', 'Review duplicate Unreal package GUIDs and retire duplicate rows.');
    catalog_tool_card('Download/mirror view', 'download-admin.php', 'Review how public users get downloads and external mirror links.');
    echo '</div></div>';

    echo '<div class="card"><h2>Games</h2>';
    if (!$games) {
        echo '<p class="muted">No games configured yet. Start in <a href="setup.php">Setup</a>.</p>';
    } else {
        echo '<table><tr><th>Game</th><th>Engine</th><th>Files</th><th>Verified</th><th>Failed</th><th>Open</th></tr>';
        foreach ($games as $game) {
            echo '<tr><td>' . catalog_h($game['name']) . '</td><td class="mono">' . catalog_h($game['engine_key']) . '</td><td>' . (int)$game['file_count'] . '</td><td>' . (int)$game['verified_count'] . '</td><td>' . (int)$game['failed_count'] . '</td><td><a class="button" href="game-files.php?id=' . (int)$game['id'] . '">Files</a> <a class="button" href="index.php?page=game&id=' . (int)$game['id'] . '">Upload/Admin</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) catalog_head('Library error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
