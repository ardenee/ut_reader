<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and processes reusable Game Profiles administration.
 * Why: Profile validation, persistence, deletion policy and assigned-game reads now belong to a shared service.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Games\CatalogGameProfileAdminService;

catalog_start_session();

function gprof_extension_text(?string $json): string
{
    $exts = json_decode((string)($json ?? '[]'), true);
    return is_array($exts) ? implode(', ', $exts) : '';
}

function gprof_compatibility_rules_text(?string $json): string
{
    $rules = json_decode((string)($json ?? '[]'), true);
    if (!is_array($rules) || !$rules) {
        return '';
    }
    return json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
}

function gprof_form(?array $profile, string $mode): void
{
    $isEdit = $mode === 'edit';
    $title = $isEdit ? 'Edit game profile: ' . gp_profile_display_name($profile ?? []) : 'Add new game profile';
    $button = $isEdit ? 'Update' : 'Add';
    $policy = (string)($profile['confidence_policy'] ?? 'normal');
    $rulesExample = "[\n  {\n    \"detected_engine\": \"UE1\",\n    \"reader_engine\": \"UE1\",\n    \"package_version_min\": 40,\n    \"package_version_max\": 99,\n    \"licensee_version_min\": 0,\n    \"licensee_version_max\": 0,\n    \"label\": \"Header-verified UE1 compatibility\"\n  }\n]";

    echo '<div class="card"><h2>' . catalog_h($title) . '</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('game_profiles')) . '"><input type="hidden" name="action" value="' . ($isEdit ? 'update' : 'add') . '"><input type="hidden" name="profile_id" value="' . (int)($profile['profile_id'] ?? $profile['id'] ?? 0) . '"><table>';
    echo '<tr><th>Profile name</th><td><input name="profile_name" required value="' . catalog_h($profile['profile_name'] ?? '') . '" style="min-width:420px" placeholder="UT99 / UE1 packages"></td></tr>';
    echo '<tr><th>Engine key</th><td><input name="engine_key" required value="' . catalog_h($profile['profile_engine'] ?? $profile['engine_key'] ?? 'UE1') . '" style="width:120px"> <span class="muted">Examples: UE1, UE2, UE3, UE4, UE5</span></td></tr>';
    echo '<tr><th>Discovery extensions</th><td><input name="extensions" value="' . catalog_h(gprof_extension_text($profile['allowed_extensions_json'] ?? null)) . '" style="min-width:520px" placeholder="u, unr, utx, umx, uax"><p class="muted small">Used only to narrow source/file discovery. Extensions never select a reader and never prove package compatibility.</p></td></tr>';
    echo '<tr><th>Header compatibility rules</th><td><textarea name="compatibility_rules_json" rows="12" class="mono" style="width:100%" placeholder="' . catalog_h($rulesExample) . '">' . catalog_h(gprof_compatibility_rules_text($profile['compatibility_rules_json'] ?? null)) . '</textarea><p class="muted small">Optional and explicit. Rules may use only serialized header facts: detected engine family, package version and licensee version. Filename/extension fields are ignored and removed when saving.</p></td></tr>';
    echo '<tr><th>Package version min/max</th><td><input name="package_version_min" value="' . catalog_h((string)($profile['package_version_min'] ?? '')) . '" style="width:90px"> <input name="package_version_max" value="' . catalog_h((string)($profile['package_version_max'] ?? '')) . '" style="width:90px"></td></tr>';
    echo '<tr><th>Licensee version min/max</th><td><input name="licensee_version_min" value="' . catalog_h((string)($profile['licensee_version_min'] ?? '')) . '" style="width:90px"> <input name="licensee_version_max" value="' . catalog_h((string)($profile['licensee_version_max'] ?? '')) . '" style="width:90px"></td></tr>';
    echo '<tr><th>Confidence policy</th><td><select name="confidence_policy">';
    foreach (['strict', 'normal', 'loose'] as $value) {
        echo '<option value="' . catalog_h($value) . '"' . ($policy === $value ? ' selected' : '') . '>' . catalog_h($value) . '</option>';
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

    $service = new CatalogGameProfileAdminService($db);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            catalog_check_csrf('game_profiles');
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'delete') {
                $service->delete((int)($_POST['profile_id'] ?? 0));
                $_SESSION['game_profiles_flash'] = 'Game profile deleted.';
                header('Location: game-profiles.php');
                exit;
            }
            if ($action === 'add' || $action === 'update') {
                $savedId = $service->save($action, $_POST);
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

    $profiles = $service->profiles();
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

    catalog_page_header('Game Profiles', 'Create and maintain reusable scanner profiles here. Reader selection is based on serialized package data; discovery extensions are non-authoritative hints only.', ['Game Admin' => 'game-manager.php', 'Upload Files' => 'profiled-upload.php', 'Library' => 'library.php']);

    echo '<div class="card"><h2>Game profiles</h2>';
    if (!$profiles) {
        echo '<p class="muted">No game profiles configured yet.</p>';
    } else {
        echo '<table><tr><th>Profile</th><th>Engine</th><th>Discovery extensions</th><th>Header rules</th><th>Version</th><th>Licensee</th><th>Policy</th><th>Assigned games</th><th>Actions</th></tr>';
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
