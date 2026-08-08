<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and processes Game Sources administration.
 * Why: Source validation/profile checks and persistence now live in a shared source service.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Source\CatalogSourceAdminService;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Sources')) {
        exit;
    }

    $service = new CatalogSourceAdminService($db);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('sources');
        $gameId = $service->add($_POST);
        $_SESSION['sources_flash'] = 'Source added.';
        header('Location: game-manager.php?game_id=' . $gameId);
        exit;
    }

    catalog_head('Game Sources');
    catalog_flash($_SESSION['sources_flash'] ?? null);
    unset($_SESSION['sources_flash']);

    $selectedGameId = (int)($_GET['game_id'] ?? 0);
    catalog_page_header('Game Sources', 'Add local folders, redirect servers, or HTTP mirrors for a specific game. Sources belong to games so scans and downloads stay tied to the correct library.', ['Game Admin' => 'game-manager.php' . ($selectedGameId ? '?game_id=' . $selectedGameId : ''), 'Scan Sources' => 'source-scan.php', 'HTTP Source Scan' => 'http-source-scan.php']);

    $sources = $service->configuredSources();
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

    $games = $service->gamesWithActiveProfiles();
    echo '<div class="card"><h2>Add source to game</h2>';
    if (!$games) {
        echo '<p class="muted">No games with active profiles exist yet. Add a game/profile first in <a href="game-manager.php">Game Admin</a>.</p>';
    } else {
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('sources')) . '"><p><label>Game<br><select name="game_id">';
        foreach ($games as $game) {
            $sel = (int)$game['id'] === $selectedGameId ? ' selected' : '';
            echo '<option value="' . (int)$game['id'] . '"' . $sel . '>' . catalog_h($game['name'] . ' / ' . $game['profile_engine']) . '</option>';
        }
        echo '</select></label></p><p><label>Source name<br><input name="name" required style="min-width:320px" placeholder="Main server folder, Redirect server, Public mirror"></label></p><p><label>Type<br><select name="source_type"><option value="local_path">Local folder</option><option value="http_mirror">HTTP mirror</option><option value="redirect_server">Redirect server</option></select></label></p><p><label>Folder path or URL<br><input name="base_path" required style="min-width:640px" placeholder="/volume1/game_servers/ut2004/System or https://redirect.example.com/ut2004/"></label></p><p><label>Notes<br><textarea name="notes" rows="3" style="width:100%"></textarea></label></p><button>Add source</button></form>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Sources error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
