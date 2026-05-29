<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/GameProfiles.php';

function gprof_json_extensions(string $text): string
{
    $parts = preg_split('/[,\s]+/', strtolower(trim($text))) ?: [];
    $parts = array_values(array_unique(array_filter(array_map(static fn($v) => trim($v, '. '), $parts), static fn($v) => $v !== '')));
    return json_encode($parts, JSON_UNESCAPED_SLASHES);
}

function gprof_extension_text(?string $json): string
{
    $exts = json_decode((string)($json ?? '[]'), true);
    return is_array($exts) ? implode(', ', $exts) : '';
}

function gprof_save(PDO $db, int $profileId, int $gameId, string $engine, string $extensions, string $versionMin, string $versionMax, string $licenseeMin, string $licenseeMax, string $policy, string $notes, int $active): int
{
    if ($gameId <= 0 || $engine === '') {
        throw new RuntimeException('Game and profile engine are required.');
    }

    if ($profileId > 0) {
        $existing = catalog_one($db, 'SELECT id FROM ue_game_profiles WHERE id=?', [$profileId]);
        if (!$existing) {
            throw new RuntimeException('Profile not found.');
        }
        $other = catalog_one($db, 'SELECT id FROM ue_game_profiles WHERE game_id=? AND id<>? LIMIT 1', [$gameId, $profileId]);
        if ($other) {
            throw new RuntimeException('That game already has a profile. Edit that existing profile or remove it first.');
        }
        $stmt = $db->prepare('UPDATE ue_game_profiles SET game_id=?, engine_key=?, allowed_extensions_json=?, package_version_min=?, package_version_max=?, licensee_version_min=?, licensee_version_max=?, confidence_policy=?, notes=?, is_active=? WHERE id=?');
        $stmt->execute([$gameId, $engine, gprof_json_extensions($extensions), $versionMin === '' ? null : (int)$versionMin, $versionMax === '' ? null : (int)$versionMax, $licenseeMin === '' ? null : (int)$licenseeMin, $licenseeMax === '' ? null : (int)$licenseeMax, $policy, $notes ?: null, $active, $profileId]);
        return $profileId;
    }

    $existing = catalog_one($db, 'SELECT id FROM ue_game_profiles WHERE game_id=? LIMIT 1', [$gameId]);
    if ($existing) {
        throw new RuntimeException('That game already has a profile. Use Edit on the existing profile instead.');
    }

    $stmt = $db->prepare('INSERT INTO ue_game_profiles(game_id,engine_key,allowed_extensions_json,package_version_min,package_version_max,licensee_version_min,licensee_version_max,confidence_policy,notes,is_active) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$gameId, $engine, gprof_json_extensions($extensions), $versionMin === '' ? null : (int)$versionMin, $versionMax === '' ? null : (int)$versionMax, $licenseeMin === '' ? null : (int)$licenseeMin, $licenseeMax === '' ? null : (int)$licenseeMax, $policy, $notes ?: null, $active]);
    return (int)$db->lastInsertId();
}

function gprof_delete(PDO $db, int $profileId): void
{
    $profile = catalog_one($db, 'SELECT p.*, g.name game_name FROM ue_game_profiles p JOIN ue_games g ON g.id=p.game_id WHERE p.id=?', [$profileId]);
    if (!$profile) {
        throw new RuntimeException('Profile not found.');
    }
    if ((int)$profile['is_active'] === 1) {
        throw new RuntimeException('This game profile is in use by ' . (string)$profile['game_name'] . '. Remove/deactivate it from the game first, then delete it.');
    }
    $db->prepare('DELETE FROM ue_game_profiles WHERE id=?')->execute([$profileId]);
}

function gprof_form(array $games, ?array $profile, string $mode): void
{
    $isEdit = $mode === 'edit';
    $title = $isEdit ? 'Edit game profile: ' . (string)$profile['game_name'] : 'Add new game profile';
    $button = $isEdit ? 'Update' : 'Add';
    $selectedGameId = (int)($profile['game_id'] ?? 0);
    $policy = (string)($profile['confidence_policy'] ?? 'normal');
    $active = (int)($profile['is_active'] ?? 1);

    echo '<div class="card"><h2>' . catalog_h($title) . '</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('game_profiles')) . '"><input type="hidden" name="action" value="' . ($isEdit ? 'update' : 'add') . '"><input type="hidden" name="profile_id" value="' . (int)($profile['profile_id'] ?? 0) . '"><table>';
    echo '<tr><th>Game</th><td><select name="game_id" required><option value="">Select game...</option>';
    foreach ($games as $game) {
        $sel = (int)$game['id'] === $selectedGameId ? ' selected' : '';
        echo '<option value="' . (int)$game['id'] . '"' . $sel . '>' . catalog_h($game['name']) . '</option>';
    }
    echo '</select><p class="muted small">Current schema allows one profile per game. Reusable/global profiles will need a future DB migration.</p></td></tr>';
    echo '<tr><th>Engine key</th><td><input name="engine_key" required value="' . catalog_h($profile['profile_engine'] ?? 'UE1') . '" style="width:120px"> <span class="muted">Examples: UE1, UE2, UE3, UE4, UE5</span></td></tr>';
    echo '<tr><th>Extensions</th><td><input name="extensions" value="' . catalog_h(gprof_extension_text($profile['allowed_extensions_json'] ?? null)) . '" style="min-width:520px" placeholder="u, unr, utx, umx, uax"></td></tr>';
    echo '<tr><th>Package version min/max</th><td><input name="package_version_min" value="' . catalog_h((string)($profile['package_version_min'] ?? '')) . '" style="width:90px"> <input name="package_version_max" value="' . catalog_h((string)($profile['package_version_max'] ?? '')) . '" style="width:90px"></td></tr>';
    echo '<tr><th>Licensee version min/max</th><td><input name="licensee_version_min" value="' . catalog_h((string)($profile['licensee_version_min'] ?? '')) . '" style="width:90px"> <input name="licensee_version_max" value="' . catalog_h((string)($profile['licensee_version_max'] ?? '')) . '" style="width:90px"></td></tr>';
    echo '<tr><th>Confidence policy</th><td><select name="confidence_policy">';
    foreach (['strict','normal','loose'] as $p) {
        echo '<option value="' . catalog_h($p) . '"' . ($policy === $p ? ' selected' : '') . '>' . catalog_h($p) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th>Active / assigned</th><td><select name="is_active"><option value="1"' . ($active === 1 ? ' selected' : '') . '>yes</option><option value="0"' . ($active === 0 ? ' selected' : '') . '>no</option></select> <span class="muted">Delete is only allowed after this is set to no.</span></td></tr>';
    echo '<tr><th>Notes</th><td><textarea name="notes" rows="4" style="width:100%">' . catalog_h($profile['notes'] ?? '') . '</textarea></td></tr>';
    echo '</table><p><button>' . catalog_h($button) . '</button> <a class="button" href="game-profiles.php">Cancel</a></p></form></div>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Game Profiles')) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('game_profiles');
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'delete') {
            gprof_delete($db, (int)($_POST['profile_id'] ?? 0));
            $_SESSION['game_profiles_flash'] = 'Game profile deleted.';
            header('Location: game-profiles.php');
            exit;
        }

        if ($action === 'add' || $action === 'update') {
            $profileId = $action === 'update' ? (int)($_POST['profile_id'] ?? 0) : 0;
            $savedId = gprof_save(
                $db,
                $profileId,
                (int)($_POST['game_id'] ?? 0),
                strtoupper(trim((string)($_POST['engine_key'] ?? ''))),
                (string)($_POST['extensions'] ?? ''),
                trim((string)($_POST['package_version_min'] ?? '')),
                trim((string)($_POST['package_version_max'] ?? '')),
                trim((string)($_POST['licensee_version_min'] ?? '')),
                trim((string)($_POST['licensee_version_max'] ?? '')),
                in_array((string)($_POST['confidence_policy'] ?? 'normal'), ['strict','normal','loose'], true) ? (string)$_POST['confidence_policy'] : 'normal',
                trim((string)($_POST['notes'] ?? '')),
                (int)($_POST['is_active'] ?? 1)
            );
            $_SESSION['game_profiles_flash'] = $action === 'add' ? 'Game profile added.' : 'Game profile updated.';
            header('Location: game-profiles.php?profile_id=' . $savedId);
            exit;
        }
    }

    catalog_head('Game Profiles');
    catalog_flash($_SESSION['game_profiles_flash'] ?? null);
    unset($_SESSION['game_profiles_flash']);

    $profiles = catalog_all($db, 'SELECT g.id game_id, g.name game_name, g.slug, p.id profile_id, p.engine_key profile_engine, p.allowed_extensions_json, p.package_version_min, p.package_version_max, p.licensee_version_min, p.licensee_version_max, p.confidence_policy, p.notes, p.is_active FROM ue_game_profiles p JOIN ue_games g ON g.id=p.game_id ORDER BY g.name, p.engine_key');
    $games = catalog_all($db, 'SELECT id, name, slug FROM ue_games ORDER BY name');
    $mode = (string)($_GET['mode'] ?? '');
    $editProfileId = (int)($_GET['profile_id'] ?? 0);
    $edit = null;
    if ($editProfileId > 0) {
        foreach ($profiles as $profile) {
            if ((int)$profile['profile_id'] === $editProfileId) {
                $edit = $profile;
                break;
            }
        }
    }

    catalog_page_header('Game Profiles', 'Create and maintain scanner profile rules here. Game Admin should only assign an existing profile to a game.', ['Game Admin' => 'game-manager.php', 'Upload Files' => 'profiled-upload.php', 'Library' => 'library.php']);

    echo '<div class="card"><h2>Game profiles</h2>';
    if (!$profiles) {
        echo '<p class="muted">No game profiles configured yet.</p>';
    } else {
        echo '<table><tr><th>Game</th><th>Profile engine</th><th>Extensions</th><th>Version</th><th>Licensee</th><th>Policy</th><th>Active</th><th>Actions</th></tr>';
        foreach ($profiles as $profile) {
            $range = ($profile['package_version_min'] !== null || $profile['package_version_max'] !== null) ? (($profile['package_version_min'] ?? '?') . ' - ' . ($profile['package_version_max'] ?? '?')) : 'not fixed';
            $licensee = ($profile['licensee_version_min'] !== null || $profile['licensee_version_max'] !== null) ? (($profile['licensee_version_min'] ?? '?') . ' - ' . ($profile['licensee_version_max'] ?? '?')) : 'not fixed';
            $engine = $profile['profile_engine'] ?: 'missing profile';
            $engineClass = $profile['profile_engine'] ? 'good-pill' : 'bad-pill';
            echo '<tr><td>' . catalog_h($profile['game_name']) . '</td><td><span class="pill ' . $engineClass . '">' . catalog_h($engine) . '</span></td><td class="mono">' . catalog_h(gprof_extension_text($profile['allowed_extensions_json'] ?? null)) . '</td><td class="mono">' . catalog_h($range) . '</td><td class="mono">' . catalog_h($licensee) . '</td><td>' . catalog_h($profile['confidence_policy'] ?? '') . '</td><td>' . ((int)$profile['is_active'] ? 'yes' : 'no') . '</td><td><a class="button" href="game-profiles.php?profile_id=' . (int)$profile['profile_id'] . '&mode=edit">Edit</a> <form method="post" style="display:inline" onsubmit="return confirm(\'Delete this game profile?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('game_profiles')) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="profile_id" value="' . (int)$profile['profile_id'] . '"><button>Delete</button></form></td></tr>';
        }
        echo '</table>';
    }
    echo '<p><a class="button" href="game-profiles.php?mode=new">New</a></p></div>';

    if ($mode === 'new') {
        gprof_form($games, null, 'new');
    } elseif ($mode === 'edit') {
        if (!$edit) {
            echo '<div class="card"><h2>Profile not found</h2><p class="muted">The selected profile could not be found.</p></div>';
        } else {
            gprof_form($games, $edit, 'edit');
        }
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Game profiles error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
