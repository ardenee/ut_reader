<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for Download blocked.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogPublicAccess.php';
require_once __DIR__ . '/lib/ExternalMirrors.php';
require_once __DIR__ . '/lib/BaseGameProtection.php';
require_once __DIR__ . '/lib/DownloadActivity.php';

use UnrealDb\Catalog\Infrastructure\Storage\LocalFilesystemPackageStorage;

catalog_start_session();

function public_download_storage_path(array $config, array $file): string
{
    $storage = new LocalFilesystemPackageStorage((string)$config['storage_path'], __DIR__);
    return $storage->resolveExisting((string)$file['relative_path']);
}

function public_download_original_name(array $file): string
{
    $name = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], (string)($file['original_name'] ?? '')));
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
    $name = rtrim(trim($name), ' .');
    return $name !== '' && $name !== '.' && $name !== '..'
        ? $name
        : catalog_clean_unreal_filename((string)($file['package_name'] ?? 'package'));
}

function public_download_send_local(array $config, PDO $db, array $file): void
{
    $path = public_download_storage_path($config, $file);
    $downloadName = public_download_original_name($file);
    $fallbackName = catalog_clean_unreal_filename($downloadName);
    $size = filesize($path);
    if ($size === false) {
        throw new RuntimeException('Stored file size is unavailable.');
    }
    $speedBytes = catalog_public_download_speed_bytes($db);
    $auditId = catalog_download_audit_start($db, [
        'download_type' => 'individual_file',
        'file_id' => (int)$file['id'],
        'game_id' => (int)$file['game_id'],
        'user_id' => isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null,
        'ip_address' => catalog_public_access_client_ip(),
        'user_agent' => catalog_download_audit_user_agent(),
        'download_name' => $downloadName,
        'artifact_size' => (int)$size,
        'range_start' => 0,
        'range_end' => max(0, (int)$size - 1),
        'bytes_requested' => (int)$size,
        'status' => 'started',
        'http_status' => 200,
    ]);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
        @apache_setenv('dont-vary', '1');
    }
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) {
            break;
        }
    }
    if (function_exists('header_remove')) {
        header_remove('Content-Encoding');
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $size);
    header('Content-Disposition: attachment; filename="' . addcslashes($fallbackName, "\\\"")
        . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');
    header('Cache-Control: private, no-store, no-transform');
    if ($speedBytes > 0) {
        header('X-UnrealDB-Rate-Limit: ' . $speedBytes . ' bytes/second');
    }
    catalog_download_audit_stream($db, $auditId, $path, 0, (int)$size, $speedBytes);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);
    $id = (int)($_GET['id'] ?? 0);
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="verified"', [$id]);
    if (!$file) {
        throw new RuntimeException('File not found.');
    }

    if (base_game_file_is_protected($db, $file)) {
        catalog_head('Download blocked');
        echo base_game_block_html($file);
        catalog_foot();
        exit;
    }

    $decision = external_public_download_decision($db, $id, $_SESSION['user']['id'] ?? null, catalog_public_access_client_ip());
    if (in_array((string)($decision['type'] ?? ''), ['local_direct', 'external_link'], true)) {
        catalog_public_download_limit($db);
    }
    if (($decision['type'] ?? '') === 'local_direct') {
        public_download_send_local($config, $db, $file);
    }

    $access = catalog_public_access_settings($db, $config);
    catalog_head('Download');
    echo '<div class="card"><h1>Download</h1><p><strong>' . catalog_h($file['package_name']) . '</strong><br>' . catalog_h(public_download_original_name($file)) . '</p><p class="muted">Public download mode: <span class="mono">' . catalog_h(external_public_download_mode($db)) . '</span></p></div>';
    echo '<div class="card"><h2>Public download restrictions</h2><p>This IP address may download up to <strong>' . (int)$access['public_download_max_files'] . '</strong> individual files per ' . catalog_h(catalog_public_access_window_label((int)$access['public_download_window_seconds'])) . '.</p><p class="muted">Rapid link opening or automated crawling can trigger a temporary ' . (int)$access['public_burst_block_seconds'] . '-second block.';
    if ((int)$access['public_download_speed_kbps'] > 0) {
        echo ' Local transfers are limited to ' . (int)$access['public_download_speed_kbps'] . ' KB/s.';
    }
    echo '</p></div>';

    if (($decision['type'] ?? '') === 'external_link') {
        $link = $decision['link'];
        echo '<div class="card"><h2>External mirror link ready</h2><p class="muted">This file is being served through the configured external/shared provider cache.</p><table>';
        echo '<tr><th>Provider</th><td>' . catalog_h($link['provider_name'] ?? '') . '</td></tr><tr><th>Expires</th><td>' . catalog_h($link['expires_at'] ?? '') . '</td></tr>';
        echo '</table><p><a class="button" href="' . catalog_h($link['external_url']) . '" target="_blank" rel="noopener noreferrer">Open external download</a></p></div>';
    } elseif (($decision['type'] ?? '') === 'pending') {
        echo '<div class="card"><h2>External download is being prepared</h2><p>' . catalog_h($decision['message'] ?? 'External download link is not ready yet.') . '</p>';
        if (!empty($decision['job_id'])) {
            echo '<p class="muted">Mirror queue job ID: <span class="mono">' . (int)$decision['job_id'] . '</span></p>';
        }
        echo '<p class="muted">Check again later. The admin/worker must upload or approve the external mirror link first.</p></div>';
    } elseif (($decision['type'] ?? '') === 'disabled') {
        echo '<div class="card"><h2>Downloads disabled</h2><p>' . catalog_h($decision['message'] ?? 'Public downloads are disabled.') . '</p></div>';
    } else {
        echo '<div class="card"><h2>Download unavailable</h2><p>Unknown public download decision.</p></div>';
    }

    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        echo '<div class="card"><h2>Admin mirror actions</h2><p><a class="button" href="mirror-links.php?file_id=' . (int)$file['id'] . '">Add/view mirror links</a> <a class="button" href="mirror-queue.php">Mirror queue</a> <a class="button" href="mirror-providers.php">Mirror providers/settings</a></p></div>';
    }
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] public download failed: ' . get_class($error) . ': ' . $error->getMessage());
    $status = http_response_code();
    if (!headers_sent()) {
        if ($status < 400) {
            http_response_code(503);
        }
        catalog_head('Download error');
    }
    $publicMessage = $status === 429 ? $error->getMessage() : catalog_public_error_message();
    echo '<div class="card"><h1>Download unavailable</h1><p>' . catalog_h($publicMessage) . '</p><p><a class="button" href="index.php">Return to UnrealDB</a></p></div>';
    catalog_foot();
}
