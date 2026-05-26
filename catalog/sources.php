<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/lib/CatalogSupport.php';

function local_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!local_admin()) {
        catalog_head('Sources');
        echo '<div class="card"><h1>Admin required</h1><p>Log in through the main catalog admin page first.</p></div>';
        catalog_foot();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $gameId = (int)($_POST['game_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $type = (string)($_POST['source_type'] ?? 'local_path');
        $base = trim((string)($_POST['base_path'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($gameId <= 0 || $name === '' || $base === '') {
            throw new RuntimeException('Game, name, and base path are required.');
        }
        if (!in_array($type, ['local_path', 'http_mirror', 'redirect_server'], true)) {
            throw new RuntimeException('Invalid source type.');
        }

        $stmt = $db->prepare('INSERT INTO ue_sources(game_id,name,source_type,base_path,notes) VALUES(?,?,?,?,?)');
        $stmt->execute([$gameId, $name, $type, $base, $notes]);
        header('Location: sources.php');
        exit;
    }

    catalog_head('Sources');
    echo '<div class="card"><h1>Local / mirror sources</h1><p class="muted">Use this to track predefined file locations such as a UT2004 server folder, redirect server, or HTTP mirror. These locations are admin-facing metadata; public file downloads should still go through the catalog controller.</p></div>';

    $sources = catalog_all($db, 'SELECT s.*, g.name game_name FROM ue_sources s JOIN ue_games g ON g.id=s.game_id ORDER BY g.name, s.name');
    echo '<div class="card"><h2>Configured sources</h2>';
    if (!$sources) {
        echo '<p class="muted">No sources configured yet.</p>';
    } else {
        echo '<table><tr><th>Game</th><th>Name</th><th>Type</th><th>Base</th><th>Notes</th></tr>';
        foreach ($sources as $src) {
            echo '<tr><td>' . catalog_h($src['game_name']) . '</td><td>' . catalog_h($src['name']) . '</td><td class="mono">' . catalog_h($src['source_type']) . '</td><td class="mono path">' . catalog_h($src['base_path']) . '</td><td>' . catalog_h($src['notes']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    $games = catalog_all($db, 'SELECT id,name FROM ue_games ORDER BY name');
    echo '<div class="card"><h2>Add source</h2><form method="post"><p><label>Game<br><select name="game_id">';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '">' . catalog_h($game['name']) . '</option>';
    }
    echo '</select></label></p><p><label>Name<br><input name="name" required style="min-width:320px"></label></p><p><label>Type<br><select name="source_type"><option value="local_path">local_path</option><option value="http_mirror">http_mirror</option><option value="redirect_server">redirect_server</option></select></label></p><p><label>Base path or URL<br><input name="base_path" required style="min-width:640px" placeholder="/volume1/game_servers/ut2004/System or https://redirect.example.com/ut2004/"></label></p><p><label>Notes<br><textarea name="notes" rows="3" style="width:100%"></textarea></label></p><button>Add source</button></form></div>';

    echo '<div class="card"><h2>Next scanner step</h2><p class="muted">The next step is adding a source scanner that walks local paths or reads mirror indexes, scans discovered package files, and records file-to-source links in <code>ue_file_locations</code>.</p></div>';
    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
