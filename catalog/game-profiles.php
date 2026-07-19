<?php
declare(strict_types=1);


require_once __DIR__ . '/lib/CatalogSupport.php';

catalog_start_session();
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

function gprof_compatibility_rules_json(string $text): ?string
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }

    $rules = json_decode($text, true);
    if (!is_array($rules)) {
        throw new RuntimeException('Compatibility rules must be valid JSON array data.');
    }

    foreach ($rules as $i => $rule) {
        if (!is_array($rule) || trim((string)($rule['detected_engine'] ?? '')) === '') {
            throw new RuntimeException('Compatibility rule #' . ($i + 1) . ' requires detected_engine.');
        }
        if (!isset($rule['extensions']) || !is_array($rule['extensions']) || !$rule['extensions']) {
            throw new RuntimeException('Compatibility rule #' . ($i + 1) . ' requires one or more extensions.');
        }
    }

    return json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function gprof_compatibility_rules_text(?string $json): string
{
    $rules = json_decode((string)($json ?? '[]'), true);
    if (!is_array($rules) || !$rules) {
        return '';
    }
    return json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
}

function gprof_save(PDO $db, int $profileId, string $name, string $engine, string $extensions, string $compatibilityRules, string $versionMin, string $versionMax, string $licenseeMin, string $licenseeMax, string $policy, string $notes): int
{
    if ($name === '' || $engine === '') {
        throw new RuntimeException('Profile name and engine key are required.');
    }

    $rulesJson = gprof_compatibility_rules_json($compatibilityRules);
    if ($profileId > 0) {
        $existing = catalog_one($db, 'SELECT id FROM ue_game_profiles WHERE id=?', [$profileId]);
        if (!$existing) {
            throw new RuntimeException('Profile not found.');
        }
        $stmt = $db->prepare('UPDATE ue_game_profiles SET profile_name=?, game_id=NULL, engine_key=?, allowed_extensions_json=?, compatibility_rules_json=?, package_version_min=?, package_version_max=?, licensee_version_min=?, licensee_version_max=?, confidence_policy=?, notes=?, is_active=1 WHERE id=?');
        $stmt->execute([$name, $engine, gprof_json_extensions($extensions), $rulesJson, $versionMin === '' ? null : (int)$versionMin, $versionMax === '' ? null : (int)$versionMax, $licenseeMin === '' ? null : (int)$licenseeMin, $licenseeMax === '' ? null : (int)$licenseeMax, $policy, $notes ?: null, $profileId]);
        return $profileId;
    }

    $stmt = $db->prepare('INSERT INTO ue_game_profiles(profile_name,game_id,engine_key,allowed_extensions_json,compatibility_rules_json,package_version_min,package_version_max,licensee_version_min,licensee_version_max,confidence_policy,notes,is_active) VALUES(?,NULL,?,?,?,?,?,?,?,?,?,1)');
    $stmt->execute([$name, $engine, gprof_json_extensions($extensions), $rulesJson, $versionMin === '' ? null : (int)$versionMin, $versionMax === '' ? null : (int)$versionMax, $licenseeMin === '' ? null : (int)$licenseeMin, $licenseeMax === '' ? null : (int)$licenseeMax, $policy, $notes ?: null]);
    return (int)$db->lastInsertId();
}

function gprof_delete(PDO $db, int $profileId): void
{
    $profile = catalog_one($db, 'SELECT id, profile_name, engine_key FROM ue_game_profiles WHERE id=?', [$profileId]);
    if (!$profile) {
        throw new RuntimeException('Profile not found.');
    }

    $games = catalog_all($db, 'SELECT name FROM ue_games WHERE profile_id=? ORDER BY name', [$profileId]);
    if ($games) {
        $names = implode(', ', array_map(static fn($g) => (string)$g['name'], $games));
        throw new RuntimeException('This game profile is in use by: ' . $names . '. Remove or change the profile on those game(s) first, then delete it.');
    }

    $db->prepare('DELETE FROM ue_game_profiles WHERE id=?')->execute([$profileId]);
}

