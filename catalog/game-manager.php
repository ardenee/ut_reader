<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/GameProfiles.php';

function gm_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function gm_csrf(): string
{
    $_SESSION['game_manager_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['game_manager_csrf'];
}

function gm_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['game_manager_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function gm_slug(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'game';
}

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
        $action = (string)($_POST['action'] ?? 'save');

        if ($action === 'save_game') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $slug = gm_slug((string)($_POST['slug'] ?? $name));
            $engine = strtoupper(trim((string)($_POST['engine_key'] ?? '')));
            $description = trim((string)($_POST['description'] ?? ''));
            if ($name === '' || $engine === '') {
                throw new RuntimeException('Game name and engine key are required.');
            }

            if ($id > 0) {
                $db->prepare('UPDATE ue_games SET name=?, slug=?, engine_key=?, description=? WHERE id=?')->execute([$name, $slug, $engine, $description ?: null, $id]);
                $gameId = $id;
            } else {
                $stmt = $db->prepare('INSERT INTO ue_games(name, slug, engine_key, description) VALUES(?,?,?,?)');
                $stmt->execute([$name, $slug, $engine, $description ?: null]);
                $gameId = (int)$db->lastInsertId();
            }

            if (($_POST['save_profile'] ?? '1') === '1') {
                $exts = gm_json_extensions((string)($_POST['extensions'] ?? ''));
                $vmin = trim((string)($_POST['package_version_min'] ?? ''));
                $vmax = trim((string)($_POST['package_version_max'] ?? ''));
                $lmin = trim((string)($_POST['licensee_version_min'] ?? ''));
                $lmax = trim((string)($_POST['licensee_version_max'] ?? ''));
                $policy = in_array((string)($_POST['confidence_policy'] ?? 'normal'), ['strict','normal','loose'], true) ? (string)$_POST['confidence_policy'] : 'normal';
                $notes = trim((string)($_POST['profile_notes'] ?? ''));
                $stmt = $db->prepare('INSERT INTO ue_game_profiles(game_id,engine_key,allowed_extensions_json,package_version_min,package_version_max,licensee_version_min,licensee_version_max,confidence_policy,notes,is_active) VALUES(?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE engine_key=VALUES(engine_key), allowed_extensions_json=VALUES(allowed_extensions_json), package_version_min=VALUES(package_version_min), package_version_max=VALUES(package_version_max), licensee_version_min=VALUES(licensee_version_min), licensee_version_max=VALUES(licensee_version_max), confidence_policy=VALUES(confidence_policy), notes=VALUES(notes), is_active=1');
                $stmt->execute([$gameId, $engine, $exts, $vmin === '' ? null : (int)$vmin, $vmax === '' ? null : (int)$vmax, $lmin === '' ? null : (int)$lmin, $lmax === '' ? null : (int)$lmax, $policy, $notes ?: null]);
            }

            $_SESSION['game_manager_flash'] = 'Game saved.';
            header('Location: game-manager.php?game_id=' . $gameId);
            exit;
        }
    }

    catalog_head('Game Manager');

    if (isset($_SESSION['game_manager_flash'])) {
        echo '<div class="card"><strong>' . catalog_h($_SESSION['game_manager_flash']) . '</strong></div>';
        unset($_SESSION['game_manager_flash']);
    }

    $games = catalog_all($db, 'SELECT g.*, p.id profile_id, p.engine_key profile_engine, p.allowed_extensions_json, p.package_version_min, p.package_version_max, p.licensee_version_min, p.licensee_version_max, p.confidence_policy, p.notes profile_notes, p.is_active profile_active, COUNT(f.id) file_count FROM ue_games g LEFT JOIN ue_game_profiles p ON p.game_id=g.id LEFT JOIN ue_files f ON f.game_id=g.id GROUP BY g.id, p.id ORDER BY g.name');
    $editId = (int)($_GET['game_id'] ?? 0);
    $edit = null;
    foreach ($games as $row) {
        if ((int)$row['id'] === $editId) {
            $edit = $row;
            break;
        }
    }

    echo '<div class="card hero"><h1>Game Manager</h1><p class="muted">Add/edit games and their scanner profiles. This is the data-driven table for future UE games, including UE5 titles later.</p>';
    catalog_page_links(['Setup' => 'setup.php', 'Profiled Upload' => 'profiled-upload.php', 'Game Profiles' => 'game-profiles.php', 'Library' => 'library.php']);
    echo '</div>';

    echo '<div class="card"><h2>Configured games</h2>';
    if (!$games) {
        echo '<p class="muted">No games configured.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Game</th><th>Slug</th><th>Engine</th><th>Profile</th><th>Exts</th><th>Version range</th><th>Files</th><th>Edit</th></tr>';
        foreach ($games as $game) {
            $exts = json_decode((string)($game['allowed_extensions_json'] ?? '[]'), true);
            $range = ($game['package_version_min'] !== null || $game['package_version_max'] !== null) ? (($game['package_version_min'] ?? '?') . ' - ' . ($game['package_version_max'] ?? '?')) : 'not fixed';
            echo '<tr><td class="mono">' . (int)$game['id'] . '</td><td>' . catalog_h($game['name']) . '</td><td class="mono">' . catalog_h($game['slug']) . '</td><td class="mono">' . catalog_h($game['engine_key']) . '</td><td class="mono">' . catalog_h($game['profile_engine'] ?? 'none') . '</td><td class="mono small">' . catalog_h(is_array($exts) ? implode(', ', $exts) : '') . '</td><td class="mono">' . catalog_h($range) . '</td><td>' . (int)$game['file_count'] . '</td><td><a class="button" href="game-manager.php?game_id=' . (int)$game['id'] . '">edit</a></td></tr>';
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

    echo '<div class="card"><h2>' . ($edit ? 'Edit game: ' . catalog_h($edit['name']) : 'Add new game') . '</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(gm_csrf()) . '"><input type="hidden" name="action" value="save_game"><input type="hidden" name="id" value="' . (int)($edit['id'] ?? 0) . '"><table>';
    echo '<tr><th>Game name</th><td><input name="name" required value="' . catalog_h($edit['name'] ?? '') . '" style="min-width:420px"></td></tr>';
    echo '<tr><th>Slug</th><td><input name="slug" value="' . catalog_h($edit['slug'] ?? '') . '" style="min-width:260px"> <span class="muted">Used in URLs/storage paths. Auto-generated if blank.</span></td></tr>';
    echo '<tr><th>Engine key</th><td><input name="engine_key" required value="' . catalog_h($edit['profile_engine'] ?? $edit['engine_key'] ?? 'UE1') . '" style="width:120px"> <span class="muted">UE1, UE2, UE3, UE4, UE5, etc.</span></td></tr>';
    echo '<tr><th>Description</th><td><textarea name="description" rows="3" style="width:100%">' . catalog_h($edit['description'] ?? '') . '</textarea></td></tr>';
    echo '<tr><th>Create/update scanner profile</th><td><select name="save_profile"><option value="1" selected>yes</option><option value="0">no</option></select></td></tr>';
    echo '<tr><th>Allowed extensions</th><td><input name="extensions" value="' . catalog_h(implode(', ', $exts)) . '" style="min-width:520px"> <span class="muted">Example: u, unr, utx, umx, uax</span></td></tr>';
    echo '<tr><th>Package version min/max</th><td><input name="package_version_min" value="' . catalog_h((string)($edit['package_version_min'] ?? '')) . '" style="width:90px"> <input name="package_version_max" value="' . catalog_h((string)($edit['package_version_max'] ?? '')) . '" style="width:90px"> <span class="muted">Leave blank for UE4/UE5/custom unversioned packages.</span></td></tr>';
    echo '<tr><th>Licensee version min/max</th><td><input name="licensee_version_min" value="' . catalog_h((string)($edit['licensee_version_min'] ?? '')) . '" style="width:90px"> <input name="licensee_version_max" value="' . catalog_h((string)($edit['licensee_version_max'] ?? '')) . '" style="width:90px"></td></tr>';
    echo '<tr><th>Confidence policy</th><td><select name="confidence_policy">';
    foreach (['strict','normal','loose'] as $p) {
        echo '<option value="' . catalog_h($p) . '"' . (($edit['confidence_policy'] ?? 'normal') === $p ? ' selected' : '') . '>' . catalog_h($p) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th>Profile notes</th><td><textarea name="profile_notes" rows="4" style="width:100%">' . catalog_h($edit['profile_notes'] ?? '') . '</textarea></td></tr>';
    echo '</table><p><button>Save game and profile</button> <a class="button" href="game-manager.php">Add new blank game</a></p></form></div>';

    echo '<div class="card"><h2>How to add a new UE game</h2><ol><li>Add the game name, slug, and engine key.</li><li>Enter the file extensions used by that game.</li><li>Enter package version min/max if known. Leave blank if unknown, UE4/UE5, or custom-version based.</li><li>Save, then use Profiled Upload Scanner to test known-good files.</li><li>Adjust the version range once you have enough known-good samples.</li></ol></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Game manager error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
