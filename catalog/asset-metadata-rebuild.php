<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogAssetMetadata.php';

function asset_metadata_int_post(string $key, int $default = 0): int
{
    return max(0, (int)($_POST[$key] ?? $default));
}

function asset_metadata_json(array $payload): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function asset_metadata_totals_zero(): array
{
    return ['assets' => 0, 'string_asset_refs' => 0, 'preload_deps' => 0, 'soft_refs' => 0, 'redirectors' => 0];
}

function asset_metadata_totals_text(array $totals): string
{
    return 'Asset rows=' . (int)($totals['assets'] ?? 0)
        . ', string asset references=' . (int)($totals['string_asset_refs'] ?? 0)
        . ', preload dependencies=' . (int)($totals['preload_deps'] ?? 0)
        . ', soft-reference candidates=' . (int)($totals['soft_refs'] ?? 0)
        . ', unparsed redirectors=' . (int)($totals['redirectors'] ?? 0) . '.';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Asset Metadata Rebuild')) {
        exit;
    }

    catalog_dependency_schema_ensure($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['ajax'] ?? '') === '1') {
        try {
            catalog_check_csrf('asset_metadata_rebuild');
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'list_game') {
                $gameId = asset_metadata_int_post('game_id');
                $offset = asset_metadata_int_post('offset');
                if ($gameId <= 0) {
                    throw new RuntimeException('Choose a game.');
                }

                $game = catalog_one($db, 'SELECT id, name FROM ue_games WHERE id=?', [$gameId]);
                if (!$game) {
                    throw new RuntimeException('Game not found.');
                }

                $countRow = catalog_one($db, 'SELECT COUNT(*) c FROM ue_files WHERE game_id=? AND scan_status="verified"', [$gameId]);
                $totalFiles = (int)($countRow['c'] ?? 0);
                $files = catalog_all(
                    $db,
                    'SELECT id, original_name, package_name FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY package_name, id LIMIT 18446744073709551615 OFFSET ' . $offset,
                    [$gameId]
                );

                asset_metadata_json([
                    'ok' => true,
                    'game_id' => (int)$game['id'],
                    'game_name' => (string)$game['name'],
                    'offset' => $offset,
                    'total_files' => $totalFiles,
                    'remaining_files' => count($files),
                    'files' => array_map(static fn(array $file): array => [
                        'id' => (int)$file['id'],
                        'original_name' => (string)$file['original_name'],
                        'package_name' => (string)$file['package_name'],
                    ], $files),
                ]);
                exit;
            }

            if ($action === 'rebuild_file') {
                $fileId = asset_metadata_int_post('file_id');
                if ($fileId <= 0) {
                    throw new RuntimeException('Missing file ID.');
                }

                $file = catalog_one($db, 'SELECT id, original_name, package_name FROM ue_files WHERE id=?', [$fileId]);
                if (!$file) {
                    throw new RuntimeException('File not found: ' . $fileId);
                }

                $stats = catalog_asset_metadata_rebuild_file($db, $config, $fileId);
                asset_metadata_json([
                    'ok' => true,
                    'file_id' => $fileId,
                    'original_name' => (string)$file['original_name'],
                    'package_name' => (string)$file['package_name'],
                    'stats' => $stats,
                    'message' => asset_metadata_totals_text($stats),
                ]);
                exit;
            }

            throw new RuntimeException('Unknown metadata action.');
        } catch (Throwable $e) {
            asset_metadata_json(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    $games = catalog_all($db, 'SELECT id, name FROM ue_games ORDER BY name');
    catalog_head('Asset Metadata Rebuild');
    echo <<<'CSS'
<style>
.asset-meta-choice { display: flex; align-items: end; gap: 12px; flex-wrap: wrap; }
.asset-meta-choice label { display: grid; gap: 6px; min-width: min(420px, 100%); }
.asset-meta-choice input[type="number"] { width: 180px; }
.asset-meta-overlay { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; padding: 20px; background: rgba(3,8,18,.72); backdrop-filter: blur(3px); }
.asset-meta-dialog { width: min(720px,100%); padding: 24px; border: 1px solid var(--line2); border-radius: 14px; background: #111b2d; box-shadow: 0 24px 70px rgba(0,0,0,.5); }
.asset-meta-dialog h2 { margin: 0 0 8px; }
.asset-meta-message { margin: 0 0 16px; }
.asset-meta-progress { height: 14px; overflow: hidden; border: 1px solid var(--line2); border-radius: 999px; background: rgba(255,255,255,.05); }
.asset-meta-progress > span { display: block; width: 0; height: 100%; border-radius: inherit; background: linear-gradient(90deg,#76a9ff,#9dc2ff); transition: width .18s linear; }
.asset-meta-count { margin-top: 9px; color: var(--muted); font-size: 13px; }
.asset-meta-totals { margin-top: 12px; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; color: var(--muted); white-space: pre-wrap; }
.asset-meta-failures { max-height: 220px; overflow: auto; margin: 14px 0 0; padding: 10px 14px; border: 1px solid rgba(255,107,122,.55); border-radius: 8px; color: #ffd9de; background: rgba(255,107,122,.1); white-space: pre-wrap; }
.asset-meta-actions { display: flex; gap: 8px; margin-top: 16px; }
</style>
CSS;

    catalog_page_header(
        'Asset Metadata Rebuild',
        'Rebuild explicit UE asset metadata for a single file or a whole game. Full-game runs are automated in the browser with a progress popup, using one package request at a time to avoid long PHP request timeouts.',
        ['Dashboard' => 'dashboard.php']
    );

    echo '<div class="card"><h2>Rebuild metadata</h2><form id="asset-meta-form" class="asset-meta-choice" method="post" action="asset-metadata-rebuild.php">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('asset_metadata_rebuild')) . '">';
    echo '<label>Single file ID<br><input type="number" name="file_id" min="1" placeholder="optional"></label>';
    echo '<label>Or full game<br><select name="game_id"><option value="0">Select game...</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '">' . catalog_h($game['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Start offset<br><input type="number" name="offset" min="0" value="0"></label>';
    echo '<button type="submit">Start metadata rebuild</button>';
    echo '</form><p class="muted">For a full game, leave Single file ID empty. Use offset 950 only if you intentionally want to skip the old first-950 batch you already ran.</p></div>';

    echo '<div class="card"><h2>Notes</h2><p class="muted">ObjectRedirector rows are recorded as unparsed metadata until serialized export-property decoding can prove the target. They are not treated as package aliases and never use folder/object-name similarity.</p></div>';

    echo <<<'JS'
<script>
(function () {
    'use strict';

    var form = document.getElementById('asset-meta-form');
    if (!form) return;

    function makeData(action) {
        var data = new FormData(form);
        data.set('ajax', '1');
        data.set('action', action);
        return data;
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
                throw new Error('Server returned non-JSON metadata response (HTTP ' + response.status + ')' + (detail ? ': ' + detail : '.'));
            }
        });
    }

    function post(action, extra) {
        var data = makeData(action);
        Object.keys(extra || {}).forEach(function (key) { data.set(key, extra[key]); });
        return fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            body: data
        }).then(parseJson).then(function (json) {
            if (!json.ok) throw new Error(json.error || 'Metadata rebuild failed.');
            return json;
        });
    }

    function totalsZero() {
        return { assets: 0, string_asset_refs: 0, preload_deps: 0, soft_refs: 0, redirectors: 0 };
    }

    function addTotals(total, stats) {
        stats = stats || {};
        Object.keys(total).forEach(function (key) { total[key] += Number(stats[key] || 0); });
    }

    function totalsText(total) {
        return 'Asset rows=' + total.assets + '\n'
            + 'String asset references=' + total.string_asset_refs + '\n'
            + 'Preload dependencies=' + total.preload_deps + '\n'
            + 'Soft-reference candidates=' + total.soft_refs + '\n'
            + 'Unparsed redirectors=' + total.redirectors;
    }

    function createOverlay(title) {
        var overlay = document.createElement('div');
        overlay.className = 'asset-meta-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-live', 'assertive');
        overlay.innerHTML = '<div class="asset-meta-dialog"><h2></h2><p class="asset-meta-message">Preparing…</p><div class="asset-meta-progress"><span></span></div><div class="asset-meta-count">Waiting for server…</div><div class="asset-meta-totals"></div></div>';
        overlay.querySelector('h2').textContent = title;
        document.body.appendChild(overlay);
        return overlay;
    }

    function updateOverlay(overlay, done, total, message, totals) {
        var percent = total > 0 ? Math.round((done / total) * 100) : 100;
        overlay.querySelector('.asset-meta-progress > span').style.width = Math.max(0, Math.min(100, percent)) + '%';
        overlay.querySelector('.asset-meta-message').textContent = message;
        overlay.querySelector('.asset-meta-count').textContent = done + ' of ' + total + ' file(s) processed (' + percent + '%)';
        overlay.querySelector('.asset-meta-totals').textContent = totalsText(totals);
    }

    function addCloseAction(overlay) {
        var actions = document.createElement('div');
        actions.className = 'asset-meta-actions';
        var close = document.createElement('button');
        close.type = 'button';
        close.textContent = 'Close';
        close.addEventListener('click', function () { overlay.remove(); });
        actions.appendChild(close);
        overlay.querySelector('.asset-meta-dialog').appendChild(actions);
    }

    async function runSingle(fileId) {
        var overlay = createOverlay('Asset metadata rebuild');
        var totals = totalsZero();
        updateOverlay(overlay, 0, 1, 'Rebuilding file ID ' + fileId + '…', totals);
        var result = await post('rebuild_file', { file_id: String(fileId) });
        addTotals(totals, result.stats);
        updateOverlay(overlay, 1, 1, 'Complete: ' + (result.package_name || result.original_name || ('file ID ' + fileId)), totals);
        addCloseAction(overlay);
    }

    async function runGame(gameId, offset) {
        var overlay = createOverlay('Full game asset metadata rebuild');
        var totals = totalsZero();
        updateOverlay(overlay, 0, 1, 'Loading game file list…', totals);
        var list = await post('list_game', { game_id: String(gameId), offset: String(offset) });
        var files = Array.isArray(list.files) ? list.files : [];
        if (files.length === 0) {
            updateOverlay(overlay, 0, 0, 'No verified files found for this game/offset.', totals);
            addCloseAction(overlay);
            return;
        }

        var failures = [];
        for (var index = 0; index < files.length; index++) {
            var file = files[index];
            var label = file.package_name || file.original_name || ('file ID ' + file.id);
            updateOverlay(overlay, index, files.length, 'Rebuilding ' + (Number(list.offset || 0) + index + 1) + '/' + list.total_files + ': ' + label, totals);
            try {
                var result = await post('rebuild_file', { file_id: String(file.id) });
                addTotals(totals, result.stats);
            } catch (error) {
                failures.push(label + ': ' + (error.message || 'Unknown error'));
            }
            updateOverlay(overlay, index + 1, files.length, 'Processed ' + (Number(list.offset || 0) + index + 1) + '/' + list.total_files + ': ' + label, totals);
        }

        overlay.querySelector('.asset-meta-progress > span').style.width = '100%';
        overlay.querySelector('.asset-meta-message').textContent = failures.length
            ? 'Finished with ' + failures.length + ' issue(s). Other files were processed.'
            : 'Full game metadata rebuild complete.';
        overlay.querySelector('.asset-meta-count').textContent = files.length + ' file(s) processed from offset ' + Number(list.offset || 0) + '.';
        overlay.querySelector('.asset-meta-totals').textContent = totalsText(totals);
        if (failures.length > 0) {
            var details = document.createElement('div');
            details.className = 'asset-meta-failures';
            details.textContent = failures.join('\n');
            overlay.querySelector('.asset-meta-dialog').appendChild(details);
        }
        addCloseAction(overlay);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var fileId = Number(form.querySelector('[name="file_id"]').value || 0);
        var gameId = Number(form.querySelector('[name="game_id"]').value || 0);
        var offset = Number(form.querySelector('[name="offset"]').value || 0);
        form.querySelectorAll('button').forEach(function (button) { button.disabled = true; });

        var runner = fileId > 0 ? runSingle(fileId) : runGame(gameId, offset);
        runner.catch(function (error) {
            window.alert(error.message || 'Metadata rebuild failed.');
        }).finally(function () {
            form.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
        });
    });
})();
</script>
JS;

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Asset Metadata Rebuild error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
