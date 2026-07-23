<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationBaseGamePolicy.php';

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
        'Parent-master authority, automatic pairing, parent-controlled base-game policy, dependency approval, inventory synchronization, workers, secret encryption, and mirror notes.',
        catalog_federation_links() + ['Mirror Settings' => '../mirror-providers.php', 'Mirror Queue' => '../mirror-queue.php']
    );

    echo '<div class="card"><h2>1. Federation authority model</h2><table>';
    echo '<tr><th>Parent/master</th><td>Source of truth. Controls the federation base-game policy, reads paired child inventories, and may download files the parent does not already have. No child approval is required.</td></tr>';
    echo '<tr><th>Child</th><td>Inherits the parent base-game policy. It may request and download files only for current missing dependencies, after parent approval.</td></tr>';
    echo '<tr><th>Approved child downloads</th><td>The child federation worker re-checks that each dependency is still missing, then queues, downloads, and imports the approved file automatically.</td></tr>';
    echo '<tr><th>Files already held by parent</th><td>Not displayed as parent needs and cannot be queued again.</td></tr>';
    echo '<tr><th>Base-game files</th><td>' . catalog_h(federation_base_game_policy_label($db)) . '</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>2. Parent-controlled base-game policy</h2><pre class="mono">Federation Settings:
  Ignore base-game files = Yes   (default)</pre><p>When enabled, official base-game files are excluded from ordinary federation inventory totals, general file lists, conflict reports, and unrestricted parent pulls. The games and normal catalog views are unchanged.</p><p><strong>Missing dependencies are always the exception.</strong> If a parent or child has a missing dependency whose matching package is classified as a base-game file, federation still searches inventories for it, displays it in dependency views, permits a request, permits parent approval, and transfers it while that dependency remains missing.</p><p class="muted">The child receives this policy from signed parent responses and cannot override it locally. Both sides retain classified inventory rows internally so dependency discovery continues to work even when ordinary base-game rows are hidden.</p></div>';

    echo '<div class="card"><h2>3. Join and automatic pairing</h2><pre class="mono">Child:
  Open /catalog/federation/join-main-parent.php
  Submit the child identity to the chosen parent

Parent:
  Open /catalog/federation/join-requests.php
  Approve or deny the request

Child:
  The page polls the decision automatically
  Approval creates the parent peer automatically
  No claim page, copied endpoint, or copied token is required</pre><p class="muted">The original one-time request token remains stored only on the child and is used automatically to complete the approved pairing. The process is retryable if a status check is interrupted.</p></div>';

    echo '<div class="card"><h2>4. Bidirectional inventory synchronization</h2><pre class="mono">Parent:
  Open /catalog/federation/peer-inventory.php
  Select a child
  Click Refresh both inventories now

Results:
  Parent refreshes its cached child inventory
  Child refreshes its cached parent inventory
  Scheduled workers repeat this at the configured interval</pre><p class="muted">Inventories contain base-game classification internally. Ordinary views apply the parent policy; dependency views always retain matching base-game files.</p></div>';

    echo '<div class="card"><h2>5. Parent downloads from a child</h2><pre class="mono">1. Parent refreshes Child Inventory.
2. Parent opens Parent Dependency Needs or Parent Needs.
3. Parent selects files and queues downloads.
4. Federation worker downloads and imports the files.</pre><p><strong>Parent Dependency Needs</strong> includes base-game files only where they satisfy a current missing dependency. <strong>Parent Needs</strong> applies the ordinary parent base-game policy.</p></div>';

    echo '<div class="card"><h2>6. Child dependency request and automatic download</h2><pre class="mono">Child:
  Open Missing Files
  Request contains local ue_dependencies rows where status=missing
  Base-game dependency packages are included

Parent:
  Open Incoming Requests
  Approve or deny the dependency request
  Approval may remain active while a file is not yet available

Child worker:
  Polls the latest parent decision
  Re-checks that each approved dependency is still missing
  Queues approved dependency files only
  Downloads and imports automatically</pre><p class="muted">A child cannot use this workflow to browse or download arbitrary parent files. A base-game file is transferable here only because the approved request is tied to a still-missing dependency.</p></div>';

    echo '<div class="card"><h2>7. Encrypt federation peer secrets</h2><p>Generate a deployment master key from a trusted shell:</p><pre class="mono">php catalog/bin/generate-federation-master-key.php</pre><p>Store the complete output in the environment as <span class="mono">UNREALDB_FEDERATION_MASTER_KEY</span>. Use the same key for every web and worker process that shares this database.</p><p>Encrypt existing peer rows:</p><pre class="mono">php catalog/bin/encrypt-federation-secrets.php</pre><p>After the command succeeds, enable:</p><pre class="mono">UNREALDB_REQUIRE_ENCRYPTED_FEDERATION_SECRETS=1</pre><p class="muted">Back up the master key separately from the database. Losing it makes existing peer pairings unreadable.</p></div>';

    echo '<div class="card"><h2>8. Enable the federation worker</h2><p>Open <a href="settings.php">Federation Settings</a> and set:</p><pre class="mono">cron_worker_enabled = 1
cron_worker_token = a long random value
inventory_sync_interval_hours = 24
max_files_per_transfer_run = 1 or more
auto_import_downloads = 1</pre><p class="muted">Keep the token private. It is sent in an HTTP header and must never be placed in a URL, bookmark, or access log.</p></div>';

    echo '<div class="card"><h2>9. Test the worker with curl</h2><pre class="mono">' . catalog_h($curlCommand) . '</pre><p>Expected JSON includes:</p><pre class="mono">"ok": true
"inventory_sync": {...}
"approved_dependency_queue": {...}
"transfers": [...]
"imports": [...]
"mirror_maintenance": {...}</pre></div>';

    echo '<div class="card"><h2>10. Synology DSM Task Scheduler</h2><p>DSM Control Panel → Task Scheduler → Create → Scheduled Task → User-defined script.</p><pre class="mono">' . catalog_h($curlCommand . ' >> /volume1/web/ut_reader/catalog/storage/federation/cron-worker.log 2>&1') . '</pre><p class="muted">Run every few minutes for continuous approval checks, inventory refreshes, transfers, imports, and mirror maintenance. Keep max_files_per_transfer_run low for large files.</p></div>';

    echo '<div class="card"><h2>11. Recommended transfer settings</h2><pre class="mono">ignore_base_game_files = 1
inventory_sync_interval_hours = 24
max_files_per_transfer_run = 1
max_download_kbps = 0 or a safe limit
max_upload_kbps = 0 or a safe limit
delay_between_downloads_seconds = 5
delay_between_uploads_seconds = 5
max_transfer_file_size_mb = 1024
log_retention_days = 90</pre></div>';

    echo '<div class="card"><h2>12. Self-signed certificates</h2><p><span class="mono">allow_self_signed_federation_certificates</span> is disabled by default. Enable it only for private testing deployments such as the current LAN parent/child setup.</p><p class="muted">Testing mode disables certificate trust and hostname verification for outbound federation requests and permits private-network targets.</p></div>';

    echo '<div class="card"><h2>13. Public download and external mirror modes</h2><pre class="mono">local_direct
  users download directly from this site

external_mirror
  users receive external provider links only

external_mirror_preferred
  prefer an active external link, otherwise use local direct

disabled
  public downloads are disabled
  controlled federation transfers still work</pre></div>';

    echo '<div class="card"><h2>14. Manual mirror workflow</h2><pre class="mono">1. A public request queues a mirror job when required.
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
