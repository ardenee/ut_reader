<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders the administrator Full Sync workflow for one game.
 * Why: A game-wide rescan must rebuild package identities first, then resolve dependencies against the complete rebuilt
 *      provider set in bounded batches, and finally publish consistent summary/stat projections.
 * Role: Web UI entry point; reusable maintenance logic lives behind file-maintenance.php services and full-sync.js.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

function full_sync_int(string $key): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? 0 : max(0, (int)$value);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Full Sync')) {
        exit;
    }

    $games = catalog_all(
        $db,
        'SELECT g.id, g.name, COUNT(f.id) catalog_file_count, COALESCE(SUM(f.file_size), 0) total_size'
        . ' FROM ue_games g'
        . ' LEFT JOIN ue_files f ON f.game_id=g.id AND f.scan_status="verified"'
        . ' GROUP BY g.id, g.name ORDER BY g.name'
    );
    $selectedGameId = full_sync_int('game_id');
    if ($selectedGameId === 0 && $games) {
        $selectedGameId = (int)$games[0]['id'];
    }
    $selectedGame = null;
    foreach ($games as $game) {
        if ((int)$game['id'] === $selectedGameId) {
            $selectedGame = $game;
            break;
        }
    }
    $syncFiles = $selectedGame === null ? [] : catalog_all(
        $db,
        'SELECT id, original_name, package_name, md5, package_guid FROM ue_files '
        . 'WHERE game_id=? AND scan_status="verified" ORDER BY package_name, original_name, id',
        [$selectedGameId]
    );

    catalog_head('Full Sync');
    echo <<<'CSS'
