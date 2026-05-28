<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/GameProfiles.php';

function local_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function sources_csrf(): string
{
    $_SESSION['sources_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['sources_csrf'];
}

function sources_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['sources_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!local_admin()) {
        catalog_head('Sources');
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        sources_check_csrf();
        $gameId = (int)($_POST['game_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $type = (string)($_POST['source_type'] ?? 'local_path');
        $base = trim((string)($_POST['base_path'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($gameId <= 0 || $name === '' || $base === '') {
            throw new RuntimeException('Game, source name, and path/URL are required.');
        }
        gp_required_profile_for_game($db, $gameId);
        if (!in_array($type, ['local_path', 'http_mirror', 'redirect_server'], true)) {
            throw new RuntimeException('Invalid source type.');
        }

        $stmt = $db->prepare('INSERT INTO ue_sources(game_id,name,source_type,base_path,notes) VALUES(?,?,?,?,?)');
        $stmt->execute([$gameId, $name, $type, $base, $notes ?: null]);
        $_SESSION['sources_flash'] = 'Source added.';
        header('Location: game-manager.php?game_id=' . $gameId);
        exit;
    }

    catalog_head('Game Sources');

    if (isset($_SESSION['sources_flash'])) {
        echo '<div class="card"><strong>' . catalog_h($_SESSION['sources_flash']) . '</strong></div>';
        unset($_SESSION['sources_flash']);
    }

    $selectedGameId = (int)($_GET['game_id'] ?? 0);
    echo '<div class="card hero"><h1>Game Sources</h1><p class="muted">Add folders, redirect servers, or HTTP mirrors for a specific game. Sources belong to games so scans and downloads stay tied to the correct library.</p>';
    catalog_page_links(['Game Admin' => 'game-manager.php' . ($selectedGameId ? '?game_id=' . $selectedGameId : ''), 'Scan Sources' => 'source-scan.php', 'Setup' => 'setup.php']);
    echo '</div>';

    $sources = catalog_all($db, 'SELECT s.*, g.name game_name, p.engine_key profile_engine FROM ue_sources s JOIN ue_games g ON g.id=s.game_id LEFT JOIN ue_game_profiles p ON p.game_id=g.id AND p.is_active=1 ORDER BY g.name, s.name');
    echo '<div class="card"><h2>Configured sources</h2>';
    if (!$sources) {
        echo '<p class="muted">No sources configured yet.</p>';
    } else {
        echo '<table><tr><th>Game</th><th>Source</th><th>Type</th><th>Path / URL</th><th>Notes</th><th>Action</th></tr>';
        foreach ($sources as $src) {
            echo '<tr><td>' . catalog_h($src['game_name']) . '<br><span class="pill amber">' . catalog_h($src['profile_engine'] ?? 'no profile') . '</span></td><td>' . catalog_h($src['name']) . '</td><td class="mono">' . catalog_h($src['source_type']) . '</td><td class="mono path">' . catalog_h($src['base_path']) . '</td><td>' . catalog_h($src['notes']) . '</td><td><a class="button" href="source-scan.php">Scan</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    $games = catalog_all($db, 'SELECT g.id,g.name,p.engine_key profile_engine FROM ue_games g JOIN ue_game_profiles p ON p.game_id=g.id AND p.is_active=1 ORDER BY g.name');
    echo '<div class="card"><h2>Add source to game</h2>';
    if (!$games) {
        echo '<p class="muted">No games with active profiles exist yet. Add a game/profile first in <a href="game-manager.php">Game Admin</a>.</p>';
    } else {
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(sources_csrf()) . '"><p><label>Game<br><select name="game_id">';
        foreach ($games as $game) {
            $sel = (int)$game['id'] === $selectedGameId ? ' selected' : '';
            echo '<option value="' . (int)$game['id'] . '"' . $sel . '>' . catalog_h($game['name'] . ' / ' . $game['profile_engine']) . '</option>';
        }
        echo '</select></label></p><p><label>Source name<br><input name="name" required style="min-width:320px" placeholder="Main server folder, Redirect server, Public mirror"></label></p><p><label>Type<br><select name="source_type"><option value="local_path">Local folder</option><option value="http_mirror">HTTP mirror</option><option value="redirect_server">Redirect server</option></select></label></p><p><label>Folder path or URL<br><input name="base_path" required style="min-width:640px" placeholder="/volume1/game_servers/ut2004/System or https://redirect.example.com/ut2004/"></label></p><p><label>Notes<br><textarea name="notes" rows="3" style="width:100%"></textarea></label></p><button>Add source</button></form>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Sources error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
