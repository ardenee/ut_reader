<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogScanner.php';
require_once __DIR__ . '/lib/CatalogPakArchive.php';

function pak_import_short_error(Throwable $error): string
{
    $message = trim($error->getMessage());
    $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
    $message = preg_split('/\s+File:\s+|\s+Trace:\s+/', $message)[0] ?? $message;
    $message = trim($message);
    return $message !== '' ? $message : 'Unknown error';
}

function pak_import_tmp_upload_path(array $file): string
{
    $tmp = (string)($file['tmp_name'] ?? '');
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('PAK upload failed with PHP upload error ' . $err . '.');
    }
    if ($tmp === '' || !is_file($tmp)) {
        throw new RuntimeException('Uploaded PAK temp file is missing.');
    }
    return $tmp;
}

function pak_import_source_from_request(): array
{
    $localPath = trim((string)($_POST['local_pak_path'] ?? ''));
    if ($localPath !== '') {
        if (!is_file($localPath) || !is_readable($localPath)) {
            throw new RuntimeException('Local PAK path is not readable: ' . $localPath);
        }
        return ['path' => $localPath, 'name' => basename($localPath), 'uploaded' => false];
    }

    $file = $_FILES['pak_file'] ?? null;
    if (!is_array($file)) {
        throw new RuntimeException('Choose a .pak file or enter a readable local .pak path.');
    }

    return ['path' => pak_import_tmp_upload_path($file), 'name' => (string)($file['name'] ?? 'upload.pak'), 'uploaded' => true];
}

function pak_import_allowed_inner_extensions(PDO $db, array $config, int $gameId): array
{
    $profile = gp_required_profile_for_game($db, $gameId);
    return scanner_profile_extensions($profile, $config);
}

function pak_import_is_scannable_inner_file(array $file, array $allowedExtensions): bool
{
    $name = catalog_clean_unreal_filename(basename((string)$file['relative']));
    $ext = catalog_clean_unreal_extension((string)pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ['uexp', 'ubulk', 'uptnl'], true)) {
        return false;
    }
    return $ext !== '' && in_array($ext, $allowedExtensions, true);
}

function pak_import_relative_display(array $file): string
{
    $relative = trim(str_replace('\\', '/', (string)$file['relative']), '/');
    return $relative !== '' ? $relative : basename((string)$file['path']);
}

function pak_import_scan_extracted_file(PDO $db, array $config, int $gameId, array $file, bool $strictProfile, ?int $userId): array
{
    $path = (string)$file['path'];
    $display = pak_import_relative_display($file);
    $name = catalog_clean_unreal_filename(basename($display));
    $result = scanner_scan_uploaded_file(
        $db,
        $config,
        $gameId,
        $path,
        $name,
        $userId,
        $strictProfile,
        null,
        false,
        ['source_relative_path' => $display]
    );
    return [$display, $result];
}

