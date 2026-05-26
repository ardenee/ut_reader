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
        'queued_jobs' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="queued"')['c'] ?? 0),
        'failed_jobs' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="failed"')['c'] ?? 0),
    ];

    echo '<div class="card"><h1>Federation Admin</h1><p class="muted">Parent/child federation foundation. Phase 1 covers identity, settings, peers, signed endpoints, and logs. File transfer comes next.</p><p><a class="button" href="../admin.php">Catalog admin</a> <a class="button" href="settings.php">Settings</a> <a class="button" href="peers.php">Peers</a> <a class="button" href="logs.php">Logs</a></p></div>';

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
    echo '<div class="stat"><h2>' . $stats['requests'] . '</h2><p>Requests</p></div>';
    echo '<div class="stat"><h2>' . $stats['queued_jobs'] . '</h2><p>Queued transfer jobs</p></div>';
    echo '<div class="stat"><h2>' . $stats['failed_jobs'] . '</h2><p>Failed transfer jobs</p></div>';
    echo '</div>';

    echo '<div class="card"><h2>Tools</h2><div class="grid">';
    echo '<a class="stat" href="settings.php"><h2>Federation settings</h2><p>Set site role, URL, identity, speed limits, delays, and transfer defaults.</p></a>';
    echo '<a class="stat" href="peers.php"><h2>Peers</h2><p>Add/manage parent or child sites and shared secrets.</p></a>';
    echo '<a class="stat" href="logs.php"><h2>Federation logs</h2><p>View API, pairing, upload/download and transfer logs.</p></a>';
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
