<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogScanner.php';

function dependency_refresh_int_post(string $key, int $default = 0): int
{
    return max(0, (int)($_POST[$key] ?? $default));
}

function dependency_refresh_json(array $payload): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dependency_refresh_stats(PDO $db, int $fileId): array
{
    $rows = catalog_all(
        $db,
        'SELECT status, resolution_source, resolution_confidence, COUNT(*) c'
        . ' FROM ue_dependencies WHERE file_id=?'
        . ' GROUP BY status, resolution_source, resolution_confidence',
        [$fileId]
    );

    $stats = [
        'total' => 0,
        'resolved' => 0,
        'missing' => 0,
        'package_only' => 0,
        'common' => 0,
        'sources' => [],
        'confidences' => [],
    ];

    foreach ($rows as $row) {
        $count = (int)($row['c'] ?? 0);
        $status = (string)($row['status'] ?? '');
        $source = (string)($row['resolution_source'] ?? 'unknown');
        $confidence = (string)($row['resolution_confidence'] ?? 'unknown');
        $stats['total'] += $count;
        if (isset($stats[$status])) {
            $stats[$status] += $count;
        }
        $stats['sources'][$source] = ($stats['sources'][$source] ?? 0) + $count;
        $stats['confidences'][$confidence] = ($stats['confidences'][$confidence] ?? 0) + $count;
    }

    ksort($stats['sources']);
    ksort($stats['confidences']);
    return $stats;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Dependency Refresh')) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['ajax'] ?? '') === '1') {
        try {
            catalog_check_csrf('dependency_refresh');
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'list_game') {
                $gameId = dependency_refresh_int_post('game_id');
                $offset = dependency_refresh_int_post('offset');
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

                dependency_refresh_json([
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

            if ($action === 'refresh_file') {
                $fileId = dependency_refresh_int_post('file_id');
                if ($fileId <= 0) {
                    throw new RuntimeException('Missing file ID.');
                }

                $file = catalog_one($db, 'SELECT id, original_name, package_name FROM ue_files WHERE id=?', [$fileId]);
                if (!$file) {
                    throw new RuntimeException('File not found: ' . $fileId);
                }

                scanner_rebuild_dependencies($db, $config, $fileId, null, 0, 100, 'Dependency refresh');
                dependency_refresh_json([
                    'ok' => true,
                    'file_id' => $fileId,
                    'original_name' => (string)$file['original_name'],
                    'package_name' => (string)$file['package_name'],
                    'stats' => dependency_refresh_stats($db, $fileId),
                ]);
                exit;
            }

            throw new RuntimeException('Unknown dependency refresh action.');
        } catch (Throwable $e) {
            dependency_refresh_json(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    $games = catalog_all($db, 'SELECT id, name FROM ue_games ORDER BY name');
    catalog_head('Dependency Refresh');
    echo <<<'CSS'
<style>
.dependency-refresh-choice { display: flex; align-items: end; gap: 12px; flex-wrap: wrap; }
.dependency-refresh-choice label { display: grid; gap: 6px; min-width: min(420px, 100%); }
.dependency-refresh-choice input[type="number"] { width: 180px; }
.dependency-refresh-overlay { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; padding: 20px; background: rgba(3,8,18,.72); backdrop-filter: blur(3px); }
.dependency-refresh-dialog { width: min(760px,100%); padding: 24px; border: 1px solid var(--line2); border-radius: 14px; background: #111b2d; box-shadow: 0 24px 70px rgba(0,0,0,.5); }
.dependency-refresh-dialog h2 { margin: 0 0 8px; }
.dependency-refresh-message { margin: 0 0 16px; }
.dependency-refresh-progress { height: 14px; overflow: hidden; border: 1px solid var(--line2); border-radius: 999px; background: rgba(255,255,255,.05); }
.dependency-refresh-progress > span { display: block; width: 0; height: 100%; border-radius: inherit; background: linear-gradient(90deg,#76a9ff,#9dc2ff); transition: width .18s linear; }
.dependency-refresh-count { margin-top: 9px; color: var(--muted); font-size: 13px; }
.dependency-refresh-totals { margin-top: 12px; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; color: var(--muted); white-space: pre-wrap; }
.dependency-refresh-failures { max-height: 220px; overflow: auto; margin: 14px 0 0; padding: 10px 14px; border: 1px solid rgba(255,107,122,.55); border-radius: 8px; color: #ffd9de; background: rgba(255,107,122,.1); white-space: pre-wrap; }
.dependency-refresh-actions { display: flex; gap: 8px; margin-top: 16px; }
</style>
CSS;

    catalog_page_header(
        'Dependency Refresh',
        'Rebuild dependency resolution rows for a single file or a whole game without reimporting packages. Use this after metadata changes so resolution source/confidence is recalculated.',
        ['Dashboard' => 'dashboard.php', 'Asset Metadata Rebuild' => 'asset-metadata-rebuild.php']
    );

    echo '<div class="card"><h2>Refresh dependencies</h2><form id="dependency-refresh-form" class="dependency-refresh-choice" method="post" action="dependency-refresh.php">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('dependency_refresh')) . '">';
    echo '<label>Single file ID<br><input type="number" name="file_id" min="1" placeholder="optional"></label>';
    echo '<label>Or full game<br><select name="game_id"><option value="0">Select game...</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '">' . catalog_h($game['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Start offset<br><input type="number" name="offset" min="0" value="0"></label>';
    echo '<button type="submit">Start dependency refresh</button>';
    echo '</form><p class="muted">For a full game, leave Single file ID empty. This does not re-scan or reimport package files; it only deletes/rebuilds ue_dependencies rows per file.</p></div>';

    echo <<<'JS'
<script>
(function () {
    'use strict';

    var form = document.getElementById('dependency-refresh-form');
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
                throw new Error('Server returned non-JSON dependency refresh response (HTTP ' + response.status + ')' + (detail ? ': ' + detail : '.'));
            }
        });
    }

    function createOverlay(title) {
        var overlay = document.createElement('div');
        overlay.className = 'dependency-refresh-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-live', 'assertive');
        overlay.innerHTML = '<div class="dependency-refresh-dialog"><h2>' + title + '</h2><p class="dependency-refresh-message">Preparing…</p><div class="dependency-refresh-progress"><span></span></div><div class="dependency-refresh-count">Waiting for server…</div><div class="dependency-refresh-totals"></div></div>';
        document.body.appendChild(overlay);
        return overlay;
    }

    function setOverlay(overlay, current, total, fileName, totals) {
        var percent = total > 0 ? Math.round((current / total) * 100) : 100;
        overlay.querySelector('.dependency-refresh-progress > span').style.width = Math.max(0, Math.min(100, percent)) + '%';
        overlay.querySelector('.dependency-refresh-message').textContent = 'Refreshing ' + current + '/' + total + ': ' + fileName;
        overlay.querySelector('.dependency-refresh-count').textContent = current + ' of ' + total + ' file(s) processed (' + percent + '%).';
        overlay.querySelector('.dependency-refresh-totals').textContent = 'Dependencies=' + totals.total
            + '\nResolved=' + totals.resolved
            + ' | Package only=' + totals.package_only
            + ' | Common=' + totals.common
            + ' | Missing=' + totals.missing;
    }

    function addStats(totals, stats) {
        totals.total += Number(stats.total || 0);
        totals.resolved += Number(stats.resolved || 0);
        totals.missing += Number(stats.missing || 0);
        totals.package_only += Number(stats.package_only || 0);
        totals.common += Number(stats.common || 0);
    }

    async function postAjax(data) {
        var response = await fetch(form.action, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body: data });
        var json = await parseJson(response);
        if (!json.ok) throw new Error(json.error || 'Dependency refresh failed.');
        return json;
    }

    async function refreshFile(file) {
        var data = makeData('refresh_file');
        data.set('file_id', String(file.id));
        return postAjax(data);
    }

    function showFailures(overlay, failures) {
        if (!failures.length) return;
        var details = document.createElement('div');
        details.className = 'dependency-refresh-failures';
        details.textContent = failures.join('\n');
        overlay.querySelector('.dependency-refresh-dialog').appendChild(details);
    }

    function addCloseButton(overlay) {
        var actions = document.createElement('div');
        actions.className = 'dependency-refresh-actions';
        var button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Close';
        button.addEventListener('click', function () { overlay.remove(); });
        actions.appendChild(button);
        overlay.querySelector('.dependency-refresh-dialog').appendChild(actions);
    }

    async function runFullGame(overlay, files) {
        var failures = [];
        var totals = { total: 0, resolved: 0, missing: 0, package_only: 0, common: 0 };
        for (var index = 0; index < files.length; index++) {
            var file = files[index];
            try {
                var result = await refreshFile(file);
                addStats(totals, result.stats || {});
                setOverlay(overlay, index + 1, files.length, result.original_name || file.original_name || file.package_name || ('file #' + file.id), totals);
            } catch (error) {
                failures.push((file.original_name || file.package_name || ('file #' + file.id)) + ': ' + (error.message || 'Unknown error'));
                setOverlay(overlay, index + 1, files.length, file.original_name || file.package_name || ('file #' + file.id), totals);
            }
        }
        overlay.querySelector('.dependency-refresh-progress > span').style.width = '100%';
        overlay.querySelector('.dependency-refresh-message').textContent = failures.length ? 'Dependency refresh finished with ' + failures.length + ' issue(s).' : 'Dependency refresh complete.';
        showFailures(overlay, failures);
        addCloseButton(overlay);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var fileId = Number(form.querySelector('[name="file_id"]').value || 0);
        var gameId = Number(form.querySelector('[name="game_id"]').value || 0);
        var overlay = createOverlay(fileId > 0 ? 'Single file dependency refresh' : 'Full game dependency refresh');
        form.querySelectorAll('button').forEach(function (button) { button.disabled = true; });

        (async function () {
            if (fileId > 0) {
                var result = await refreshFile({ id: fileId });
                var totals = { total: 0, resolved: 0, missing: 0, package_only: 0, common: 0 };
                addStats(totals, result.stats || {});
                setOverlay(overlay, 1, 1, result.original_name || ('file #' + fileId), totals);
                overlay.querySelector('.dependency-refresh-message').textContent = 'Dependency refresh complete.';
                addCloseButton(overlay);
                return;
            }

            if (gameId <= 0) throw new Error('Choose a game or enter a file ID.');
            var list = await postAjax(makeData('list_game'));
            if (!Array.isArray(list.files) || !list.files.length) throw new Error('No verified files found for this game/offset.');
            overlay.querySelector('h2').textContent = 'Dependency refresh: ' + (list.game_name || 'selected game');
            await runFullGame(overlay, list.files);
        })().catch(function (error) {
            overlay.remove();
            window.alert(error.message || 'Dependency refresh failed.');
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
        catalog_head('Dependency Refresh error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