function pak_import_handle_request(PDO $db, array $config): array
{
    catalog_check_csrf('pak-import');

    $gameId = (int)($_POST['game_id'] ?? 0);
    $strictProfile = (string)($_POST['strict_profile'] ?? '1') === '1';
    if ($gameId <= 0) {
        throw new RuntimeException('Choose a target game.');
    }
    $game = catalog_one($db, 'SELECT id, name, slug FROM ue_games WHERE id=?', [$gameId]);
    if (!$game) {
        throw new RuntimeException('Target game not found.');
    }

    $source = pak_import_source_from_request();
    if (!catalog_pak_archive_is_supported_filename((string)$source['name'])) {
        throw new RuntimeException('Selected file is not a .pak file: ' . basename((string)$source['name']));
    }

    $extracted = catalog_pak_archive_extract_to_temp($config, (string)$source['path'], (string)$source['name']);
    $allowed = pak_import_allowed_inner_extensions($db, $config, $gameId);
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    $found = count($extracted['files']);
    $scannable = 0;
    $imported = 0;
    $duplicates = 0;
    $aliases = 0;
    $failed = 0;
    $skipped = 0;
    $samples = [];

    try {
        foreach ($extracted['files'] as $file) {
            $display = pak_import_relative_display($file);
            if (!pak_import_is_scannable_inner_file($file, $allowed)) {
                $skipped++;
                continue;
            }

            $scannable++;
            try {
                [$display, $result] = pak_import_scan_extracted_file($db, $config, $gameId, $file, $strictProfile, $userId);
                $status = (string)($result[0] ?? 'verified');
                if ($status === 'duplicate') {
                    $duplicates++;
                } elseif ($status === 'alias') {
                    $aliases++;
                } else {
                    $imported++;
                }
                if (count($samples) < 100) {
                    $samples[] = [$status, $display, (string)($result[2] ?? '')];
                }
            } catch (Throwable $error) {
                $failed++;
                if (count($samples) < 100) {
                    $samples[] = ['failed', $display, pak_import_short_error($error)];
                }
                if (is_file((string)$file['path'])) {
                    @unlink((string)$file['path']);
                }
            }
        }
    } finally {
        catalog_pak_archive_delete_tree((string)$extracted['dir']);
        if (!empty($source['uploaded']) && is_file((string)$source['path'])) {
            @unlink((string)$source['path']);
        }
    }

    return [
        'game' => $game,
        'source_name' => (string)$source['name'],
        'extract_log' => (string)($extracted['log'] ?? ''),
        'found' => $found,
        'scannable' => $scannable,
        'imported' => $imported,
        'duplicates' => $duplicates,
        'aliases' => $aliases,
        'failed' => $failed,
        'skipped' => $skipped,
        'samples' => $samples,
    ];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('PAK Import')) {
        exit;
    }

    $result = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = pak_import_handle_request($db, $config);
    }

    $games = catalog_all(
        $db,
        'SELECT g.id, g.name, p.engine_key profile_engine, p.allowed_extensions_json'
        . ' FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
        . ' ORDER BY g.name'
    );

    $selectedGameId = (int)($_POST['game_id'] ?? $_GET['game_id'] ?? 0);

    catalog_head('PAK Import');
    catalog_page_header(
        'PAK Import',
        'Extract a UE4 .pak using the built-in PHP parser, then import supported package files from the extracted contents. Inner PAK paths are preserved as UE4 package identity context; the .pak wrapper is not retained.',
        ['Upload Files' => 'profiled-upload.php', 'Local Source Scan' => 'source-scan.php', 'Upload Bucket' => 'upload-bucket.php']
    );

    echo CatalogUi::alert(
        'info',
        'Standalone PHP extractor enabled.',
        'Current support is intentionally basic: unencrypted PAK indexes, unencrypted entries, uncompressed entries, and zlib-compressed blocks where PHP can decode them. Encrypted/Oodle/IOStore containers will be rejected or skipped.'
    );

    if (is_array($result)) {
        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Import result</h2><p class="muted">' . catalog_h((string)$result['source_name']) . ' → ' . catalog_h((string)$result['game']['name']) . '</p></div></div><div class="ui-section__body">';
        echo '<table><tr><th>Extracted files</th><td>' . (int)$result['found'] . '</td></tr>';
        echo '<tr><th>Scannable package files</th><td>' . (int)$result['scannable'] . '</td></tr>';
        echo '<tr><th>Imported</th><td>' . (int)$result['imported'] . '</td></tr>';
        echo '<tr><th>Aliases</th><td>' . (int)$result['aliases'] . '</td></tr>';
        echo '<tr><th>Duplicates</th><td>' . (int)$result['duplicates'] . '</td></tr>';
        echo '<tr><th>Failed</th><td>' . (int)$result['failed'] . '</td></tr>';
        echo '<tr><th>Skipped non-package/unsupported sidecar files</th><td>' . (int)$result['skipped'] . '</td></tr></table>';
        if ((string)$result['extract_log'] !== '') {
            echo '<h3>Extractor log</h3><pre class="mono path">' . catalog_h((string)$result['extract_log']) . '</pre>';
        }
        if ($result['samples']) {
            echo '<h3>Samples</h3><table><tr><th>Status</th><th>Path</th><th>Message</th></tr>';
            foreach ($result['samples'] as $sample) {
                echo '<tr><td><span class="pill">' . catalog_h((string)$sample[0]) . '</span></td><td class="mono path">' . catalog_h((string)$sample[1]) . '</td><td class="mono path">' . catalog_h((string)$sample[2]) . '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div></section>';
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Extract and import</h2><p>Use either an uploaded .pak or a readable local server path. For large PAKs, a local path is recommended.</p></div></div><div class="ui-section__body">';
    echo '<form method="post" enctype="multipart/form-data" data-ui-loading-form><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('pak-import')) . '">';
    echo '<p><label>Target game<br><select name="game_id" required><option value="">Choose target game</option>';
    foreach ($games as $game) {
        $selected = (int)$game['id'] === $selectedGameId ? ' selected' : '';
        $label = (string)$game['name'] . ' / ' . ((string)($game['profile_engine'] ?: 'no profile'));
        echo '<option value="' . (int)$game['id'] . '"' . $selected . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>Profile mismatch handling<br><select name="strict_profile"><option value="1" selected>Strict: reject/move mismatches to unverified</option><option value="0">Loose: allow scanner/parser to try anyway</option></select></label></p>';
    echo '<p><label>Upload .pak<br><input type="file" name="pak_file" accept=".pak"></label></p>';
    echo '<p><label>Or local .pak path on this server<br><input type="text" name="local_pak_path" placeholder="D:\\Games\\Example\\Content\\Paks\\Example-WindowsNoEditor.pak" style="width:min(100%,760px)"></label></p>';
    echo '<p><button type="submit">Extract PAK and import contents</button></p>';
    echo '<p class="muted">The built-in extractor keeps extracted sidecar files beside their .uasset while scanning, but .uexp/.ubulk are not imported as standalone catalog rows. Inner paths are preserved for UE4 package identity. Limits are controlled by config.php pak.max_extracted_files and pak.max_extracted_bytes.</p>';
    echo '</form></div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Optional configuration</h2></div></div><div class="ui-section__body"><pre class="mono">' . catalog_h("'pak' => [\n    'max_extracted_files' => 20000,\n    'max_extracted_bytes' => 8 * 1024 * 1024 * 1024,\n],") . '</pre></div></section>';

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('PAK Import Error');
    }
    echo CatalogUi::alert('danger', pak_import_short_error($error), 'PAK import failed.');
    catalog_foot();
}
