<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/ExternalMirrors.php';
require_once __DIR__ . '/lib/BaseGameProtection.php';

function public_download_storage_path(array $config, array $file): string
{
    $path = realpath(__DIR__ . '/' . (string)$file['relative_path']);
    $root = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    if (!$path || !$root || !str_starts_with($path, $root) || !is_file($path)) throw new RuntimeException('Stored file missing');
    return $path;
}

function public_download_send_local(array $config, array $file): void
{
    $path = public_download_storage_path($config, $file);
    $downloadName = catalog_clean_unreal_filename((string)$file['original_name']);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);
    $id = (int)($_GET['id'] ?? 0);
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="verified"', [$id]);
    if (!$file) throw new RuntimeException('File not found');

    if (base_game_file_is_protected($db, $file)) {
        catalog_head('Download blocked');
        echo base_game_block_html($file);
        catalog_foot();
        exit;
    }

    $decision = external_public_download_decision($db, $id, $_SESSION['user']['id'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null);
    if (($decision['type'] ?? '') === 'local_direct') public_download_send_local($config, $file);

    catalog_head('Download');
    echo '<div class="card"><h1>Download</h1><p><strong>' . catalog_h($file['package_name']) . '</strong><br>' . catalog_h(catalog_clean_unreal_filename((string)$file['original_name'])) . '</p><p class="muted">Public download mode: <span class="mono">' . catalog_h(external_public_download_mode($db)) . '</span></p></div>';

    if (($decision['type'] ?? '') === 'external_link') {
        $link = $decision['link'];
        echo '<div class="card"><h2>External mirror link ready</h2><p class="muted">This file is being served through the configured external/shared provider cache.</p><table>';
        echo '<tr><th>Provider</th><td>' . catalog_h($link['provider_name'] ?? '') . '</td></tr><tr><th>Expires</th><td>' . catalog_h($link['expires_at'] ?? '') . '</td></tr>';
        echo '</table><p><a class="button" href="' . catalog_h($link['external_url']) . '" target="_blank" rel="noopener noreferrer">Open external download</a></p></div>';
    } elseif (($decision['type'] ?? '') === 'pending') {
        echo '<div class="card"><h2>External download is being prepared</h2><p>' . catalog_h($decision['message'] ?? 'External download link is not ready yet.') . '</p>';
        if (!empty($decision['job_id'])) echo '<p class="muted">Mirror queue job ID: <span class="mono">' . (int)$decision['job_id'] . '</span></p>';
        echo '<p class="muted">Check again later. The admin/worker must upload or approve the external mirror link first.</p></div>';
    } elseif (($decision['type'] ?? '') === 'disabled') {
        echo '<div class="card"><h2>Downloads disabled</h2><p>' . catalog_h($decision['message'] ?? 'Public downloads are disabled.') . '</p></div>';
    } else {
        echo '<div class="card"><h2>Download unavailable</h2><p>Unknown public download decision.</p></div>';
    }

    if (($_SESSION['user']['role'] ?? '') === 'admin') echo '<div class="card"><h2>Admin mirror actions</h2><p><a class="button" href="mirror-links.php?file_id=' . (int)$file['id'] . '">Add/view mirror links</a> <a class="button" href="mirror-queue.php">Mirror queue</a> <a class="button" href="mirror-providers.php">Mirror providers/settings</a></p></div>';
    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) catalog_head('Download error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