<style>
.full-sync-choice { display: flex; align-items: end; gap: 12px; flex-wrap: wrap; }
.full-sync-choice label { display: grid; gap: 6px; min-width: min(420px, 100%); }
.full-sync-scope { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: 16px 0; }
.full-sync-scope .stat { min-height: auto; }
.full-sync-warning { border-left: 4px solid #f6c453; padding-left: 12px; }
.full-sync-start { margin-top: 16px; }
.full-sync-overlay { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; padding: 20px; background: rgba(3,8,18,.72); backdrop-filter: blur(3px); }
.full-sync-dialog { width: min(630px,100%); padding: 24px; border: 1px solid var(--line2); border-radius: 14px; background: #111b2d; box-shadow: 0 24px 70px rgba(0,0,0,.5); }
.full-sync-dialog h2 { margin: 0 0 8px; }
.full-sync-dialog p { margin: 0 0 16px; }
.full-sync-progress { height: 14px; overflow: hidden; border: 1px solid var(--line2); border-radius: 999px; background: rgba(255,255,255,.05); }
.full-sync-progress > span { display: block; width: 0; height: 100%; border-radius: inherit; background: linear-gradient(90deg,#76a9ff,#9dc2ff); transition: width .18s linear; }
.full-sync-count { margin-top: 9px; color: var(--muted); font-size: 13px; }
.full-sync-loading { display: none; align-items: center; gap: 10px; margin-top: 16px; color: var(--text); }
.full-sync-loading.is-visible { display: flex; }
.full-sync-spinner { width: 17px; height: 17px; border: 3px solid rgba(157,194,255,.25); border-top-color:#9dc2ff; border-radius: 50%; animation: full-sync-spin .8s linear infinite; }
.full-sync-failures { max-height: 220px; overflow: auto; margin: 14px 0 0; padding: 10px 14px; border: 1px solid rgba(255,107,122,.55); border-radius: 8px; color: #ffd9de; background: rgba(255,107,122,.1); white-space: pre-wrap; }
.full-sync-result-actions { display: flex; gap: 8px; margin-top: 16px; }
@keyframes full-sync-spin { to { transform: rotate(360deg); } }
@media (max-width: 700px) { .full-sync-scope { grid-template-columns: 1fr; } }
</style>
CSS;
    echo CatalogUi::pageHeader(
        'Full Sync',
        'Validate every verified package in one game against storage, re-import it, then rebuild dependency projections from the complete game.',
        ['Back to dashboard' => 'dashboard.php']
    );

    $synced = full_sync_int('synced');
    $removed = full_sync_int('removed');
    $total = full_sync_int('total');
    $failed = full_sync_int('failed');
    if ($total > 0) {
        $message = 'Last full sync: ' . $synced . ' re-imported';
        if ($removed > 0) {
            $message .= ', ' . $removed . ' missing stored file record(s) removed';
        }
        $message .= ', from ' . $total . ' verified catalog record(s).';
        if ($failed > 0) {
            $message .= ' ' . $failed . ' operation(s) reported an issue.';
            echo CatalogUi::alert('warning', $message, 'The page shows individual failures at the end of a run.');
        } else {
            echo CatalogUi::alert('success', $message);
        }
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Select game</h2><p>Choose the game whose verified catalog packages should be checked against storage.</p></div></div><div class="ui-section__body">';
    if (!$games) {
        echo CatalogUi::emptyState(
            'No games available',
            'Create a game before running a full sync.',
            ['label' => 'Game Admin', 'href' => 'game-manager.php']
        );
    } else {
        echo '<form method="get" class="full-sync-choice">';
        echo '<label for="full-sync-game">Game<select id="full-sync-game" name="game_id">';
        foreach ($games as $game) {
            echo '<option value="' . (int)$game['id'] . '"'
                . ((int)$game['id'] === $selectedGameId ? ' selected' : '') . '>'
                . catalog_h($game['name']) . ' — ' . (int)$game['catalog_file_count']
                . ' verified packages</option>';
        }
        echo '</select></label>';
        echo CatalogUi::button('Choose game', ['type' => 'submit', 'variant' => 'secondary']);
        echo '</form>';
    }
    echo '</div></section>';

    if ($selectedGame !== null) {
        $count = count($syncFiles);
        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>'
            . catalog_h($selectedGame['name'])
            . '</h2><p>Storage validation, scanner rebuild and dependency reconciliation scope.</p></div></div>'
            . '<div class="ui-section__body">';
        echo '<div class="full-sync-scope">';
        echo '<div class="stat"><h2>' . $count . '</h2><p>Verified packages</p></div>';
        echo '<div class="stat"><h2>' . catalog_h(catalog_bytes((int)$selectedGame['total_size']))
            . '</h2><p>Recorded verified size</p></div>';
        echo '<div class="stat"><h2>4 phases</h2><p>Check, rebuild, resolve, finalize</p></div>';
        echo '</div>';
        echo '<p class="full-sync-warning"><strong>For each verified package:</strong> Full Sync checks whether the stored package still exists. A missing package is removed together with its compact metadata, lookup projections, locations and catalog references. Existing packages are re-imported through the normal scanner. After every package identity is rebuilt, Full Sync rebuilds the game provider projection, resolves dependencies in bounded batches of up to 100 packages, refreshes package summaries, and finally rebuilds the cached game counters.</p>';
        if ($count === 0) {
            echo '<p class="muted">This game has no verified catalog packages to sync.</p>';
        } else {
            echo '<form id="full-sync-form" class="full-sync-start" method="post" action="file-maintenance.php">';
            echo '<input type="hidden" name="csrf" value="'
                . catalog_h(catalog_csrf('catalog-maintenance')) . '">';
            echo '<input type="hidden" name="game_id" value="' . (int)$selectedGame['id'] . '">';
            echo CatalogUi::button(
                'Start full sync for ' . $selectedGame['name'],
                ['type' => 'submit', 'variant' => 'danger']
            );
            echo '</form>';
            echo '<script id="full-sync-files" type="application/json">'
                . json_encode($syncFiles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                . '</script>';
        }
        echo '</div></section>';
    }

    $fullSyncScriptVersion = @filemtime(__DIR__ . '/full-sync.js');
    echo '<script src="full-sync.js?v=' . (int)($fullSyncScriptVersion ?: 1) . '"></script>';
    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Full Sync Error');
    echo CatalogUi::alert('danger', $e->getMessage(), 'The full sync page could not be loaded.');
    catalog_foot();
}
