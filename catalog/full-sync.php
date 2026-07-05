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
    $syncFiles = $selectedGame === null ? [] : catalog_all(
        $db,
        'SELECT id, original_name FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY package_name, original_name, id',
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
    echo CatalogUi::pageHeader('Full Sync', 'Refresh every stored verified package in one game through short, scanner-based requests.', ['Back to dashboard' => 'dashboard.php']);

    $synced = full_sync_int('synced');
    $total = full_sync_int('total');
    $failed = full_sync_int('failed');
    if ($total > 0) {
        $message = 'Last full sync: ' . $synced . '/' . $total . ' packages re-imported.';
        if ($failed > 0) {
            $message .= ' ' . $failed . ' package(s) reported an issue.';
            echo CatalogUi::alert('warning', $message, 'The page shows individual failures at the end of a run.');
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
        $count = count($syncFiles);
        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>' . catalog_h($selectedGame['name']) . '</h2><p>Scanner-based full refresh scope.</p></div></div><div class="ui-section__body">';
        echo '<div class="full-sync-scope">';
        echo '<div class="stat"><h2>' . $count . '</h2><p>Verified packages</p></div>';
        echo '<div class="stat"><h2>' . catalog_h(catalog_bytes((int)$selectedGame['total_size'])) . '</h2><p>Stored size</p></div>';
        echo '<div class="stat"><h2>2 passes</h2><p>Re-import, then dependencies</p></div>';
        echo '</div>';
        echo '<p class="full-sync-warning">The sync runs <strong>one package per request</strong>: first each package is re-imported using the normal Upload Files scanner, then each package receives a final dependency refresh. This avoids the shared-host request timeout that stopped the earlier single-request sync.</p>';
        if ($count === 0) {
            echo '<p class="muted">This game has no verified packages to sync.</p>';
        } else {
            echo '<form id="full-sync-form" class="full-sync-start" method="post" action="file-maintenance.php">';
            echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('catalog-maintenance')) . '">';
            echo '<input type="hidden" name="game_id" value="' . (int)$selectedGame['id'] . '">';
            echo CatalogUi::button('Start full sync for ' . $selectedGame['name'], ['type' => 'submit', 'variant' => 'danger']);
            echo '</form>';
            echo '<script id="full-sync-files" type="application/json">' . json_encode($syncFiles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
        }
        echo '</div></section>';
    }

    echo <<<'JS'
<script>
(function () {
    'use strict';

    var form = document.getElementById('full-sync-form');
    var fileList = document.getElementById('full-sync-files');
    if (!form || !fileList) return;

    var files;
    try {
        files = JSON.parse(fileList.textContent || '[]');
    } catch (error) {
        window.alert('The full sync file list could not be loaded. Refresh this page and try again.');
        return;
    }
    if (!Array.isArray(files) || files.length === 0) return;

    function makeToken() {
        var bytes = new Uint8Array(18);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes).map(function (value) { return value.toString(16).padStart(2, '0'); }).join('');
        }
        return Date.now().toString(36) + Math.random().toString(36).slice(2);
    }

    function shortServerText(text) {
        return text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 260);
    }

    function parseJson(response) {
        return response.text().then(function (text) {
            try {
                return JSON.parse(text);
            } catch (error) {
                var detail = shortServerText(text);
                throw new Error('Server returned a non-JSON maintenance response (HTTP ' + response.status + ')' + (detail ? ': ' + detail : '.'));
            }
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

    function updateOverlay(overlay, phase, completedBefore, phaseTotal, state, fileName) {
        var localPercent = Math.max(0, Math.min(100, Number(state.percent || 0)));
        var phaseStart = phase === 'reimport' ? 0 : 80;
        var phaseEnd = phase === 'reimport' ? 80 : 100;
        var overall = phaseStart + (((completedBefore + (localPercent / 100)) / Math.max(1, phaseTotal)) * (phaseEnd - phaseStart));
        var label = phase === 'reimport' ? 'Re-importing' : 'Refreshing dependencies';
        var message = state.message || 'Working…';
        overlay.querySelector('.full-sync-progress > span').style.width = Math.max(0, Math.min(100, overall)) + '%';
        overlay.querySelector('.full-sync-message').textContent = label + ' package ' + (completedBefore + 1) + '/' + phaseTotal + ' (' + fileName + '): ' + message;
        overlay.querySelector('.full-sync-count').textContent = phase === 'reimport'
            ? completedBefore + ' of ' + phaseTotal + ' packages re-imported (' + Math.round(overall) + '% overall)'
            : completedBefore + ' of ' + phaseTotal + ' packages dependency-refreshed (' + Math.round(overall) + '% overall)';
    }

    function completeOverlayStep(overlay, phase, completed, phaseTotal, fileName, message) {
        var phaseStart = phase === 'reimport' ? 0 : 80;
        var phaseEnd = phase === 'reimport' ? 80 : 100;
        var overall = phaseStart + ((completed / Math.max(1, phaseTotal)) * (phaseEnd - phaseStart));
        var label = phase === 'reimport' ? 'Re-imported' : 'Refreshed dependencies for';
        overlay.querySelector('.full-sync-progress > span').style.width = Math.max(0, Math.min(100, overall)) + '%';
        overlay.querySelector('.full-sync-message').textContent = label + ' package ' + completed + '/' + phaseTotal + ' (' + fileName + '): ' + message;
        overlay.querySelector('.full-sync-count').textContent = phase === 'reimport'
            ? completed + ' of ' + phaseTotal + ' packages re-imported (' + Math.round(overall) + '% overall)'
            : completed + ' of ' + phaseTotal + ' packages dependency-refreshed (' + Math.round(overall) + '% overall)';
    }

    function pollProgress(token, onState) {
        var active = true;
        var timer = null;
        function tick() {
            if (!active) return;
            fetch('file-maintenance.php?progress=' + encodeURIComponent(token), {
                credentials: 'same-origin',
                cache: 'no-store'
            }).then(parseJson).then(function (state) {
                if (active) onState(state);
            }).catch(function () {
                /* The active package POST is authoritative. Keep its last real stage visible. */
            }).finally(function () {
                if (active) timer = window.setTimeout(tick, 450);
            });
        }
        tick();
        return function () {
            active = false;
            if (timer !== null) window.clearTimeout(timer);
        };
    }

    function runPackageRequest(overlay, operation, file, phase, completedBefore, phaseTotal) {
        var token = makeToken();
        var data = new FormData(form);
        data.set('operation', operation);
        data.set('file_id', String(file.id));
        data.set('progress_token', token);
        var stopPolling = pollProgress(token, function (state) {
            updateOverlay(overlay, phase, completedBefore, phaseTotal, state, file.original_name || 'package');
        });

        return fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            body: data
        }).then(parseJson).then(function (result) {
            stopPolling();
            if (!result.ok) {
                throw new Error(result.error || 'Package maintenance failed.');
            }
            return result;
        }).catch(function (error) {
            stopPolling();
            throw error;
        });
    }

    function showFailures(overlay, failures, returnUrl) {
        if (failures.length === 0) return false;
        overlay.querySelector('.full-sync-message').textContent = 'Full sync finished with ' + failures.length + ' issue(s). The unaffected packages continued.';
        var details = document.createElement('div');
        details.className = 'full-sync-failures';
        details.textContent = failures.join('\n');
        overlay.querySelector('.full-sync-dialog').appendChild(details);
        var actions = document.createElement('div');
        actions.className = 'full-sync-result-actions';
        var back = document.createElement('a');
        back.className = 'button';
        back.href = returnUrl;
        back.textContent = 'Return to full sync';
        actions.appendChild(back);
        overlay.querySelector('.full-sync-dialog').appendChild(actions);
        return true;
    }

    async function runFullSync(overlay) {
        var failures = [];
        var refreshFiles = [];
        var reimported = 0;
        var total = files.length;

        for (var index = 0; index < total; index++) {
            var file = files[index];
            try {
                var result = await runPackageRequest(overlay, 'sync_reimport', file, 'reimport', index, total);
                reimported++;
                refreshFiles.push({ id: result.file_id, original_name: result.original_name || file.original_name });
                completeOverlayStep(overlay, 'reimport', index + 1, total, file.original_name, result.message || 'Scanner re-import complete.');
            } catch (error) {
                failures.push('Re-import failed — ' + file.original_name + ': ' + (error.message || 'Unknown error'));
                /* A failed re-import restores its original catalog record, so include it in the final dependency pass. */
                refreshFiles.push(file);
                completeOverlayStep(overlay, 'reimport', index + 1, total, file.original_name, 'Skipped after error; continuing with the next package.');
            }
        }

        for (var refreshIndex = 0; refreshIndex < refreshFiles.length; refreshIndex++) {
            var refreshFile = refreshFiles[refreshIndex];
            try {
                var refreshResult = await runPackageRequest(overlay, 'sync_refresh_dependencies', refreshFile, 'dependencies', refreshIndex, refreshFiles.length);
                completeOverlayStep(overlay, 'dependencies', refreshIndex + 1, refreshFiles.length, refreshFile.original_name, refreshResult.message || 'Dependency refresh complete.');
            } catch (error) {
                failures.push('Dependency refresh failed — ' + refreshFile.original_name + ': ' + (error.message || 'Unknown error'));
                completeOverlayStep(overlay, 'dependencies', refreshIndex + 1, refreshFiles.length, refreshFile.original_name, 'Skipped after error; continuing with the next package.');
            }
        }

        overlay.querySelector('.full-sync-progress > span').style.width = '100%';
        overlay.querySelector('.full-sync-count').textContent = reimported + ' of ' + total + ' packages re-imported; final dependency pass completed.';
        var returnUrl = 'full-sync.php?game_id=' + encodeURIComponent(form.querySelector('[name="game_id"]').value)
            + '&synced=' + encodeURIComponent(reimported)
            + '&total=' + encodeURIComponent(total)
            + '&failed=' + encodeURIComponent(failures.length);
        if (showFailures(overlay, failures, returnUrl)) return;

        overlay.querySelector('.full-sync-message').textContent = 'Full sync complete.';
        overlay.querySelector('.full-sync-loading').classList.add('is-visible');
        window.setTimeout(function () { window.location.assign(returnUrl); }, 120);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var gameSelect = document.getElementById('full-sync-game');
        var gameName = gameSelect ? gameSelect.options[gameSelect.selectedIndex].text : 'this game';
        if (!window.confirm('Run a full scanner sync for ' + gameName + '? It will re-import and refresh each verified package one at a time.')) return;

        var overlay = createOverlay();
        form.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
        runFullSync(overlay).catch(function (error) {
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
