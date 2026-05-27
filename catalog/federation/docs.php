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

    echo '<div class="card"><h1>Federation / Mirror Docs</h1><p><a class="button" href="admin.php">Federation admin</a> <a class="button" href="settings.php">Settings</a> <a class="button" href="worker-run.php">Bulk worker</a> <a class="button" href="maintenance.php">Maintenance</a> <a class="button" href="../mirror-providers.php">Mirror settings</a> <a class="button" href="../mirror-queue.php">Mirror queue</a></p></div>';

    echo '<div class="card"><h2>1. Enable the cron worker</h2><p>Open <a href="settings.php">Federation Settings</a> and set:</p><pre class="mono">cron_worker_enabled = 1
cron_worker_token = a long random value
max_files_per_transfer_run = 1 or more</pre><p class="muted">Keep the token private. Anyone with this token can run the federation worker.</p></div>';

    echo '<div class="card"><h2>2. Test with curl</h2><pre class="mono">curl -s ' . catalog_h(escapeshellarg($cronUrl)) . '</pre><p>Expected JSON includes:</p><pre class="mono">"ok": true
"mirror_maintenance": {...}
"transfers": [...]
"imports": [...]</pre></div>';

    echo '<div class="card"><h2>3. Synology DSM Task Scheduler</h2><p>DSM Control Panel → Task Scheduler → Create → Scheduled Task → User-defined script.</p><pre class="mono">/usr/bin/curl -s ' . catalog_h(escapeshellarg($cronUrl)) . ' >> /volume1/web/ut_reader/catalog/storage/federation/cron-worker.log 2>&1</pre><p class="muted">Run every few minutes if you want slow continuous federation transfers and mirror cleanup. Keep max_files_per_transfer_run low when using large files.</p></div>';

    echo '<div class="card"><h2>4. Recommended federation settings</h2><pre class="mono">max_files_per_transfer_run = 1
max_download_kbps = 0 or a safe limit
max_upload_kbps = 0 or a safe limit
delay_between_downloads_seconds = 5
delay_between_uploads_seconds = 5
max_transfer_file_size_mb = 1024
log_retention_days = 90</pre></div>';

    echo '<div class="card"><h2>5. Public download / external mirror modes</h2><pre class="mono">public_download_mode = local_direct
  users download directly from this site

public_download_mode = external_mirror
  users only get external provider links
  if no active link exists, a mirror job is queued/pending

public_download_mode = external_mirror_preferred
  use active external link if present
  otherwise fall back to local direct and queue a mirror job

public_download_mode = disabled
  public downloads disabled
  federation transfers still work</pre></div>';

    echo '<div class="card"><h2>6. Manual mirror workflow</h2><pre class="mono">1. User requests file while external_mirror mode is active.
2. Job appears in /catalog/mirror-queue.php.
3. Admin opens the job.
4. Admin uploads file manually to MEGA / 1fichier / Rapidgator / etc.
5. Admin pastes the external URL into mirror-job.php.
6. System creates active external link.
7. Future public requests reuse the link until external_mirror_expiry_days passes.</pre></div>';

    echo '<div class="card"><h2>7. Main parent join workflow</h2><pre class="mono">Child site:
  /catalog/federation/join-main-parent.php
  auto-submits local site identity to main_parent_url

Parent site:
  /catalog/federation/join-requests.php
  admin approves request

Child site:
  poll from join-main-parent.php
  approved pairing is claimed automatically
  parent peer is created locally</pre></div>';

    echo '<div class="card"><h2>8. Current limitations</h2><p>The current upload path signs and sends the full file body in one request. For very large files, the future upgrade is chunked upload with per-chunk HMAC signatures.</p><p>External provider uploads currently support the ManualProvider workflow. Provider-specific APIs can be added later without changing the public download mode logic.</p></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation docs error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
