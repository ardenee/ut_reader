<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/GameProfiles.php';

function gm_is_admin(): bool { return ($_SESSION['user']['role'] ?? '') === 'admin'; }
function gm_csrf(): string { $_SESSION['game_manager_csrf'] ??= bin2hex(random_bytes(16)); return $_SESSION['game_manager_csrf']; }
function gm_check_csrf(): void { if (($_POST['csrf'] ?? '') !== ($_SESSION['game_manager_csrf'] ?? '')) { throw new RuntimeException('Bad CSRF token'); } }
function gm_slug(string $text): string { $text = strtolower(trim($text)); $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? ''; return trim($text, '-') ?: 'game'; }
function gm_json_extensions(string $text): string
{
    $parts = preg_split('/[,\s]+/', strtolower(trim($text))) ?: [];
    $parts = array_values(array_unique(array_filter(array_map(static fn($v) => trim($v, '. '), $parts), static fn($v) => $v !== '')));
    return json_encode($parts, JSON_UNESCAPED_SLASHES);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!gm_is_admin()) {
        catalog_head('Admin required');
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        gm_check_csrf();
        $action = (string)($_POST['action'] ?? 'save_game');

        if ($action === 'save_game') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $slug = gm_slug((string)($_POST['slug'] ?? $name));
            $engine = strtoupper(trim((string)($_POST['engine_key'] ?? '')));
            $description = trim((string)($_POST['description'] ?? ''));
            if ($name === '' || $engine === '') {
                throw new RuntimeException('Game name and profile engine are required.');
            }

            if ($id > 0) {
                $db->prepare('UPDATE ue_games SET name=?, slug=?, description=? WHERE id=?')->execute([$name, $slug, $description ?: null, $id]);
                $gameId = $id;
            } else {
                $stmt = $db->prepare('INSERT INTO ue_games(name, slug, description) VALUES(?,?,?)');
                $stmt->execute([$name, $slug, $description ?: null]);
                $gameId = (int)$db->lastInsertId();
            }

            $exts = gm_json_extensions((string)($_POST['extensions'] ?? ''));
            $vmin = trim((string)($_POST['package_version_min'] ?? ''));
            $vmax = trim((string)($_POST['package_version_max'] ?? ''));
            $lmin = trim((string)($_POST['licensee_version_min'] ?? ''));
            $lmax = trim((string)($_POST['licensee_version_max'] ?? ''));
            $policy = in_array((string)($_POST['confidence_policy'] ?? 'normal'), ['strict','normal','loose'], true) ? (string)$_POST['confidence_policy'] : 'normal';
            $notes = trim((string)($_POST['profile_notes'] ?? ''));
            $stmt = $db->prepare('INSERT INTO ue_game_profiles(game_id,engine_key,allowed_extensions_json,package_version_min,package_version_max,licensee_version_min,licensee_version_max,confidence_policy,notes,is_active) VALUES(?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE engine_key=VALUES(engine_key), allowed_extensions_json=VALUES(allowed_extensions_json), package_version_min=VALUES(package_version_min), package_version_max=VALUES(package_version_max), licensee_version_min=VALUES(licensee_version_min), licensee_version_max=VALUES(licensee_version_max), confidence_policy=VALUES(confidence_policy), notes=VALUES(notes), is_active=1');
            $stmt->execute([$gameId, $engine, $exts, $vmin === '' ? null : (int)$vmin, $vmax === '' ? null : (int)$vmax, $lmin === '' ? null : (int)$lmin, $lmax === '' ? null : (int)$lmax, $policy, $notes ?: null]);

            $_SESSION['game_manager_flash'] = 'Game and scanner profile saved.';
            header('Location: game-manager.php?game_id=' . $gameId);
            exit;
        }
    }

    catalog_head('Game Admin');

    if (isset($_SESSION['game_manager_flash'])) {
        echo '<div class="card"><strong>' . catalog_h($_SESSION['game_manager_flash']) . '</strong></div>';
        unset($_SESSION['game_manager_flash']);
    }

    $games = catalog_all($db, 'SELECT g.*, p.id profile_id, p.engine_key profile_engine, p.allowed_extensions_json, p.package_version_min, p.package_version_max, p.licensee_version_min, p.licensee_version_max, p.confidence_policy, p.notes profile_notes, p.is_active profile_active, COUNT(DISTINCT f.id) file_count, COUNT(DISTINCT s.id) source_count FROM ue_games g LEFT JOIN ue_game_profiles p ON p.game_id=g.id AND p.is_active=1 LEFT JOIN ue_files f ON f.game_id=g.id LEFT JOIN ue_sources s ON s.game_id=g.id GROUP BY g.id, p.id ORDER BY g.name');
    $editId = (int)($_GET['game_id'] ?? 0);
    $edit = null;
    foreach ($games as $row) {
        if ((int)$row['id'] === $editId) {
            $edit = $row;
            break;
        }
    }

    echo '<div class="card hero"><h1>Game Admin</h1><p class="muted">Add games, assign the scanner profile, and attach folders or download sources to that game.</p>';
    catalog_page_links(['Upload Files' => 'profiled-upload.php' . ($editId ? '?game_id=' . $editId : ''), 'Add Game Source' => 'sources.php' . ($editId ? '?game_id=' . $editId : ''), 'Scan Sources' => 'source-scan.php', 'Library' => 'library.php']);
    echo '</div>';

    echo '<div class="card"><h2>Games</h2>';
    if (!$games) {
        echo '<p class="muted">No games configured.</p>';
    } else {
        echo '<table><tr><th>Game</th><th>Profile engine</th><th>Extensions</th><th>Version range</th><th>Files</th><th>Sources</th><th>Actions</th></tr>';
        foreach ($games as $game) {
            $exts = json_decode((string)($game['allowed_extensions_json'] ?? '[]'), true);
            $range = ($game['package_version_min'] !== null || $game['package_version_max'] !== null) ? (($game['package_version_min'] ?? '?') . ' - ' . ($game['package_version_max'] ?? '?')) : 'not fixed';
            $engine = $game['profile_engine'] ?: 'missing profile';
            $engineClass = $game['profile_engine'] ? 'good-pill' : 'bad-pill';
            echo '<tr><td><strong>' . catalog_h($game['name']) . '</strong><br><span class="muted small">' . catalog_h($game['slug']) . '</span></td><td><span class="pill ' . $engineClass . '">' . catalog_h($engine) . '</span></td><td class="mono small">' . catalog_h(is_array($exts) ? implode(', ', $exts) : '') . '</td><td class="mono">' . catalog_h($range) . '</td><td>' . (int)$game['file_count'] . '</td><td>' . (int)$game['source_count'] . '</td><td><a class="button" href="game-manager.php?game_id=' . (int)$game['id'] . '">Edit</a> <a class="button" href="sources.php?game_id=' . (int)$game['id'] . '">Sources</a> <a class="button" href="profiled-upload.php?game_id=' . (int)$game['id'] . '">Upload</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    $exts = [];
    if ($edit) {
        $exts = json_decode((string)($edit['allowed_extensions_json'] ?? '[]'), true);
        if (!is_array($exts)) {
            $exts = [];
        }
    }

    echo '<div class="card"><h2>' . ($edit ? 'Edit ' . catalog_h($edit['name']) : 'Add new game') . '</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(gm_csrf()) . '"><input type="hidden" name="action" value="save_game"><input type="hidden" name="id" value="' . (int)($edit['id'] ?? 0) . '"><table>';
    echo '<tr><th>Game name</th><td><input name="name" required value="' . catalog_h($edit['name'] ?? '') . '" style="min-width:420px"></td></tr>';
    echo '<tr><th>Slug</th><td><input name="slug" value="' . catalog_h($edit['slug'] ?? '') . '" style="min-width:260px"> <span class="muted">Used in URLs and storage paths.</span></td></tr>';
    echo '<tr><th>Description</th><td><textarea name="description" rows="3" style="width:100%">' . catalog_h($edit['description'] ?? '') . '</textarea></td></tr>';
    echo '<tr><th colspan="2">Scanner profile</th></tr>';
    echo '<tr><th>Engine key</th><td><input name="engine_key" required value="' . catalog_h($edit['profile_engine'] ?? 'UE1') . '" style="width:120px"> <span class="muted">UE1, UE2, UE3, UE4, UE5, etc. This comes from the game profile.</span></td></tr>';
    echo '<tr><th>Allowed extensions</th><td><input name="extensions" value="' . catalog_h(implode(', ', $exts)) . '" style="min-width:520px" placeholder="u, unr, utx, umx, uax"></td></tr>';
    echo '<tr><th>Package version min/max</th><td><input name="package_version_min" value="' . catalog_h((string)($edit['package_version_min'] ?? '')) . '" style="width:90px"> <input name="package_version_max" value="' . catalog_h((string)($edit['package_version_max'] ?? '')) . '" style="width:90px"> <span class="muted">Leave blank if unknown or unversioned.</span></td></tr>';
    echo '<tr><th>Licensee version min/max</th><td><input name="licensee_version_min" value="' . catalog_h((string)($edit['licensee_version_min'] ?? '')) . '" style="width:90px"> <input name="licensee_version_max" value="' . catalog_h((string)($edit['licensee_version_max'] ?? '')) . '" style="width:90px"></td></tr>';
    echo '<tr><th>Confidence policy</th><td><select name="confidence_policy">';
    foreach (['strict','normal','loose'] as $p) {
        echo '<option value="' . catalog_h($p) . '"' . (($edit['confidence_policy'] ?? 'normal') === $p ? ' selected' : '') . '>' . catalog_h($p) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th>Profile notes</th><td><textarea name="profile_notes" rows="4" style="width:100%">' . catalog_h($edit['profile_notes'] ?? '') . '</textarea></td></tr>';
    echo '</table><p><button>Save game and profile</button> <a class="button" href="game-manager.php">Add blank game</a></p></form></div>';

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
    if (!headers_sent()) {
        catalog_head('Game admin error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
