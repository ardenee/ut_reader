<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';

function docs_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_head('Federation Docs');

    if (!docs_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="../index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    $siteUrl = rtrim((string)fed_setting($db, 'site_url', ''), '/');
    $token = (string)fed_setting($db, 'cron_worker_token', '');
    $cronUrl = ($siteUrl !== '' ? $siteUrl : 'https://YOUR-SITE/catalog') . '/federation/cron-worker.php?token=' . ($token !== '' ? $token : 'YOUR-LONG-RANDOM-TOKEN');

    echo '<div class="card"><h1>Federation DSM / Cron Setup</h1><p><a class="button" href="admin.php">Federation admin</a> <a class="button" href="settings.php">Settings</a> <a class="button" href="worker-run.php">Bulk worker</a> <a class="button" href="maintenance.php">Maintenance</a></p></div>';

    echo '<div class="card"><h2>1. Enable the cron worker</h2><p>Open <a href="settings.php">Federation Settings</a> and set:</p><pre class="mono">cron_worker_enabled = 1
cron_worker_token = a long random value
max_files_per_transfer_run = 1 or more</pre><p class="muted">Keep the token private. Anyone with this token can run the federation worker.</p></div>';

    echo '<div class="card"><h2>2. Test with curl</h2><pre class="mono">curl -s ' . catalog_h(escapeshellarg($cronUrl)) . '</pre><p>Expected JSON includes:</p><pre class="mono">"ok": true
"transfers": [...]
"imports": [...]</pre></div>';

    echo '<div class="card"><h2>3. Synology DSM Task Scheduler</h2><p>DSM Control Panel → Task Scheduler → Create → Scheduled Task → User-defined script.</p><pre class="mono">/usr/bin/curl -s ' . catalog_h(escapeshellarg($cronUrl)) . ' >> /volume1/web/ut_reader/catalog/storage/federation/cron-worker.log 2>&1</pre><p class="muted">Run every few minutes if you want slow continuous federation transfers. Keep max_files_per_transfer_run low when using large files.</p></div>';

    echo '<div class="card"><h2>4. Recommended safe settings</h2><pre class="mono">max_files_per_transfer_run = 1
max_download_kbps = 0 or a safe limit
max_upload_kbps = 0 or a safe limit
delay_between_downloads_seconds = 5
delay_between_uploads_seconds = 5
max_transfer_file_size_mb = 1024
log_retention_days = 90</pre></div>';

    echo '<div class="card"><h2>5. Current limitation</h2><p>The current upload path signs and sends the full file body in one request. For very large files, the future upgrade is chunked upload with per-chunk HMAC signatures.</p></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation docs error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
