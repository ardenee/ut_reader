<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
@set_time_limit(0);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/ExternalMirrors.php';
require_once __DIR__ . '/lib/ModPackageBuilder.php';

$tmp = null;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $settings = modpkg_settings($db);
    $mode = external_public_download_mode($db);

    if ($mode === 'disabled') {
        throw new RuntimeException('Public downloads are disabled.');
    }
    if ($mode === 'external_mirror') {
        throw new RuntimeException('Generated package downloads are unavailable in external-mirror-only mode because the local catalog payload is required.');
    }
    if (!$settings['enabled']) {
        throw new RuntimeException('Package exports are disabled.');
    }

    $id = (int)($_GET['id'] ?? 0);
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status<>"failed"', [$id]);
    if (!$file) {
        throw new RuntimeException('File not found.');
    }
    $game = modpkg_game_row($db, (int)$file['game_id']);
    if (!$game) {
        throw new RuntimeException('Game not found.');
    }

    $available = modpkg_available_formats($game, $settings);
    $format = strtolower(trim((string)($_GET['format'] ?? modpkg_default_format($game, $settings))));
    if (!in_array($format, $available, true)) {
        throw new RuntimeException('The selected package format is not available for this game.');
    }

    $includeDependencies = !isset($_GET['dependencies']) || (string)$_GET['dependencies'] !== '0';
    $allowIncomplete = $settings['allow_incomplete'] && (string)($_GET['allow_incomplete'] ?? '0') === '1';
    $plan = modpkg_plan($db, $config, $id, $format, $includeDependencies, $settings);

    if (($plan['missing'] || $plan['package_only']) && !$allowIncomplete) {
        $problems = count($plan['missing']) + count($plan['package_only']);
        throw new RuntimeException(
            'Package generation stopped because ' . $problems . ' dependencies are missing or only matched at package level. '
            . 'Resolve them first' . ($settings['allow_incomplete'] ? ' or explicitly enable incomplete export in the download form.' : '.')
        );
    }

    $options = modpkg_default_options($plan, $settings, [
        'name' => $_GET['name'] ?? null,
        'version' => $_GET['version'] ?? null,
        'author' => $_GET['author'] ?? null,
    ]);

    $tmp = tempnam(sys_get_temp_dir(), 'unrealdb_pkg_');
    if ($tmp === false) {
        throw new RuntimeException('Could not create a temporary package file.');
    }

    $validation = modpkg_build($tmp, $plan, $options, $settings);
    if (empty($validation['ok'])) {
        throw new RuntimeException('Generated package did not pass validation.');
    }

    $downloadName = modpkg_download_name($format, $options);
    $contentType = match (modpkg_extension($format)) {
        'zip' => 'application/zip',
        'pak', 'umod', 'ut2mod', 'ut4mod' => 'application/octet-stream',
        default => 'application/octet-stream',
    };
    $size = filesize($tmp);
    if ($size === false) {
        throw new RuntimeException('Could not determine generated package size.');
    }

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . $size);
    header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, "\\\"") . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
} catch (Throwable $e) {
    if ($tmp !== null && is_file($tmp)) {
        @unlink($tmp);
    }
    if (!headers_sent()) {
        catalog_head('Package download error');
    }
    echo '<div class="card"><h1>Package download error</h1><p>' . catalog_h($e->getMessage()) . '</p><p><a class="button" href="javascript:history.back()">Back</a></p></div>';
    catalog_foot();
}
