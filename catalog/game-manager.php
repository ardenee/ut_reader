<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/GameProfiles.php';
require_once __DIR__ . '/lib/UploadProgress.php';
require_once __DIR__ . '/lib/CatalogPackageAliases.php';

function gm_slug(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'game';
}

function gm_profile_label(array $profile): string
{
    $exts = json_decode((string)($profile['allowed_extensions_json'] ?? '[]'), true);
    $extText = is_array($exts) && $exts ? ' / .' . implode(' .', $exts) : '';
    $range = ($profile['package_version_min'] !== null || $profile['package_version_max'] !== null)
        ? ' / version ' . ($profile['package_version_min'] ?? '?') . '-' . ($profile['package_version_max'] ?? '?')
        : '';
    return gp_profile_display_name($profile) . ' / ' . (string)$profile['engine_key'] . $extText . $range;
}

function gm_storage_root(array $config): string
{
    $storageRoot = realpath(rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
    if ($storageRoot === false || !is_dir($storageRoot)) {
        throw new RuntimeException('Catalog storage folder is unavailable.');
    }
    return $storageRoot;
}

function gm_emit(?callable $progress, string $stage, int $done, int $total, int $percent, string $message): void
{
    if ($progress === null) {
        return;
    }
    $progress([
        'stage' => $stage,
        'done' => max(0, $done),
        'total' => max(1, $total),
        'percent' => max(0, min(100, $percent)),
        'message' => $message,
    ]);
}

/** @return array{path:string,is_dir:bool} */
function gm_storage_entry(SplFileInfo $item): array
{
    return [
        'path' => $item->getPathname(),
        'is_dir' => $item->isDir() && !$item->isLink(),
    ];
}

function gm_remove_storage_tree(string $targetPath, string $storageRoot, ?callable $progress = null): int
{
    gm_emit($progress, 'storage_scan', 0, 1, 2, 'Inspecting managed game storage…');
    if (!file_exists($targetPath)) {
        gm_emit($progress, 'storage_delete', 0, 0, 82, 'No managed game storage folder exists.');
        return 0;
    }

    $storageRoot = rtrim(realpath($storageRoot) ?: $storageRoot, DIRECTORY_SEPARATOR);
    $rootPrefix = $storageRoot . DIRECTORY_SEPARATOR;
    $resolved = realpath($targetPath);
    if ($resolved === false || !str_starts_with($resolved, $rootPrefix) || $resolved === $storageRoot) {
        throw new RuntimeException('Refusing to reset storage outside the catalog storage folder.');
    }

    if (is_file($resolved) || is_link($resolved)) {
        gm_emit($progress, 'storage_delete', 0, 1, 12, 'Deleting the managed game storage item…');
        if (!@unlink($resolved)) {
            throw new RuntimeException('Could not remove stored file: ' . $resolved);
        }
        gm_emit($progress, 'storage_delete', 1, 1, 82, 'Managed game storage deleted.');
        return 1;
    }

    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $entries[] = gm_storage_entry($item);
        $count = count($entries);
        if (($count % 250) === 0) {
            gm_emit($progress, 'storage_scan', $count, $count + 1, 4, 'Counting managed storage entries… ' . $count . ' found');
        }
    }

    $total = max(1, count($entries) + 1);
    $removedFiles = 0;
    foreach ($entries as $index => $entry) {
        $path = $entry['path'];
        if ($entry['is_dir']) {
            if (!@rmdir($path)) {
                throw new RuntimeException('Could not remove storage folder: ' . $path);
            }
        } else {
            if (!@unlink($path)) {
                throw new RuntimeException('Could not remove stored file: ' . $path);
            }
            $removedFiles++;
        }

        $done = $index + 1;
        $percent = 5 + (int)floor(($done / $total) * 75);
        gm_emit(
            $progress,
            'storage_delete',
            $done,
            $total,
            $percent,
            'Deleting managed storage entry ' . $done . '/' . $total . ': ' . basename($path)
        );
    }

    if (!@rmdir($resolved)) {
        throw new RuntimeException('Could not remove game storage folder: ' . $resolved);
    }
    gm_emit($progress, 'storage_delete', $total, $total, 82, 'Managed game storage deleted.');
    return $removedFiles;
}

/**
 * Remove all catalogued packages for one game and delete that game's managed
 * catalog storage folder. Game setup, profile assignment, and source records stay.
 *
 * @return array{game_id:int,game_name:string,catalog_records:int,stored_files:int,total_size:int}
 */
