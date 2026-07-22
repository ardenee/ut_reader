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
    $cronUrl = ($siteUrl !== '' ? $siteUrl : 'https://YOUR-SITE/catalog') . '/federation/cron-worker-streaming.php';
    $cronToken = $token !== '' ? $token : 'YOUR-LONG-RANDOM-TOKEN';
    $curlCommand = '/usr/bin/curl -fsS -X POST -H ' . escapeshellarg('X-Federation-Cron-Token: ' . $cronToken) . ' ' . escapeshellarg($cronUrl);

    catalog_page_header(
        'Federation / Mirror Docs',
        'Parent-master authority, automatic pairing, child dependency approval, direct child inventory, workers, secret encryption, and mirror notes.',
        catalog_federation_links() + ['Mirror Settings' => '../mirror-providers.php', 'Mirror Queue' => '../mirror-queue.php']
    );

    echo '<div class="card"><h2>1. Federation authority model</h2><table>';
    echo '<tr><th>Parent/master</th><td>Source of truth. May read a paired child inventory and download any non-protected file that the parent does not already have. No child approval is required.</td></tr>';
    echo '<tr><th>Child</th><td>May request files only for current missing dependencies. Parent approval is required before download.</td></tr>';
    echo '<tr><th>Approved child downloads</th><td>The child federation worker re-checks that each dependency is still missing, then queues, downloads, and imports the approved file automatically.</td></tr>';
    echo '<tr><th>Files already held by parent</th><td>Not displayed in Child Inventory and cannot be queued through the parent inventory page.</td></tr>';
    echo '<tr><th>Base-game protected files</th><td>Remain blocked from federation transfer.</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>2. Join and automatic pairing</h2><pre class="mono">Child:
  Open /catalog/federation/join-main-parent.php
  Submit the child identity to the chosen parent

Parent:
  Open /catalog/federation/join-requests.php
  Approve or deny the request

Child:
  The page polls the decision automatically
  Approval creates the parent peer automatically
  No claim page, copied endpoint, or copied token is required</pre><p class="muted">The original one-time request token remains stored only on the child and is used automatically to complete the approved pairing. The process is retryable if a status check is interrupted.</p></div>';

    echo '<div class="card"><h2>3. Parent reads child inventory</h2><pre class="mono">Parent:
  Open /catalog/federation/peer-inventory.php
  Select a child
  Click Refresh directly from child

Filters:
  Needed dependency files
  Other missing files
  All files parent lacks</pre><p class="muted">The request is signed by the paired parent. The child does not approve or manually push inventory. Shared files already present on the parent are excluded.</p></div>';

    echo '<div class="card"><h2>4. Parent downloads from a child</h2><pre class="mono">1. Parent refreshes Child Inventory.
2. Parent selects needed or other missing files.
3. Parent queues downloads.
4. Federation worker downloads and imports the files.</pre><p class="muted">A paired parent does not require child approval. Only files absent from the parent are offered. Base-game protection still applies.</p></div>';

    echo '<div class="card"><h2>5. Child dependency request and automatic download</h2><pre class="mono">Child:
  Generate Missing Dependency Request
  Request contains local ue_dependencies rows where status=missing

Parent:
  Open Child Dependency Requests
  Approve or deny the request/items

Child worker:
  Polls the latest parent decision
  Re-checks that each approved dependency is still missing
  Queues approved dependency files only
  Downloads and imports automatically</pre><p class="muted">A child cannot use this workflow to browse or download arbitrary parent files.</p></div>';

    echo '<div class="card"><h2>6. Encrypt federation peer secrets</h2><p>Generate a deployment master key from a trusted shell:</p><pre class="mono">php catalog/bin/generate-federation-master-key.php</pre><p>Store the complete output in the environment as <span class="mono">UNREALDB_FEDERATION_MASTER_KEY</span>. Use the same key for every web and worker process that shares this database.</p><p>Encrypt existing peer rows:</p><pre class="mono">php catalog/bin/encrypt-federation-secrets.php</pre><p>After the command succeeds, enable:</p><pre class="mono">UNREALDB_REQUIRE_ENCRYPTED_FEDERATION_SECRETS=1</pre><p class="muted">Back up the master key separately from the database. Losing it makes existing peer pairings unreadable.</p></div>';

    echo '<div class="card"><h2>7. Enable the federation worker</h2><p>Open <a href="settings.php">Federation Settings</a> and set:</p><pre class="mono">cron_worker_enabled = 1
cron_worker_token = a long random value
max_files_per_transfer_run = 1 or more
auto_import_downloads = 1</pre><p class="muted">Keep the token private. It is sent in an HTTP header and must never be placed in a URL, bookmark, or access log.</p></div>';

    echo '<div class="card"><h2>8. Test the worker with curl</h2><pre class="mono">' . catalog_h($curlCommand) . '</pre><p>Expected JSON includes:</p><pre class="mono">"ok": true
"approved_dependency_queue": {...}
"transfers": [...]
"imports": [...]
"mirror_maintenance": {...}</pre></div>';

    echo '<div class="card"><h2>9. Synology DSM Task Scheduler</h2><p>DSM Control Panel → Task Scheduler → Create → Scheduled Task → User-defined script.</p><pre class="mono">' . catalog_h($curlCommand . ' >> /volume1/web/ut_reader/catalog/storage/federation/cron-worker.log 2>&1') . '</pre><p class="muted">Run every few minutes for continuous approval checks, transfers, imports, and mirror maintenance. Keep max_files_per_transfer_run low for large files.</p></div>';

    echo '<div class="card"><h2>10. Recommended transfer settings</h2><pre class="mono">max_files_per_transfer_run = 1
max_download_kbps = 0 or a safe limit
max_upload_kbps = 0 or a safe limit
delay_between_downloads_seconds = 5
delay_between_uploads_seconds = 5
max_transfer_file_size_mb = 1024
log_retention_days = 90</pre></div>';

    echo '<div class="card"><h2>11. Self-signed certificates</h2><p><span class="mono">allow_self_signed_federation_certificates</span> is disabled by default. Enable it only for private testing deployments such as the current LAN parent/child setup.</p><p class="muted">Testing mode disables certificate trust and hostname verification for outbound federation requests and permits private-network targets.</p></div>';

    echo '<div class="card"><h2>12. Public download and external mirror modes</h2><pre class="mono">local_direct
  users download directly from this site

external_mirror
  users receive external provider links only

external_mirror_preferred
  prefer an active external link, otherwise use local direct

disabled
  public downloads are disabled
  controlled federation transfers still work</pre></div>';

    echo '<div class="card"><h2>13. Manual mirror workflow</h2><pre class="mono">1. A public request queues a mirror job when required.
2. Admin opens /catalog/mirror-queue.php.
3. Admin uploads the file to the external provider.
4. Admin records the external URL.
5. Future public requests reuse the active link until it expires.</pre></div>';

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] federation docs failed: ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Federation docs error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h(catalog_public_error_message()) . '</p></div>';
    catalog_foot();
}
