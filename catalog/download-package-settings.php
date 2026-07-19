<?php
declare(strict_types=1);


require_once __DIR__ . '/lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/lib/FederationAuth.php';
require_once __DIR__ . '/lib/ModPackageBuilder.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Package Export Settings')) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('package_export_settings');

        $booleanKeys = [
            'package_export_enabled',
            'package_export_dependency_zip_enabled',
            'package_export_umod_enabled',
            'package_export_ut3_zip_enabled',
            'package_export_ut4_pak_enabled',
            'package_export_include_transitive',
            'package_export_allow_incomplete',
        ];
        foreach ($booleanKeys as $key) {
            fed_set_setting($db, $key, isset($_POST[$key]) ? '1' : '0');
        }

        $maxFiles = max(1, min(10000, (int)($_POST['package_export_max_files'] ?? 1000)));
        $maxBytesMb = max(1, min(102400, (int)($_POST['package_export_max_bytes_mb'] ?? 2048)));
        $author = trim((string)($_POST['package_export_default_author'] ?? 'UnrealDB')) ?: 'UnrealDB';
        $mountPoint = modpkg_normalize_mount_point((string)($_POST['package_export_ut4_mount_point'] ?? '../../../UnrealTournament/Content/'));

        fed_set_setting($db, 'package_export_max_files', (string)$maxFiles);
        fed_set_setting($db, 'package_export_max_bytes_mb', (string)$maxBytesMb);
        fed_set_setting($db, 'package_export_default_author', $author);
        fed_set_setting($db, 'package_export_ut4_mount_point', $mountPoint);
        fed_set_setting($db, 'package_export_ut4_pak_version', '3');

        $games = catalog_all($db, 'SELECT id FROM ue_games ORDER BY id');
        $gameFormats = [];
        $allowed = ['auto', MODPKG_FORMAT_DEPENDENCY_ZIP, MODPKG_FORMAT_UMOD, MODPKG_FORMAT_UT2MOD, MODPKG_FORMAT_UT4MOD, MODPKG_FORMAT_UT3_ZIP, MODPKG_FORMAT_UT4_PAK];
        foreach ($games as $game) {
            $value = strtolower(trim((string)($_POST['game_format_' . (int)$game['id']] ?? 'auto')));
            $gameFormats[(string)(int)$game['id']] = in_array($value, $allowed, true) ? $value : 'auto';
        }
        fed_set_setting($db, 'package_export_game_formats_json', modpkg_json($gameFormats));

        $_SESSION['package_export_flash'] = 'Package export settings saved.';
        header('Location: download-package-settings.php');
        exit;
    }

    $settings = modpkg_settings($db);
    $games = catalog_all(
        $db,
        'SELECT g.id, g.name, g.slug, p.profile_name, p.engine_key
         FROM ue_games g
         LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
         ORDER BY g.name'
    );
    $labels = modpkg_format_labels();
    unset($labels[MODPKG_FORMAT_DISABLED]);

    catalog_head('Package Export Settings');
    catalog_page_header(
        'Package Export Settings',
        'Configure dependency-complete downloads and native Unreal Tournament package formats.',
        ['Downloads' => 'download-admin.php', 'Base Game Protection' => 'base-game-files.php']
    );

    if (isset($_SESSION['package_export_flash'])) {
        catalog_flash((string)$_SESSION['package_export_flash']);
        unset($_SESSION['package_export_flash']);
    }

    echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('package_export_settings')) . '">';
    echo '<div class="card"><h2>Package generation</h2><table>';
    foreach ([
        'package_export_enabled' => ['enabled', 'Enable generated package downloads'],
        'package_export_dependency_zip_enabled' => ['dependency_zip_enabled', 'Enable dependency ZIP downloads'],
        'package_export_umod_enabled' => ['umod_enabled', 'Enable UMOD-family downloads (.umod/.ut2mod/.ut4mod)'],
        'package_export_ut3_zip_enabled' => ['ut3_zip_enabled', 'Enable UT3 structured ZIP downloads'],
        'package_export_ut4_pak_enabled' => ['ut4_pak_enabled', 'Enable UT4 unencrypted PAK downloads'],
        'package_export_include_transitive' => ['include_transitive', 'Follow dependencies transitively'],
        'package_export_allow_incomplete' => ['allow_incomplete', 'Allow users to explicitly export packages with missing/package-only dependencies'],
    ] as $postKey => [$settingKey, $label]) {
        echo '<tr><th>' . catalog_h($label) . '</th><td><label><input type="checkbox" name="' . catalog_h($postKey) . '" value="1"' . (!empty($settings[$settingKey]) ? ' checked' : '') . '> enabled</label></td></tr>';
    }
    echo '<tr><th>Maximum files per package</th><td><input type="number" min="1" max="10000" name="package_export_max_files" value="' . (int)$settings['max_files'] . '"></td></tr>';
    echo '<tr><th>Maximum package payload</th><td><input type="number" min="1" max="102400" name="package_export_max_bytes_mb" value="' . (int)round((int)$settings['max_bytes'] / 1024 / 1024) . '"> MB</td></tr>';
    echo '<tr><th>Default author</th><td><input name="package_export_default_author" value="' . catalog_h($settings['default_author']) . '" style="min-width:360px"></td></tr>';
    echo '<tr><th>UT4 PAK mount point</th><td><input name="package_export_ut4_mount_point" value="' . catalog_h($settings['ut4_mount_point']) . '" style="min-width:520px"><br><span class="muted small">PAK version 3, uncompressed and unencrypted.</span></td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Per-game default format</h2><p class="muted">Automatic selection uses the assigned engine profile and game name. Override only when a custom game entry cannot be identified reliably. Use the global format controls above to disable an exporter.</p>';
    echo '<table><tr><th>Game</th><th>Profile</th><th>Engine</th><th>Automatic result</th><th>Configured default</th></tr>';
    foreach ($games as $game) {
        $auto = modpkg_inferred_format($game);
        $selected = (string)($settings['game_formats'][(string)(int)$game['id']] ?? 'auto');
        if (!isset($labels[$selected]) && $selected !== 'auto') {
            $selected = 'auto';
        }
        echo '<tr><td>' . catalog_h($game['name']) . '<br><span class="mono small">' . catalog_h($game['slug']) . '</span></td><td>' . catalog_h($game['profile_name']) . '</td><td class="mono">' . catalog_h($game['engine_key']) . '</td><td>' . catalog_h($labels[$auto] ?? $auto) . '</td><td><select name="game_format_' . (int)$game['id'] . '">';
        echo '<option value="auto"' . ($selected === 'auto' ? ' selected' : '') . '>Automatic</option>';
        foreach ($labels as $value => $label) {
            echo '<option value="' . catalog_h($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
        }
        echo '</select></td></tr>';
    }
    echo '</table></div><p><button class="primary">Save package export settings</button></p></form>';

    echo '<div class="card"><h2>Output rules</h2><table>';
    echo '<tr><th>UT99</th><td>.umod</td></tr>';
    echo '<tr><th>UT2003</th><td>.ut2mod</td></tr>';
    echo '<tr><th>UT2004</th><td>.ut4mod</td></tr>';
    echo '<tr><th>UT3 PC</th><td>ZIP containing the UTGame folder structure</td></tr>';
    echo '<tr><th>UT4</th><td>Uncompressed, unencrypted PAK using the configured Content mount point</td></tr>';
    echo '</table></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Package export settings error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
