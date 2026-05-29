<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/GameProfiles.php';

function gm_slug(string $text): string { $text = strtolower(trim($text)); $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? ''; return trim($text, '-') ?: 'game'; }

function gm_profile_label(array $profile): string
{
    $exts = json_decode((string)($profile['allowed_extensions_json'] ?? '[]'), true);
    $extText = is_array($exts) && $exts ? ' / .' . implode(' .', $exts) : '';
    $range = ($profile['package_version_min'] !== null || $profile['package_version_max'] !== null) ? ' / version ' . ($profile['package_version_min'] ?? '?') . '-' . ($profile['package_version_max'] ?? '?') : '';
    return (string)$profile['profile_game_name'] . ' / ' . (string)$profile['engine_key'] . $extText . $range;
}

function gm_copy_profile_to_game(PDO $db, int $gameId, int $profileId): void
{
    $profile = catalog_one($db, 'SELECT * FROM ue_game_profiles WHERE id=? AND is_active=1', [$profileId]);
    if (!$profile) {
        throw new RuntimeException('Selected active game profile not found.');
    }

    $stmt = $db->prepare('INSERT INTO ue_game_profiles(game_id,engine_key,allowed_extensions_json,package_version_min,package_version_max,licensee_version_min,licensee_version_max,confidence_policy,notes,is_active) VALUES(?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE engine_key=VALUES(engine_key), allowed_extensions_json=VALUES(allowed_extensions_json), package_version_min=VALUES(package_version_min), package_version_max=VALUES(package_version_max), licensee_version_min=VALUES(licensee_version_min), licensee_version_max=VALUES(licensee_version_max), confidence_policy=VALUES(confidence_policy), notes=VALUES(notes), is_active=1');
    $stmt->execute([
        $gameId,
        (string)$profile['engine_key'],
        (string)$profile['allowed_extensions_json'],
        $profile['package_version_min'] !== null ? (int)$profile['package_version_min'] : null,
        $profile['package_version_max'] !== null ? (int)$profile['package_version_max'] : null,
        $profile['licensee_version_min'] !== null ? (int)$profile['licensee_version_min'] : null,
        $profile['licensee_version_max'] !== null ? (int)$profile['licensee_version_max'] : null,
        (string)$profile['confidence_policy'],
        $profile['notes'] !== null ? (string)$profile['notes'] : null,
    ]);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page()) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('game_manager');
        $action = (string)($_POST['action'] ?? 'save_game');

        if ($action === 'save_game') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $slug = gm_slug((string)($_POST['slug'] ?? $name));
            $profileId = (int)($_POST['profile_id'] ?? 0);
            $description = trim((string)($_POST['description'] ?? ''));
            if ($name === '' || $profileId <= 0) {
                throw new RuntimeException('Game name and game profile are required.');
            }

            if ($id > 0) {
                $db->prepare('UPDATE ue_games SET name=?, slug=?, description=? WHERE id=?')->execute([$name, $slug, $description ?: null, $id]);
                $gameId = $id;
            } else {
                $stmt = $db->prepare('INSERT INTO ue_games(name, slug, description) VALUES(?,?,?)');
                $stmt->execute([$name, $slug, $description ?: null]);
                $gameId = (int)$db->lastInsertId();
            }

            gm_copy_profile_to_game($db, $gameId, $profileId);

            $_SESSION['game_manager_flash'] = 'Game saved and profile assigned.';
            header('Location: game-manager.php?game_id=' . $gameId);
            exit;
        }
    }

    catalog_head('Game Admin');
    catalog_flash($_SESSION['game_manager_flash'] ?? null);
    unset($_SESSION['game_manager_flash']);

    $profileChoices = catalog_all($db, 'SELECT p.*, g.name profile_game_name, g.slug profile_game_slug FROM ue_game_profiles p JOIN ue_games g ON g.id=p.game_id WHERE p.is_active=1 ORDER BY g.name, p.engine_key, p.id');
    $games = catalog_all($db, 'SELECT g.*, p.id profile_id, p.engine_key profile_engine, p.allowed_extensions_json, p.package_version_min, p.package_version_max, p.licensee_version_min, p.licensee_version_max, p.confidence_policy, p.notes profile_notes, p.is_active profile_active, COUNT(DISTINCT f.id) file_count, COUNT(DISTINCT s.id) source_count FROM ue_games g LEFT JOIN ue_game_profiles p ON p.game_id=g.id AND p.is_active=1 LEFT JOIN ue_files f ON f.game_id=g.id LEFT JOIN ue_sources s ON s.game_id=g.id GROUP BY g.id, p.id ORDER BY g.name');
    $editId = (int)($_GET['game_id'] ?? 0);
    $edit = null;
    foreach ($games as $row) {
        if ((int)$row['id'] === $editId) {
            $edit = $row;
            break;
        }
    }

    catalog_page_header('Game Admin', 'Add games, assign an existing scanner profile, and attach folders or download sources to that game. Create or edit profile rules in Game Profiles.', ['Game Profiles' => 'game-profiles.php', 'Upload Files' => 'profiled-upload.php' . ($editId ? '?game_id=' . $editId : ''), 'Add Game Source' => 'sources.php' . ($editId ? '?game_id=' . $editId : ''), 'Scan Sources' => 'source-scan.php', 'Library' => 'library.php']);

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

    echo '<div class="card"><h2>' . ($edit ? 'Edit ' . catalog_h($edit['name']) : 'Add new game') . '</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('game_manager')) . '"><input type="hidden" name="action" value="save_game"><input type="hidden" name="id" value="' . (int)($edit['id'] ?? 0) . '"><table>';
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
            $selected = $edit && (int)$edit['profile_id'] === (int)$profile['id'] ? ' selected' : '';
            echo '<option value="' . (int)$profile['id'] . '"' . $selected . '>' . catalog_h(gm_profile_label($profile)) . '</option>';
        }
        echo '</select><p class="muted small">Profiles are managed in Game Profiles. Selecting one here assigns a copy of that profile to this game.</p>';
    }
    echo '</td></tr>';
    echo '</table><p><button' . (!$profileChoices ? ' disabled' : '') . '>Save game</button> <a class="button" href="game-manager.php">Add blank game</a> <a class="button" href="game-profiles.php">Manage profiles</a></p></form></div>';

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
