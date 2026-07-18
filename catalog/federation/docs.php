<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Federation Docs')) {
        exit;
    }

    catalog_head('Federation Docs');

    $siteUrl = rtrim((string)fed_setting($db, 'site_url', ''), '/');
    $token = (string)fed_setting($db, 'cron_worker_token', '');
    $cronUrl = ($siteUrl !== '' ? $siteUrl : 'https://YOUR-SITE/catalog') . '/federation/cron-worker.php';
    $cronToken = $token !== '' ? $token : 'YOUR-LONG-RANDOM-TOKEN';
    $curlCommand = '/usr/bin/curl -fsS -X POST -H ' . escapeshellarg('X-Federation-Cron-Token: ' . $cronToken) . ' ' . escapeshellarg($cronUrl);

    catalog_page_header('Federation / Mirror Docs', 'Secret encryption, DSM cron, worker, federation transfer, external mirror, and parent/child join workflow notes.', catalog_federation_links() + ['Mirror Settings' => '../mirror-providers.php', 'Mirror Queue' => '../mirror-queue.php']);

    echo '<div class="card"><h2>1. Encrypt federation peer secrets</h2><p>Generate a deployment master key from a trusted shell:</p><pre class="mono">php catalog/bin/generate-federation-master-key.php</pre><p>Store the complete output in the environment as <span class="mono">UNREALDB_FEDERATION_MASTER_KEY</span>. Use the same key for every web and worker process that shares this database.</p><p>Encrypt existing peer rows:</p><pre class="mono">php catalog/bin/encrypt-federation-secrets.php</pre><p>After the command succeeds, enable:</p><pre class="mono">UNREALDB_REQUIRE_ENCRYPTED_FEDERATION_SECRETS=1</pre><p class="muted">Back up the master key separately from the database. Losing it makes existing peer pairings unreadable. A database-only compromise no longer reveals usable peer credentials.</p></div>';

    echo '<div class="card"><h2>2. Enable the cron worker</h2><p>Open <a href="settings.php">Federation Settings</a> and set:</p><pre class="mono">cron_worker_enabled = 1
cron_worker_token = a long random value
max_files_per_transfer_run = 1 or more</pre><p class="muted">Keep the token private. It is sent in an HTTP header and must never be placed in a URL, browser bookmark, or access log.</p></div>';

    echo '<div class="card"><h2>3. Test with curl</h2><pre class="mono">' . catalog_h($curlCommand) . '</pre><p>Expected JSON includes:</p><pre class="mono">"ok": true
"mirror_maintenance": {...}
"transfers": [...]
"imports": [...]</pre></div>';

    echo '<div class="card"><h2>4. Synology DSM Task Scheduler</h2><p>DSM Control Panel → Task Scheduler → Create → Scheduled Task → User-defined script.</p><pre class="mono">' . catalog_h($curlCommand . ' >> /volume1/web/ut_reader/catalog/storage/federation/cron-worker.log 2>&1') . '</pre><p class="muted">Run every few minutes if you want slow continuous federation transfers and mirror cleanup. Keep max_files_per_transfer_run low when using large files.</p></div>';

    echo '<div class="card"><h2>5. Recommended federation settings</h2><pre class="mono">max_files_per_transfer_run = 1
max_download_kbps = 0 or a safe limit
max_upload_kbps = 0 or a safe limit
delay_between_downloads_seconds = 5
delay_between_uploads_seconds = 5
max_transfer_file_size_mb = 1024
log_retention_days = 90</pre></div>';

    echo '<div class="card"><h2>6. Public download / external mirror modes</h2><pre class="mono">public_download_mode = local_direct
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

    echo '<div class="card"><h2>7. Manual mirror workflow</h2><pre class="mono">1. User requests file while external_mirror mode is active.
2. Job appears in /catalog/mirror-queue.php.
3. Admin opens the job.
4. Admin uploads file manually to MEGA / 1fichier / Rapidgator / etc.
5. Admin pastes the external URL into mirror-job.php.
6. System creates active external link.
7. Future public requests reuse the link until external_mirror_expiry_days passes.</pre></div>';

    echo '<div class="card"><h2>8. Parent join workflow</h2><pre class="mono">Child site:
  /catalog/federation/join-main-parent.php
  submits local site identity and polls approval status

Parent site:
  /catalog/federation/join-requests.php
  admin approves request
  endpoint and one-time token are shown once

Child site:
  /catalog/federation/claim-parent.php
  enters the POST endpoint and token
  parent peer is created locally</pre><p class="muted">The claim token is never placed in a URL. Status polling never returns the peer shared secret.</p></div>';

    echo '<div class="card"><h2>9. Current limitations</h2><p>The compatibility protocol still uses symmetric HMAC peer credentials. The planned federation upgrade is asymmetric signing with key rotation and revocation.</p><p>External provider uploads currently support the ManualProvider workflow. Provider-specific APIs can be added later without changing the public download mode logic.</p></div>';

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] federation docs failed: ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Federation docs error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h(catalog_public_error_message()) . '</p></div>';
    catalog_foot();
}
