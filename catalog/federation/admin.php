<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';

function fed_admin_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_head('Federation Admin');

    if (!fed_admin_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="../index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    $identity = fed_ensure_identity($db);
    $stats = [
        'peers' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers')['c'] ?? 0),
        'active_peers' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers WHERE is_active=1')['c'] ?? 0),
        'peer_files' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peer_files')['c'] ?? 0),
        'requests' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_requests')['c'] ?? 0),
        'join_pending' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_join_requests WHERE status="pending"')['c'] ?? 0),
        'queued_jobs' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="queued"')['c'] ?? 0),
        'downloaded_jobs' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="downloaded"')['c'] ?? 0),
        'failed_jobs' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="failed"')['c'] ?? 0),
    ];

    echo '<div class="card"><h1>Federation Admin</h1><p class="muted">Parent/child federation dashboard for identity, join requests, peers, inventory, requests, approvals, transfer queues, imports, uploads, maintenance, conflicts, and logs.</p><p><a class="button" href="../admin.php">Catalog admin</a> <a class="button" href="settings.php">Settings</a> <a class="button" href="join-requests.php">Join Requests</a> <a class="button" href="join.php" target="_blank">Public Join Page</a> <a class="button" href="claim-parent.php">Claim Parent</a> <a class="button" href="peers.php">Peers</a> <a class="button" href="queue.php">Queue</a> <a class="button" href="worker-run.php">Bulk worker</a> <a class="button" href="conflicts.php">Conflicts</a> <a class="button" href="maintenance.php">Maintenance</a> <a class="button" href="docs.php">Docs</a> <a class="button" href="logs.php">Logs</a></p></div>';

    echo '<div class="card"><h2>Local site identity</h2><table>';
    echo '<tr><th>Site name</th><td>' . catalog_h($identity['site_name']) . '</td></tr>';
    echo '<tr><th>Site URL</th><td class="mono path">' . catalog_h($identity['site_url']) . '</td></tr>';
    echo '<tr><th>Site ID</th><td class="mono">' . catalog_h($identity['site_id']) . '</td></tr>';
    echo '<tr><th>Fingerprint</th><td class="mono">' . catalog_h($identity['site_fingerprint']) . '</td></tr>';
    echo '<tr><th>Role</th><td>' . catalog_h(fed_setting($db, 'site_role', 'standalone')) . '</td></tr>';
    echo '</table></div>';

    echo '<div class="grid">';
    echo '<div class="stat"><h2>' . $stats['peers'] . '</h2><p>Total peers</p></div>';
    echo '<div class="stat"><h2>' . $stats['active_peers'] . '</h2><p>Active peers</p></div>';
    echo '<div class="stat"><h2>' . $stats['peer_files'] . '</h2><p>Peer inventory rows</p></div>';
    echo '<div class="stat"><h2>' . $stats['requests'] . '</h2><p>File requests</p></div>';
    echo '<div class="stat"><h2>' . $stats['join_pending'] . '</h2><p>Pending join requests</p></div>';
    echo '<div class="stat"><h2>' . $stats['queued_jobs'] . '</h2><p>Queued transfer jobs</p></div>';
    echo '<div class="stat"><h2>' . $stats['downloaded_jobs'] . '</h2><p>Waiting import</p></div>';
    echo '<div class="stat"><h2>' . $stats['failed_jobs'] . '</h2><p>Failed jobs</p></div>';
    echo '</div>';

    echo '<div class="card"><h2>Core tools</h2><div class="grid">';
    echo '<a class="stat" href="settings.php"><h2>Federation settings</h2><p>Set site role, URL, identity, speed limits, delays, transfer defaults, join request toggle, and cron worker token.</p></a>';
    echo '<a class="stat" href="join-requests.php"><h2>Join requests</h2><p>Parent admin approval page for public child-site pairing requests.</p></a>';
    echo '<a class="stat" href="join.php"><h2>Public join page</h2><p>Share this URL so new deployments can request access to the master parent.</p></a>';
    echo '<a class="stat" href="claim-parent.php"><h2>Claim parent</h2><p>Child-side tool to claim an approved one-time parent pairing URL.</p></a>';
    echo '<a class="stat" href="peers.php"><h2>Peers</h2><p>Add/manage parent or child sites and shared secrets.</p></a>';
    echo '<a class="stat" href="queue.php"><h2>Queue overview</h2><p>Review queued/running/downloaded/imported/failed transfer jobs.</p></a>';
    echo '<a class="stat" href="conflicts.php"><h2>Conflict report</h2><p>Review same-name, same-GUID, and hash mismatch conflicts between local and peer files.</p></a>';
    echo '<a class="stat" href="maintenance.php"><h2>Maintenance</h2><p>Prune old nonces/logs and review federation incoming storage usage.</p></a>';
    echo '<a class="stat" href="docs.php"><h2>DSM/cron docs</h2><p>Setup notes and curl examples for scheduled federation workers.</p></a>';
    echo '<a class="stat" href="logs.php"><h2>Federation logs</h2><p>View API, pairing, upload/download and transfer logs.</p></a>';
    echo '</div></div>';

    echo '<div class="card"><h2>Parent/master tools</h2><div class="grid">';
    echo '<a class="stat" href="peer-inventory.php"><h2>Peer inventory</h2><p>View each child inventory separately.</p></a>';
    echo '<a class="stat" href="parent-pull.php"><h2>Parent pull from children</h2><p>Pull missing dependencies first, then other files the parent does not have.</p></a>';
    echo '<a class="stat" href="requests.php"><h2>Child file requests</h2><p>Approve or deny child missing-dependency requests, including selected items.</p></a>';
    echo '</div></div>';

    echo '<div class="card"><h2>Child tools</h2><div class="grid">';
    echo '<a class="stat" href="inventory-push.php"><h2>Push inventory to parent</h2><p>Send verified local file metadata to the parent.</p></a>';
    echo '<a class="stat" href="upload-to-parent.php"><h2>Upload files to parent</h2><p>Queue selected verified local files for controlled upload to parent.</p></a>';
    echo '<a class="stat" href="request-generate.php"><h2>Generate missing dependency request</h2><p>Submit local missing dependency list to the parent.</p></a>';
    echo '<a class="stat" href="request-status.php"><h2>Request status/cancel</h2><p>Poll parent status and cancel active requests.</p></a>';
    echo '<a class="stat" href="approved-downloads.php"><h2>Approved downloads</h2><p>Queue parent-approved files for controlled download.</p></a>';
    echo '</div></div>';

    echo '<div class="card"><h2>Workers</h2><div class="grid">';
    echo '<a class="stat" href="worker-run.php"><h2>Bulk worker</h2><p>Run multiple sequential transfers/imports up to the configured per-run limit.</p></a>';
    echo '<a class="stat" href="transfer-run.php"><h2>Run one transfer</h2><p>Download or upload one queued federation job.</p></a>';
    echo '<a class="stat" href="import-run.php"><h2>Import one downloaded file</h2><p>Import one downloaded federation file into the local catalog.</p></a>';
    echo '<a class="stat" href="cron-worker.php" target="_blank"><h2>Cron worker endpoint</h2><p>Token-protected worker endpoint for DSM Task Scheduler.</p></a>';
    echo '<a class="stat" href="../api/federation/hello.php" target="_blank"><h2>Hello endpoint</h2><p>Public identity/status endpoint used for connection testing.</p></a>';
    echo '</div></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation admin error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