function gprof_form(?array $profile, string $mode): void
{
    $isEdit = $mode === 'edit';
    $title = $isEdit ? 'Edit game profile: ' . gp_profile_display_name($profile ?? []) : 'Add new game profile';
    $button = $isEdit ? 'Update' : 'Add';
    $policy = (string)($profile['confidence_policy'] ?? 'normal');
    $rulesExample = "[\n  {\n    \"detected_engine\": \"UE1\",\n    \"reader_engine\": \"UE1\",\n    \"extensions\": [\"utx\"],\n    \"package_version_min\": 40,\n    \"package_version_max\": 99,\n    \"label\": \"Legacy UE1 texture package\"\n  }\n]";

    echo '<div class="card"><h2>' . catalog_h($title) . '</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('game_profiles')) . '"><input type="hidden" name="action" value="' . ($isEdit ? 'update' : 'add') . '"><input type="hidden" name="profile_id" value="' . (int)($profile['profile_id'] ?? $profile['id'] ?? 0) . '"><table>';
    echo '<tr><th>Profile name</th><td><input name="profile_name" required value="' . catalog_h($profile['profile_name'] ?? '') . '" style="min-width:420px" placeholder="UT99 / UE1 packages"></td></tr>';
    echo '<tr><th>Engine key</th><td><input name="engine_key" required value="' . catalog_h($profile['profile_engine'] ?? $profile['engine_key'] ?? 'UE1') . '" style="width:120px"> <span class="muted">Examples: UE1, UE2, UE3, UE4, UE5</span></td></tr>';
    echo '<tr><th>Extensions</th><td><input name="extensions" value="' . catalog_h(gprof_extension_text($profile['allowed_extensions_json'] ?? null)) . '" style="min-width:520px" placeholder="u, unr, utx, umx, uax"></td></tr>';
    echo '<tr><th>Legacy compatibility rules</th><td><textarea name="compatibility_rules_json" rows="12" class="mono" style="width:100%" placeholder="' . catalog_h($rulesExample) . '">' . catalog_h(gprof_compatibility_rules_text($profile['compatibility_rules_json'] ?? null)) . '</textarea><p class="muted small">Optional and explicit. These allow a profile to accept a proven older package format, store it in the selected game, and parse it using its detected-engine reader. Do not add broad lower-engine rules.</p></td></tr>';
    echo '<tr><th>Package version min/max</th><td><input name="package_version_min" value="' . catalog_h((string)($profile['package_version_min'] ?? '')) . '" style="width:90px"> <input name="package_version_max" value="' . catalog_h((string)($profile['package_version_max'] ?? '')) . '" style="width:90px"></td></tr>';
    echo '<tr><th>Licensee version min/max</th><td><input name="licensee_version_min" value="' . catalog_h((string)($profile['licensee_version_min'] ?? '')) . '" style="width:90px"> <input name="licensee_version_max" value="' . catalog_h((string)($profile['licensee_version_max'] ?? '')) . '" style="width:90px"></td></tr>';
    echo '<tr><th>Confidence policy</th><td><select name="confidence_policy">';
    foreach (['strict','normal','loose'] as $p) {
        echo '<option value="' . catalog_h($p) . '"' . ($policy === $p ? ' selected' : '') . '>' . catalog_h($p) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th>Notes</th><td><textarea name="notes" rows="4" style="width:100%">' . catalog_h($profile['notes'] ?? '') . '</textarea></td></tr>';
    echo '</table><p><button class="button">' . catalog_h($button) . '</button> <a class="button" href="game-profiles.php">Cancel</a></p></form></div>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Game Profiles')) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
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
                    trim((string)($_POST['profile_name'] ?? '')),
                    strtoupper(trim((string)($_POST['engine_key'] ?? ''))),
                    (string)($_POST['extensions'] ?? ''),
                    (string)($_POST['compatibility_rules_json'] ?? ''),
                    trim((string)($_POST['package_version_min'] ?? '')),
                    trim((string)($_POST['package_version_max'] ?? '')),
                    trim((string)($_POST['licensee_version_min'] ?? '')),
                    trim((string)($_POST['licensee_version_max'] ?? '')),
                    in_array((string)($_POST['confidence_policy'] ?? 'normal'), ['strict','normal','loose'], true) ? (string)$_POST['confidence_policy'] : 'normal',
                    trim((string)($_POST['notes'] ?? ''))
                );
                $_SESSION['game_profiles_flash'] = $action === 'add' ? 'Game profile added.' : 'Game profile updated.';
                header('Location: ' . ($action === 'add' ? 'game-profiles.php' : 'game-profiles.php?profile_id=' . $savedId . '&mode=edit'));
                exit;
            }
        } catch (Throwable $e) {
            $_SESSION['game_profiles_flash'] = $e->getMessage();
            header('Location: game-profiles.php');
            exit;
        }
    }

    catalog_head('Game Profiles');
    catalog_flash($_SESSION['game_profiles_flash'] ?? null);
    unset($_SESSION['game_profiles_flash']);

    $profiles = catalog_all($db, 'SELECT p.id profile_id, p.profile_name, p.engine_key profile_engine, p.allowed_extensions_json, p.compatibility_rules_json, p.package_version_min, p.package_version_max, p.licensee_version_min, p.licensee_version_max, p.confidence_policy, p.notes, COUNT(g.id) assigned_games FROM ue_game_profiles p LEFT JOIN ue_games g ON g.profile_id=p.id GROUP BY p.id ORDER BY COALESCE(p.profile_name, p.engine_key), p.id');
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

    catalog_page_header('Game Profiles', 'Create and maintain reusable scanner profiles here. Game Admin assigns one of these profiles to each game.', ['Game Admin' => 'game-manager.php', 'Upload Files' => 'profiled-upload.php', 'Library' => 'library.php']);

    echo '<div class="card"><h2>Game profiles</h2>';
    if (!$profiles) {
        echo '<p class="muted">No game profiles configured yet.</p>';
    } else {
        echo '<table><tr><th>Profile</th><th>Engine</th><th>Extensions</th><th>Legacy rules</th><th>Version</th><th>Licensee</th><th>Policy</th><th>Assigned games</th><th>Actions</th></tr>';
        foreach ($profiles as $profile) {
            $range = ($profile['package_version_min'] !== null || $profile['package_version_max'] !== null) ? (($profile['package_version_min'] ?? '?') . ' - ' . ($profile['package_version_max'] ?? '?')) : 'not fixed';
            $licensee = ($profile['licensee_version_min'] !== null || $profile['licensee_version_max'] !== null) ? (($profile['licensee_version_min'] ?? '?') . ' - ' . ($profile['licensee_version_max'] ?? '?')) : 'not fixed';
            $engine = $profile['profile_engine'] ?: 'missing profile';
            $engineClass = $profile['profile_engine'] ? 'good-pill' : 'bad-pill';
            $ruleCount = count(compat_rules($profile));
            echo '<tr><td>' . catalog_h(gp_profile_display_name($profile)) . '</td><td><span class="pill ' . $engineClass . '">' . catalog_h($engine) . '</span></td><td class="mono">' . catalog_h(gprof_extension_text($profile['allowed_extensions_json'] ?? null)) . '</td><td>' . ($ruleCount ? '<span class="pill amber">' . $ruleCount . ' configured</span>' : '<span class="muted">none</span>') . '</td><td class="mono">' . catalog_h($range) . '</td><td class="mono">' . catalog_h($licensee) . '</td><td>' . catalog_h($profile['confidence_policy'] ?? '') . '</td><td>' . (int)$profile['assigned_games'] . '</td><td><a class="button" href="game-profiles.php?profile_id=' . (int)$profile['profile_id'] . '&mode=edit">Edit</a> <form method="post" style="display:inline" onsubmit="return confirm(\'Delete this game profile?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('game_profiles')) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="profile_id" value="' . (int)$profile['profile_id'] . '"><button class="button">Delete</button></form></td></tr>';
        }
        echo '</table>';
    }
    echo '<p><a class="button" href="game-profiles.php?mode=new">New</a></p></div>';

    if ($mode === 'new') {
        gprof_form(null, 'new');
    } elseif ($mode === 'edit') {
        if (!$edit) {
            echo '<div class="card"><h2>Profile not found</h2><p class="muted">The selected profile could not be found.</p></div>';
        } else {
            gprof_form($edit, 'edit');
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
