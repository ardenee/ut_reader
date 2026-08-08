<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders Game Admin, progress polling and lifecycle action responses.
 * Why: Game persistence, aggregate reads, reset/delete orchestration and source reads now belong to a shared service.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UploadProgress.php';

use UnrealDb\Catalog\Infrastructure\Games\CatalogGameAdminService;

catalog_start_session();

function gm_profile_label(array $profile): string
{
    $exts = json_decode((string)($profile['allowed_extensions_json'] ?? '[]'), true);
    $extText = is_array($exts) && $exts !== [] ? ' / .' . implode(' .', $exts) : '';
    $range = ($profile['package_version_min'] !== null || $profile['package_version_max'] !== null)
        ? ' / version ' . ($profile['package_version_min'] ?? '?') . '-' . ($profile['package_version_max'] ?? '?')
        : '';
    return gp_profile_display_name($profile) . ' / ' . (string)$profile['engine_key'] . $extText . $range;
}

function gm_json_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** @return array{progress:?callable,ajax:bool} */
function gm_long_action_context(): array
{
    $ajax = (string)($_POST['ajax'] ?? '') === '1';
    $progressToken = upload_progress_token((string)($_POST['progress_token'] ?? ''));
    $progress = null;
    if ($progressToken !== '') {
        $progress = static function (array $state) use ($progressToken): void {
            upload_progress_write($progressToken, $state);
        };
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    return ['progress' => $progress, 'ajax' => $ajax];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $service = new CatalogGameAdminService($db, $config);

    if (isset($_GET['progress'])) {
        if (!catalog_support_is_admin()) {
            gm_json_reply(['ok' => false, 'error' => 'Administrator login is required.'], 403);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        gm_json_reply(upload_progress_read((string)$_GET['progress']));
    }

    if (!catalog_require_admin_page()) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('game_manager');
        $action = (string)($_POST['action'] ?? 'save_game');

        if ($action === 'reset_game_files' || $action === 'delete_game') {
            $context = gm_long_action_context();
            $gameId = (int)($_POST['game_id'] ?? 0);
            $response = $action === 'reset_game_files'
                ? $service->reset($gameId, (string)($_POST['confirm_reset'] ?? '') === 'yes', $context['progress'])
                : $service->delete($gameId, (string)($_POST['confirm_delete'] ?? '') === 'yes', $context['progress']);

            if ($context['ajax']) {
                gm_json_reply([
                    'ok' => true,
                    'message' => $response['message'],
                    'return_url' => $response['return_url'],
                    'result' => $response['result'],
                ]);
            }

            catalog_start_session();
            $_SESSION['game_manager_flash'] = $response['message'];
            session_write_close();
            header('Location: ' . $response['return_url']);
            exit;
        }

        if ($action === 'save_game') {
            $gameId = $service->save($_POST);
            $_SESSION['game_manager_flash'] = 'Game saved and profile assigned.';
            header('Location: game-manager.php?game_id=' . $gameId);
            exit;
        }
    }

    $flash = $_SESSION['game_manager_flash'] ?? null;
    unset($_SESSION['game_manager_flash']);
    $csrfToken = catalog_csrf('game_manager');

    catalog_head('Game Admin');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    catalog_flash($flash);

    echo <<<'CSS'
<style>
.game-actions { display:flex; gap:6px; align-items:flex-start; flex-wrap:wrap; }
.game-actions form { display:inline; margin:0; }
.game-reset-button { border-color:rgba(255,166,74,.85); color:#ffedd5; background:linear-gradient(180deg,rgba(124,69,16,.9),rgba(69,26,3,.9)); }
.game-delete-button { border-color:rgba(255,107,122,.9); color:#fecdd3; background:linear-gradient(180deg,rgba(127,29,29,.95),rgba(69,10,10,.95)); }
</style>
CSS;

    $profileChoices = $service->profileChoices();
    $games = $service->games();
    $editId = (int)($_GET['game_id'] ?? 0);
    $edit = null;
    foreach ($games as $row) {
        if ((int)$row['id'] === $editId) {
            $edit = $row;
            break;
        }
    }

    catalog_page_header(
        'Game Admin',
        'Add, edit, reset, or permanently delete games. Reset and delete remove managed files, then optimise the affected catalog tables.',
        [
            'Game Profiles' => 'game-profiles.php',
            'Upload Files' => 'profiled-upload.php' . ($editId ? '?game_id=' . $editId : ''),
            'Add Game Source' => 'sources.php' . ($editId ? '?game_id=' . $editId : ''),
            'Scan Sources' => 'source-scan.php',
            'Library' => 'library.php',
        ]
    );

    echo '<div class="card"><h2>Games</h2>';
    if ($games === []) {
        echo '<p class="muted">No games configured.</p>';
    } else {
        echo '<table><tr><th>Game</th><th>Assigned profile</th><th>Engine</th><th>Extensions</th><th>Version range</th><th>Files</th><th>Sources</th><th>Actions</th></tr>';
        foreach ($games as $game) {
            $exts = json_decode((string)($game['allowed_extensions_json'] ?? '[]'), true);
            $range = ($game['package_version_min'] !== null || $game['package_version_max'] !== null)
                ? (($game['package_version_min'] ?? '?') . ' - ' . ($game['package_version_max'] ?? '?'))
                : 'not fixed';
            $engine = $game['profile_engine'] ?: 'missing profile';
            $engineClass = $game['profile_engine'] ? 'good-pill' : 'bad-pill';
            $gameId = (int)$game['id'];
            $fileCount = (int)$game['file_count'];
            $sourceCount = (int)$game['source_count'];

            echo '<tr><td><strong>' . catalog_h($game['name']) . '</strong><br><span class="muted small">' . catalog_h($game['slug']) . '</span></td>'
                . '<td>' . catalog_h($game['profile_name'] ?? 'none') . '</td>'
                . '<td><span class="pill ' . $engineClass . '">' . catalog_h($engine) . '</span></td>'
                . '<td class="mono small">' . catalog_h(is_array($exts) ? implode(', ', $exts) : '') . '</td>'
                . '<td class="mono">' . catalog_h($range) . '</td>'
                . '<td>' . number_format($fileCount) . '</td>'
                . '<td>' . number_format($sourceCount) . '</td>'
                . '<td><div class="game-actions">'
                . '<a class="button" href="game-manager.php?game_id=' . $gameId . '">Edit</a> '
                . '<a class="button" href="sources.php?game_id=' . $gameId . '">Sources</a> '
                . '<a class="button" href="profiled-upload.php?game_id=' . $gameId . '">Upload</a> '
                . '<form method="post" class="game-reset-form" data-game-name="' . catalog_h($game['name']) . '" data-file-count="' . $fileCount . '">'
                . '<input type="hidden" name="csrf" value="' . catalog_h($csrfToken) . '">'
                . '<input type="hidden" name="action" value="reset_game_files">'
                . '<input type="hidden" name="game_id" value="' . $gameId . '">'
                . '<input type="hidden" name="confirm_reset" value="yes">'
                . '<button type="submit" class="button game-reset-button">Reset</button>'
                . '</form>'
                . '<form method="post" class="game-delete-form" data-game-name="' . catalog_h($game['name']) . '" data-file-count="' . $fileCount . '" data-source-count="' . $sourceCount . '">'
                . '<input type="hidden" name="csrf" value="' . catalog_h($csrfToken) . '">'
                . '<input type="hidden" name="action" value="delete_game">'
                . '<input type="hidden" name="game_id" value="' . $gameId . '">'
                . '<input type="hidden" name="confirm_delete" value="yes">'
                . '<button type="submit" class="button game-delete-button">Delete</button>'
                . '</form></div></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    $longJobPath = __DIR__ . '/assets/catalog-long-job.js';
    $longJobVersion = is_file($longJobPath) ? (string)filemtime($longJobPath) : '1';
    echo '<script src="assets/catalog-long-job.js?v=' . catalog_h($longJobVersion) . '"></script>';
    echo <<<'JS'
<script>
(function () {
    'use strict';

    function resetMessage(gameName, fileCount) {
        return 'Reset ' + gameName + '?\n\n'
            + 'This permanently deletes all catalogued package files, retained PAK archives, managed storage, and game-associated unverified rows. Related Names, Imports, Exports, locations, aliases, asset metadata, and dependencies are removed.\n\n'
            + 'Game setup, profile assignment, source definitions, and base-game protection remain. The affected catalog tables are optimised afterwards.\n\n'
            + 'Catalog records affected: ' + fileCount;
    }

    function deleteMessage(gameName, fileCount, sourceCount) {
        return 'Permanently delete ' + gameName + '?\n\n'
            + 'This deletes the game itself, all catalogued and unverified files, retained PAK archives, managed storage, source definitions, base-game protection rows, and all dependent Names, Imports, Exports, locations, aliases, asset metadata, and dependencies.\n\n'
            + 'The assigned reusable game profile is not deleted. Existing Game Backup exports are not deleted. The affected database tables are optimised afterwards.\n\n'
            + 'This cannot be undone.\n\n'
            + 'Catalog records: ' + fileCount + '\nSource definitions: ' + sourceCount;
    }

    function endpoint() {
        return window.location.pathname + window.location.search;
    }

    function disableLifecycleButtons(disabled) {
        document.querySelectorAll('.game-reset-form button, .game-delete-form button').forEach(function (button) {
            button.disabled = disabled;
        });
    }

    function begin(form, options) {
        if (!window.CatalogLongJob) {
            window.alert('The progress window could not be loaded. Refresh the page and try again.');
            return;
        }

        var overlay = window.CatalogLongJob.create({
            title: options.title,
            message: options.message,
            count: options.count
        });
        var token = window.CatalogLongJob.makeToken();
        var data = new FormData(form);
        data.set('ajax', '1');
        data.set('progress_token', token);
        disableLifecycleButtons(true);

        var stopPolling = window.CatalogLongJob.poll(endpoint(), token, function (state) {
            var done = Number(state.done || 0);
            var total = Number(state.total || 0);
            overlay.update({
                percent: Number(state.percent || 0),
                message: state.message || options.working,
                count: total > 0 ? done + ' of ' + total : 'Working…',
                status: options.status
            });
        }, 450);

        fetch(endpoint(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
            body: data
        }).then(window.CatalogLongJob.parseJson).then(function (result) {
            stopPolling();
            if (!result.ok) throw new Error(result.error || options.failed);
            overlay.complete(result.message || options.complete, options.completeStatus);
            overlay.addAction('Reload Game Admin', result.return_url || 'game-manager.php');
            if (options.uploadAfter) {
                overlay.addAction('Upload files', 'profiled-upload.php?game_id=' + encodeURIComponent(form.querySelector('[name="game_id"]').value));
            }
        }).catch(function (error) {
            stopPolling();
            overlay.fail(error.message || options.failed);
            overlay.addAction('Close', null, function () {
                overlay.destroy();
                disableLifecycleButtons(false);
            });
        });
    }

    document.querySelectorAll('.game-reset-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var gameName = form.getAttribute('data-game-name') || 'this game';
            var fileCount = form.getAttribute('data-file-count') || '0';
            if (!window.confirm(resetMessage(gameName, fileCount))) return;
            begin(form, {
                title: 'Resetting ' + gameName,
                message: 'Preparing game reset…',
                count: '0 of ' + fileCount + ' catalog records',
                working: 'Game reset in progress…',
                status: 'Reset and optimisation in progress',
                failed: 'Game reset failed.',
                complete: 'Game reset complete.',
                completeStatus: 'Reset complete',
                uploadAfter: true
            });
        });
    });

    document.querySelectorAll('.game-delete-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var gameName = form.getAttribute('data-game-name') || 'this game';
            var fileCount = form.getAttribute('data-file-count') || '0';
            var sourceCount = form.getAttribute('data-source-count') || '0';
            if (!window.confirm(deleteMessage(gameName, fileCount, sourceCount))) return;
            begin(form, {
                title: 'Deleting ' + gameName,
                message: 'Preparing permanent game deletion…',
                count: '0 of ' + fileCount + ' catalog records',
                working: 'Game deletion in progress…',
                status: 'Deletion and optimisation in progress',
                failed: 'Game deletion failed.',
                complete: 'Game deleted.',
                completeStatus: 'Game deleted',
                uploadAfter: false
            });
        });
    });
})();
</script>
JS;

    echo '<div class="card"><h2>' . ($edit ? 'Edit ' . catalog_h($edit['name']) : 'Add new game') . '</h2>'
        . '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h($csrfToken) . '">'
        . '<input type="hidden" name="action" value="save_game">'
        . '<input type="hidden" name="id" value="' . (int)($edit['id'] ?? 0) . '"><table>';
    echo '<tr><th>Game name</th><td><input name="name" required value="' . catalog_h($edit['name'] ?? '') . '" style="min-width:420px"></td></tr>';
    echo '<tr><th>Slug</th><td><input name="slug" value="' . catalog_h($edit['slug'] ?? '') . '" style="min-width:260px"> <span class="muted">Used in URLs and storage paths.</span></td></tr>';
    echo '<tr><th>Description</th><td><textarea name="description" rows="3" style="width:100%">' . catalog_h($edit['description'] ?? '') . '</textarea></td></tr>';
    echo '<tr><th>Game profile</th><td>';
    if ($profileChoices === []) {
        echo '<p class="muted">No active game profiles exist yet. Create one in <a href="game-profiles.php">Game Profiles</a> first.</p>';
    } else {
        echo '<select name="profile_id" required style="min-width:620px">';
        echo '<option value="">Select a game profile...</option>';
        foreach ($profileChoices as $profile) {
            $selected = $edit && (int)($edit['profile_id'] ?? 0) === (int)$profile['id'] ? ' selected' : '';
            echo '<option value="' . (int)$profile['id'] . '"' . $selected . '>' . catalog_h(gm_profile_label($profile)) . '</option>';
        }
        echo '</select><p class="muted small">Profiles are managed in Game Profiles. This only assigns the selected profile to this game.</p>';
    }
    echo '</td></tr>';
    echo '</table><p><button class="button"' . ($profileChoices === [] ? ' disabled' : '') . '>Save game</button> '
        . '<a class="button" href="game-manager.php">Add blank game</a> '
        . '<a class="button" href="game-profiles.php">Manage profiles</a></p></form></div>';

    if ($edit) {
        $sources = $service->sourcesForGame((int)$edit['id']);
        echo '<div class="card"><div class="section-title"><h2>Sources for this game</h2><a class="button" href="sources.php?game_id=' . (int)$edit['id'] . '">Add source</a></div>';
        if ($sources === []) {
            echo '<p class="muted">No folders, redirect servers, or HTTP mirrors are tied to this game yet.</p>';
        } else {
            echo '<table><tr><th>Name</th><th>Type</th><th>Path / URL</th><th>Notes</th></tr>';
            foreach ($sources as $source) {
                echo '<tr><td>' . catalog_h($source['name']) . '</td><td class="mono">' . catalog_h($source['source_type']) . '</td><td class="mono path">' . catalog_h($source['base_path']) . '</td><td>' . catalog_h($source['notes']) . '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }

    catalog_foot();
} catch (Throwable $error) {
    if ((string)($_POST['ajax'] ?? '') === '1') {
        gm_json_reply(['ok' => false, 'error' => $error->getMessage()], 500);
    }
    if (!headers_sent()) {
        catalog_head('Game admin error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
