<?php
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
        'SELECT g.id, g.name, COUNT(f.id) verified_file_count, COALESCE(SUM(f.file_size), 0) total_size'
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
.full-sync-dialog { width: min(600px, 100%); padding: 24px; border: 1px solid var(--line2); border-radius: 14px; background: #111b2d; box-shadow: 0 24px 70px rgba(0,0,0,.5); }
.full-sync-dialog h2 { margin: 0 0 8px; }
.full-sync-dialog p { margin: 0 0 16px; }
.full-sync-progress { height: 14px; overflow: hidden; border: 1px solid var(--line2); border-radius: 999px; background: rgba(255,255,255,.05); }
.full-sync-progress > span { display: block; width: 0; height: 100%; border-radius: inherit; background: linear-gradient(90deg,#76a9ff,#9dc2ff); transition: width .18s linear; }
.full-sync-count { margin-top: 9px; color: var(--muted); font-size: 13px; }
.full-sync-loading { display: none; align-items: center; gap: 10px; margin-top: 16px; color: var(--text); }
.full-sync-loading.is-visible { display: flex; }
.full-sync-spinner { width: 17px; height: 17px; border: 3px solid rgba(157,194,255,.25); border-top-color: #9dc2ff; border-radius: 50%; animation: full-sync-spin .8s linear infinite; }
.full-sync-failures { max-height: 190px; overflow: auto; margin: 14px 0 0; padding: 10px 14px; border: 1px solid rgba(255,107,122,.55); border-radius: 8px; color: #ffd9de; background: rgba(255,107,122,.1); white-space: pre-wrap; }
.full-sync-result-actions { display: flex; gap: 8px; margin-top: 16px; }
@keyframes full-sync-spin { to { transform: rotate(360deg); } }
@media (max-width: 700px) { .full-sync-scope { grid-template-columns: 1fr; } }
</style>
CSS;
    echo CatalogUi::pageHeader('Full Sync', 'Re-import every stored verified package in one game through the normal Upload Files scanner.', ['Back to dashboard' => 'dashboard.php']);

    $synced = full_sync_int('synced');
    $total = full_sync_int('total');
    $failed = full_sync_int('failed');
    if ($total > 0) {
        $message = 'Last full sync: ' . $synced . '/' . $total . ' packages re-imported.';
        if ($failed > 0) {
            $message .= ' ' . $failed . ' package(s) failed.';
            echo CatalogUi::alert('warning', $message, 'Review any failure details shown during the sync before running it again.');
        } else {
            echo CatalogUi::alert('success', $message);
        }
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Select game</h2><p>Choose the game whose stored catalog packages should be refreshed.</p></div></div><div class="ui-section__body">';
    if (!$games) {
        echo CatalogUi::emptyState('No games available', 'Create a game before running a full sync.', ['label' => 'Game Admin', 'href' => 'game-manager.php']);
    } else {
        echo '<form method="get" class="full-sync-choice">';
        echo '<label for="full-sync-game">Game<select id="full-sync-game" name="game_id">';
        foreach ($games as $game) {
            echo '<option value="' . (int)$game['id'] . '"' . ((int)$game['id'] === $selectedGameId ? ' selected' : '') . '>' . catalog_h($game['name']) . ' — ' . (int)$game['verified_file_count'] . ' verified files</option>';
        }
        echo '</select></label>';
        echo CatalogUi::button('Choose game', ['type' => 'submit', 'variant' => 'secondary']);
        echo '</form>';
    }
    echo '</div></section>';

    if ($selectedGame !== null) {
        $count = (int)$selectedGame['verified_file_count'];
        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>' . catalog_h($selectedGame['name']) . '</h2><p>Full scanner-based refresh scope.</p></div></div><div class="ui-section__body">';
        echo '<div class="full-sync-scope">';
        echo '<div class="stat"><h2>' . $count . '</h2><p>Verified packages</p></div>';
        echo '<div class="stat"><h2>' . catalog_h(catalog_bytes((int)$selectedGame['total_size'])) . '</h2><p>Stored size</p></div>';
        echo '<div class="stat"><h2>100%</h2><p>Final dependency refresh</p></div>';
        echo '</div>';
        echo '<p class="full-sync-warning">Each package is re-imported using the same reader, validation, table-writing, and dependency logic as <strong>Upload Files</strong>. The original stored file is checked first, then the package record is refreshed. A final dependency pass runs after all packages have been processed.</p>';
        if ($count === 0) {
            echo '<p class="muted">This game has no verified packages to sync.</p>';
        } else {
            echo '<form id="full-sync-form" class="full-sync-start" method="post" action="file-maintenance.php">';
            echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('catalog-maintenance')) . '">';
            echo '<input type="hidden" name="operation" value="sync_game">';
            echo '<input type="hidden" name="game_id" value="' . (int)$selectedGame['id'] . '">';
            echo CatalogUi::button('Start full sync for ' . $selectedGame['name'], ['type' => 'submit', 'variant' => 'danger']);
            echo '</form>';
        }
        echo '</div></section>';
    }

    echo <<<'JS'
<script>
(function () {
    'use strict';
    var form = document.getElementById('full-sync-form');
    if (!form) return;

    function makeToken() {
        var bytes = new Uint8Array(18);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes).map(function (value) { return value.toString(16).padStart(2, '0'); }).join('');
        }
        return Date.now().toString(36) + Math.random().toString(36).slice(2);
    }

    function parseJson(response) {
        return response.text().then(function (text) {
            try { return JSON.parse(text); }
            catch (error) { throw new Error('Server returned an invalid maintenance response (HTTP ' + response.status + ').'); }
        });
    }

    function createOverlay() {
        var overlay = document.createElement('div');
        overlay.className = 'full-sync-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-live', 'assertive');
        overlay.innerHTML = '<div class="full-sync-dialog"><h2>Full game sync</h2><p class="full-sync-message">Preparing scanner…</p><div class="full-sync-progress"><span></span></div><div class="full-sync-count">Waiting for server…</div><div class="full-sync-loading"><span class="full-sync-spinner"></span><span>Loading updated sync page…</span></div></div>';
        document.body.appendChild(overlay);
        return overlay;
    }

    function showState(overlay, state) {
        var percent = Math.max(0, Math.min(100, Number(state.percent || 0)));
        var done = Number(state.done || 0);
        var total = Number(state.total || 0);
        var message = state.message || 'Working…';
        var fileMatch = message.match(/(?:Syncing|Synced|Skipped) file (\d+)\/(\d+)/);
        overlay.querySelector('.full-sync-progress > span').style.width = percent + '%';
        overlay.querySelector('.full-sync-message').textContent = message;

        if (state.stage === 'final_dependencies' && total > 0) {
            overlay.querySelector('.full-sync-count').textContent = total + ' of ' + total + ' packages synced; final dependency refresh (' + Math.round(percent) + '%)';
        } else if (fileMatch) {
            overlay.querySelector('.full-sync-count').textContent = fileMatch[1] + ' of ' + fileMatch[2] + ' packages (' + Math.round(percent) + '%)';
        } else {
            overlay.querySelector('.full-sync-count').textContent = total > 0
                ? done + ' of ' + total + ' packages (' + Math.round(percent) + '%)'
                : Math.round(percent) + '%';
        }
    }

    function poll(progressToken, overlay) {
        var active = true;
        var timer = null;
        function tick() {
            if (!active) return;
            fetch('file-maintenance.php?progress=' + encodeURIComponent(progressToken), { credentials: 'same-origin', cache: 'no-store' })
                .then(parseJson)
                .then(function (state) { if (active) showState(overlay, state); })
                .catch(function () {})
                .finally(function () { if (active) timer = window.setTimeout(tick, 500); });
        }
        tick();
        return function () { active = false; if (timer !== null) window.clearTimeout(timer); };
    }

    function showFailures(overlay, result) {
        var failures = Array.isArray(result.failures) ? result.failures : [];
        if (failures.length === 0) return false;
        var details = document.createElement('div');
        details.className = 'full-sync-failures';
        details.textContent = failures.join('\n');
        overlay.querySelector('.full-sync-dialog').appendChild(details);
        var actions = document.createElement('div');
        actions.className = 'full-sync-result-actions';
        var back = document.createElement('a');
        back.className = 'button';
        back.href = result.return_url || window.location.href;
        back.textContent = 'Return to full sync';
        actions.appendChild(back);
        overlay.querySelector('.full-sync-dialog').appendChild(actions);
        return true;
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var gameSelect = document.getElementById('full-sync-game');
        var gameName = gameSelect ? gameSelect.options[gameSelect.selectedIndex].text : 'this game';
        if (!window.confirm('Run a full scanner sync for ' + gameName + '? Every verified stored package in this game will be re-imported.')) return;

        var overlay = createOverlay();
        var token = makeToken();
        var stopPolling = poll(token, overlay);
        var data = new FormData(form);
        data.set('progress_token', token);
        form.querySelectorAll('button').forEach(function (button) { button.disabled = true; });

        fetch(form.action, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body: data })
            .then(parseJson)
            .then(function (result) {
                stopPolling();
                if (!result.ok) throw new Error(result.error || 'Full sync failed.');
                showState(overlay, { percent: 100, done: result.synced || 0, total: result.total || 0, message: result.message || 'Full sync complete.' });
                if (showFailures(overlay, result)) return;
                overlay.querySelector('.full-sync-loading').classList.add('is-visible');
                window.setTimeout(function () { window.location.assign(result.return_url || window.location.href); }, 100);
            })
            .catch(function (error) {
                stopPolling();
                overlay.remove();
                form.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
                window.alert(error.message || 'Full sync failed.');
            });
    });
})();
</script>
JS;
    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Full Sync Error');
    echo CatalogUi::alert('danger', $e->getMessage(), 'The full sync page could not be loaded.');
    catalog_foot();
}
