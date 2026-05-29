<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/GameProfiles.php';

function gprof_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function gprof_csrf(): string
{
    $_SESSION['game_profiles_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['game_profiles_csrf'];
}

function gprof_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['game_profiles_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function gprof_json_extensions(string $text): string
{
    $parts = preg_split('/[,\s]+/', strtolower(trim($text))) ?: [];
    $parts = array_values(array_unique(array_filter(array_map(static fn($v) => trim($v, '. '), $parts), static fn($v) => $v !== '')));
    return json_encode($parts, JSON_UNESCAPED_SLASHES);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!gprof_is_admin()) {
        catalog_head('Admin required');
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        gprof_check_csrf();
        $gameId = (int)($_POST['game_id'] ?? 0);
        $engine = strtoupper(trim((string)($_POST['engine_key'] ?? '')));
        $exts = gprof_json_extensions((string)($_POST['extensions'] ?? ''));
        $vmin = trim((string)($_POST['package_version_min'] ?? ''));
        $vmax = trim((string)($_POST['package_version_max'] ?? ''));
        $lmin = trim((string)($_POST['licensee_version_min'] ?? ''));
        $lmax = trim((string)($_POST['licensee_version_max'] ?? ''));
        $policy = in_array((string)($_POST['confidence_policy'] ?? 'normal'), ['strict','normal','loose'], true) ? (string)$_POST['confidence_policy'] : 'normal';
        $notes = trim((string)($_POST['notes'] ?? ''));
        $active = (int)($_POST['is_active'] ?? 1);
        if ($gameId <= 0 || $engine === '') {
            throw new RuntimeException('Game and profile engine are required.');
        }
        $stmt = $db->prepare('INSERT INTO ue_game_profiles(game_id,engine_key,allowed_extensions_json,package_version_min,package_version_max,licensee_version_min,licensee_version_max,confidence_policy,notes,is_active) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE engine_key=VALUES(engine_key), allowed_extensions_json=VALUES(allowed_extensions_json), package_version_min=VALUES(package_version_min), package_version_max=VALUES(package_version_max), licensee_version_min=VALUES(licensee_version_min), licensee_version_max=VALUES(licensee_version_max), confidence_policy=VALUES(confidence_policy), notes=VALUES(notes), is_active=VALUES(is_active)');
        $stmt->execute([$gameId, $engine, $exts, $vmin === '' ? null : (int)$vmin, $vmax === '' ? null : (int)$vmax, $lmin === '' ? null : (int)$lmin, $lmax === '' ? null : (int)$lmax, $policy, $notes ?: null, $active]);
        $_SESSION['game_profiles_flash'] = 'Game profile saved.';
        header('Location: game-profiles.php');
        exit;
    }

    catalog_head('Game Profiles');

    if (isset($_SESSION['game_profiles_flash'])) {
        echo '<div class="card"><strong>' . catalog_h($_SESSION['game_profiles_flash']) . '</strong></div>';
        unset($_SESSION['game_profiles_flash']);
    }

    $games = catalog_all($db, 'SELECT g.id, g.name, g.slug, p.id profile_id, p.engine_key profile_engine, p.allowed_extensions_json, p.package_version_min, p.package_version_max, p.licensee_version_min, p.licensee_version_max, p.confidence_policy, p.notes, p.is_active FROM ue_games g LEFT JOIN ue_game_profiles p ON p.game_id=g.id ORDER BY g.name');

    echo '<div class="card hero"><h1>Game Profiles</h1><p class="muted">Advanced scanner rules for each game. Most edits should happen through Game Admin.</p>';
    catalog_page_links(['Game Admin' => 'game-manager.php', 'Upload Files' => 'profiled-upload.php', 'Library' => 'library.php']);
    echo '</div>';

    echo '<div class="card"><h2>Profiles</h2><table><tr><th>Game</th><th>Profile engine</th><th>Extensions</th><th>Version</th><th>Policy</th><th>Active</th><th>Edit</th></tr>';
    foreach ($games as $game) {
        $exts = json_decode((string)($game['allowed_extensions_json'] ?? '[]'), true);
        $range = ($game['package_version_min'] !== null || $game['package_version_max'] !== null) ? (($game['package_version_min'] ?? '?') . ' - ' . ($game['package_version_max'] ?? '?')) : 'not fixed';
        $engine = $game['profile_engine'] ?: 'missing profile';
        $engineClass = $game['profile_engine'] ? 'good-pill' : 'bad-pill';
        echo '<tr><td>' . catalog_h($game['name']) . '</td><td><span class="pill ' . $engineClass . '">' . catalog_h($engine) . '</span></td><td class="mono">' . catalog_h(is_array($exts) ? implode(', ', $exts) : '') . '</td><td class="mono">' . catalog_h($range) . '</td><td>' . catalog_h($game['confidence_policy'] ?? '') . '</td><td>' . ((int)($game['is_active'] ?? 0) ? 'yes' : 'no') . '</td><td><a class="button" href="game-profiles.php?game_id=' . (int)$game['id'] . '">edit</a></td></tr>';
    }
    echo '</table></div>';

    $editGameId = (int)($_GET['game_id'] ?? ($games[0]['id'] ?? 0));
    $edit = null;
    foreach ($games as $game) {
        if ((int)$game['id'] === $editGameId) {
            $edit = $game;
            break;
        }
    }
    if ($edit) {
        $exts = json_decode((string)($edit['allowed_extensions_json'] ?? '[]'), true);
        echo '<div class="card"><h2>Edit profile: ' . catalog_h($edit['name']) . '</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(gprof_csrf()) . '"><input type="hidden" name="game_id" value="' . (int)$edit['id'] . '"><table>';
        echo '<tr><th>Engine key</th><td><input name="engine_key" value="' . catalog_h($edit['profile_engine'] ?? 'UE1') . '" style="width:120px"> <span class="muted">Examples: UE1, UE2, UE3, UE4, UE5</span></td></tr>';
        echo '<tr><th>Extensions</th><td><input name="extensions" value="' . catalog_h(is_array($exts) ? implode(', ', $exts) : '') . '" style="min-width:520px"></td></tr>';
        echo '<tr><th>Package version min/max</th><td><input name="package_version_min" value="' . catalog_h((string)($edit['package_version_min'] ?? '')) . '" style="width:90px"> <input name="package_version_max" value="' . catalog_h((string)($edit['package_version_max'] ?? '')) . '" style="width:90px"></td></tr>';
        echo '<tr><th>Licensee version min/max</th><td><input name="licensee_version_min" value="' . catalog_h((string)($edit['licensee_version_min'] ?? '')) . '" style="width:90px"> <input name="licensee_version_max" value="' . catalog_h((string)($edit['licensee_version_max'] ?? '')) . '" style="width:90px"></td></tr>';
        echo '<tr><th>Confidence policy</th><td><select name="confidence_policy">';
        foreach (['strict','normal','loose'] as $p) {
            echo '<option value="' . catalog_h($p) . '"' . (($edit['confidence_policy'] ?? 'normal') === $p ? ' selected' : '') . '>' . catalog_h($p) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th>Active</th><td><select name="is_active"><option value="1"' . ((int)($edit['is_active'] ?? 1) === 1 ? ' selected' : '') . '>yes</option><option value="0"' . ((int)($edit['is_active'] ?? 1) === 0 ? ' selected' : '') . '>no</option></select></td></tr>';
        echo '<tr><th>Notes</th><td><textarea name="notes" rows="4" style="width:100%">' . catalog_h($edit['notes'] ?? '') . '</textarea></td></tr>';
        echo '</table><p><button>Save profile</button></p></form></div>';
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Game profiles error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