function gm_reset_game_files(PDO $db, array $config, int $gameId, ?callable $progress = null): array
{
    gm_emit($progress, 'prepare', 0, 1, 1, 'Preparing game reset…');
    $game = catalog_one(
        $db,
        'SELECT g.id, g.name, g.slug, COUNT(f.id) file_count, COALESCE(SUM(f.file_size), 0) total_size'
        . ' FROM ue_games g'
        . ' LEFT JOIN ue_files f ON f.game_id=g.id'
        . ' WHERE g.id=?'
        . ' GROUP BY g.id, g.name, g.slug',
        [$gameId]
    );
    if (!$game) {
        throw new RuntimeException('Game not found.');
    }

    $fileIds = array_map(
        static fn(array $row): int => (int)$row['id'],
        catalog_all($db, 'SELECT id FROM ue_files WHERE game_id=? ORDER BY id', [$gameId])
    );

    $storageRoot = gm_storage_root($config);
    $gameStoragePath = $storageRoot . DIRECTORY_SEPARATOR . 'games' . DIRECTORY_SEPARATOR . gm_slug((string)$game['slug']);
    $storedFilesRemoved = gm_remove_storage_tree($gameStoragePath, $storageRoot, $progress);

    catalog_package_aliases_ensure($db);
    gm_emit($progress, 'database', 0, max(1, count($fileIds)), 84, 'Removing package aliases and catalog records…');
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM ue_file_package_aliases WHERE game_id=?')->execute([$gameId]);

        $catalogRecordsRemoved = 0;
        $chunks = array_chunk($fileIds, 100);
        $totalChunks = max(1, count($chunks));
        if ($chunks === []) {
            gm_emit($progress, 'database', 0, 0, 98, 'No catalog file records remain to delete.');
        } else {
            foreach ($chunks as $chunkIndex => $chunk) {
                $sql = 'DELETE FROM ue_files WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
                $stmt = $db->prepare($sql);
                $stmt->execute($chunk);
                $catalogRecordsRemoved += $stmt->rowCount();
                $doneChunks = $chunkIndex + 1;
                $percent = 84 + (int)floor(($doneChunks / $totalChunks) * 14);
                gm_emit(
                    $progress,
                    'database',
                    min(count($fileIds), $doneChunks * 100),
                    max(1, count($fileIds)),
                    $percent,
                    'Deleting catalog records batch ' . $doneChunks . '/' . $totalChunks
                );
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    gm_emit($progress, 'done', 1, 1, 100, 'Game reset complete.');
    return [
        'game_id' => (int)$game['id'],
        'game_name' => (string)$game['name'],
        'catalog_records' => $catalogRecordsRemoved,
        'stored_files' => $storedFilesRemoved,
        'total_size' => (int)$game['total_size'],
    ];
}

function gm_json_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

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

        if ($action === 'reset_game_files') {
            $gameId = (int)($_POST['game_id'] ?? 0);
            $confirmed = (string)($_POST['confirm_reset'] ?? '') === 'yes';
            $ajax = (string)($_POST['ajax'] ?? '') === '1';
            $progressToken = upload_progress_token((string)($_POST['progress_token'] ?? ''));
            if ($gameId <= 0 || !$confirmed) {
                throw new RuntimeException('Game reset confirmation is required.');
            }

            $progress = null;
            if ($progressToken !== '') {
                $progress = static function (array $state) use ($progressToken): void {
                    upload_progress_write($progressToken, $state);
                };
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $result = gm_reset_game_files($db, $config, $gameId, $progress);
            $message = 'Reset ' . $result['game_name'] . ': removed '
                . $result['catalog_records'] . ' catalog file record(s), deleted '
                . $result['stored_files'] . ' stored file(s), and cleared '
                . catalog_bytes($result['total_size']) . ' of recorded file data.';
            $returnUrl = 'game-manager.php?game_id=' . (int)$result['game_id'];

            if ($ajax) {
                gm_json_reply([
                    'ok' => true,
                    'message' => $message,
                    'return_url' => $returnUrl,
                    'result' => $result,
                ]);
            }

            catalog_start_session();
            $_SESSION['game_manager_flash'] = $message;
            session_write_close();
            header('Location: ' . $returnUrl);
            exit;
        }

        if ($action === 'save_game') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $slug = gm_slug((string)($_POST['slug'] ?? $name));
            $profileId = (int)($_POST['profile_id'] ?? 0);
            $description = trim((string)($_POST['description'] ?? ''));
            if ($name === '' || $profileId <= 0) {
                throw new RuntimeException('Game name and game profile are required.');
            }
            $profile = catalog_one($db, 'SELECT id FROM ue_game_profiles WHERE id=? AND is_active=1', [$profileId]);
            if (!$profile) {
                throw new RuntimeException('Selected active game profile not found.');
            }

            if ($id > 0) {
                $db->prepare('UPDATE ue_games SET name=?, slug=?, description=?, profile_id=? WHERE id=?')
                    ->execute([$name, $slug, $description ?: null, $profileId, $id]);
                $gameId = $id;
            } else {
                $stmt = $db->prepare('INSERT INTO ue_games(name, slug, description, profile_id) VALUES(?,?,?,?)');
                $stmt->execute([$name, $slug, $description ?: null, $profileId]);
                $gameId = (int)$db->lastInsertId();
            }

            $_SESSION['game_manager_flash'] = 'Game saved and profile assigned.';
            header('Location: game-manager.php?game_id=' . $gameId);
            exit;
        }
    }

    catalog_head('Game Admin');
    catalog_flash($_SESSION['game_manager_flash'] ?? null);
    unset($_SESSION['game_manager_flash']);

    echo <<<'CSS'
<style>
.game-actions { display: flex; gap: 6px; align-items: flex-start; flex-wrap: wrap; }
.game-actions form { display: inline; margin: 0; }
.game-reset-button { border-color: rgba(255,107,122,.85); color: #fecdd3; background: linear-gradient(180deg, rgba(127,29,29,.9), rgba(69,10,10,.9)); }
</style>
CSS;

    $profileChoices = catalog_all($db, 'SELECT * FROM ue_game_profiles WHERE is_active=1 ORDER BY COALESCE(profile_name, engine_key), engine_key, id');
    $games = catalog_all($db, 'SELECT g.*, p.id profile_id, p.profile_name, p.engine_key profile_engine, p.allowed_extensions_json, p.package_version_min, p.package_version_max, p.licensee_version_min, p.licensee_version_max, p.confidence_policy, p.notes profile_notes, COUNT(DISTINCT f.id) file_count, COUNT(DISTINCT s.id) source_count FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 LEFT JOIN ue_files f ON f.game_id=g.id LEFT JOIN ue_sources s ON s.game_id=g.id GROUP BY g.id, p.id ORDER BY g.name');
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
        'Add games, assign an existing scanner profile, and attach folders or download sources to that game. Create or edit profile rules in Game Profiles.',
        [
            'Game Profiles' => 'game-profiles.php',
            'Upload Files' => 'profiled-upload.php' . ($editId ? '?game_id=' . $editId : ''),
            'Add Game Source' => 'sources.php' . ($editId ? '?game_id=' . $editId : ''),
            'Scan Sources' => 'source-scan.php',
            'Library' => 'library.php',
        ]
    );

    echo '<div class="card"><h2>Games</h2>';
    if (!$games) {
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
            echo '<tr><td><strong>' . catalog_h($game['name']) . '</strong><br><span class="muted small">' . catalog_h($game['slug']) . '</span></td>'
                . '<td>' . catalog_h($game['profile_name'] ?? 'none') . '</td>'
                . '<td><span class="pill ' . $engineClass . '">' . catalog_h($engine) . '</span></td>'
                . '<td class="mono small">' . catalog_h(is_array($exts) ? implode(', ', $exts) : '') . '</td>'
                . '<td class="mono">' . catalog_h($range) . '</td>'
                . '<td>' . $fileCount . '</td>'
                . '<td>' . (int)$game['source_count'] . '</td>'
                . '<td><div class="game-actions">'
                . '<a class="button" href="game-manager.php?game_id=' . $gameId . '">Edit</a> '
                . '<a class="button" href="sources.php?game_id=' . $gameId . '">Sources</a> '
                . '<a class="button" href="profiled-upload.php?game_id=' . $gameId . '">Upload</a> '
                . '<form method="post" class="game-reset-form" data-game-name="' . catalog_h($game['name']) . '" data-file-count="' . $fileCount . '">'
                . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('game_manager')) . '">'
                . '<input type="hidden" name="action" value="reset_game_files">'
                . '<input type="hidden" name="game_id" value="' . $gameId . '">'
                . '<input type="hidden" name="confirm_reset" value="yes">'
                . '<button type="submit" class="button game-reset-button">Reset</button>'
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
            + 'This will permanently delete all catalogued package files for this game, remove their database records, and clear related Names, Imports, Exports, locations, aliases, and dependency rows.\n\n'
            + 'Game setup, profile assignment, and source definitions will stay.\n\n'
            + 'Catalog records affected: ' + fileCount;
    }

    document.querySelectorAll('.game-reset-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var gameName = form.getAttribute('data-game-name') || 'this game';
            var fileCount = form.getAttribute('data-file-count') || '0';
            if (!window.confirm(resetMessage(gameName, fileCount))) return;
            if (!window.CatalogLongJob) {
                window.alert('The progress window could not be loaded. Refresh the page and try again.');
                return;
            }

            var overlay = window.CatalogLongJob.create({
                title: 'Resetting ' + gameName,
                message: 'Preparing game reset…',
                count: '0 of ' + fileCount + ' catalog records'
            });
            var token = window.CatalogLongJob.makeToken();
            var data = new FormData(form);
            data.set('ajax', '1');
            data.set('progress_token', token);

            document.querySelectorAll('.game-reset-form button').forEach(function (button) {
                button.disabled = true;
            });

            var stopPolling = window.CatalogLongJob.poll('game-manager.php', token, function (state) {
                var done = Number(state.done || 0);
                var total = Number(state.total || 0);
                var count = total > 0 ? done + ' of ' + total : 'Working…';
                overlay.update({
                    percent: Number(state.percent || 0),
                    message: state.message || 'Reset in progress…',
                    count: count,
                    status: 'Reset in progress'
                });
            }, 450);

            fetch(form.action || 'game-manager.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                body: data
            }).then(window.CatalogLongJob.parseJson).then(function (result) {
                stopPolling();
                if (!result.ok) {
                    throw new Error(result.error || 'Game reset failed.');
                }
                overlay.complete(result.message || 'Game reset complete.', 'Reset complete');
                overlay.addAction('Reload Game Admin', result.return_url || window.location.href);
                overlay.addAction('Upload files', 'profiled-upload.php?game_id=' + encodeURIComponent(form.querySelector('[name="game_id"]').value));
            }).catch(function (error) {
                stopPolling();
                overlay.fail(error.message || 'Game reset failed.');
                overlay.addAction('Close', null, function () {
                    overlay.destroy();
                    document.querySelectorAll('.game-reset-form button').forEach(function (button) {
                        button.disabled = false;
                    });
                });
            });
        });
    });
})();
</script>
JS;

    echo '<div class="card"><h2>' . ($edit ? 'Edit ' . catalog_h($edit['name']) : 'Add new game') . '</h2>'
        . '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('game_manager')) . '">'
        . '<input type="hidden" name="action" value="save_game"><input type="hidden" name="id" value="' . (int)($edit['id'] ?? 0) . '"><table>';
    echo '<tr><th>Game name</th><td><input name="name" required value="' . catalog_h($edit['name'] ?? '') . '" style="min-width:420px"></td></tr>';
    echo '<tr><th>Slug</th><td><input name="slug" value="' . catalog_h($edit['slug'] ?? '') . '" style="min-width:260px"> <span class="muted">Used in URLs and storage paths.</span></td></tr>';
    echo '<tr><th>Description</th><td><textarea name="description" rows="3" style="width:100%">' . catalog_h($edit['description'] ?? '') . '</textarea></td></tr>';
    echo '<tr><th>Game profile</th><td>';
    if (!$profileChoices) {
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
    echo '</table><p><button class="button"' . (!$profileChoices ? ' disabled' : '') . '>Save game</button> '
        . '<a class="button" href="game-manager.php">Add blank game</a> '
        . '<a class="button" href="game-profiles.php">Manage profiles</a></p></form></div>';

    if ($edit) {
        $sources = catalog_all($db, 'SELECT * FROM ue_sources WHERE game_id=? ORDER BY name', [(int)$edit['id']]);
        echo '<div class="card"><div class="section-title"><h2>Sources for this game</h2><a class="button" href="sources.php?game_id=' . (int)$edit['id'] . '">Add source</a></div>';
        if (!$sources) {
            echo '<p class="muted">No folders, redirect servers, or HTTP mirrors are tied to this game yet.</p>';
        } else {
            echo '<table><tr><th>Name</th><th>Type</th><th>Path / URL</th><th>Notes</th></tr>';
            foreach ($sources as $src) {
                echo '<tr><td>' . catalog_h($src['name']) . '</td><td class="mono">' . catalog_h($src['source_type']) . '</td><td class="mono path">' . catalog_h($src['base_path']) . '</td><td>' . catalog_h($src['notes']) . '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }

    catalog_foot();
} catch (Throwable $e) {
    if ((string)($_POST['ajax'] ?? '') === '1') {
        gm_json_reply(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    if (!headers_sent()) {
        catalog_head('Game admin error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
