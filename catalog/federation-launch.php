<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

function fl_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function fl_card(string $title, string $href, string $text): void
{
    echo '<a class="stat" href="' . catalog_h($href) . '"><h2>' . catalog_h($title) . '</h2><p>' . catalog_h($text) . '</p></a>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_head('Federation Launcher');

    if (!fl_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    $stats = [
        'peers' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers')['c'] ?? 0),
        'active_peers' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers WHERE is_active=1')['c'] ?? 0),
        'queued' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="queued"')['c'] ?? 0),
        'downloaded' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="downloaded"')['c'] ?? 0),
        'failed' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="failed"')['c'] ?? 0),
        'requests' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_requests')['c'] ?? 0),
        'peer_files' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peer_files')['c'] ?? 0),
    ];

    echo '<div class="card"><h1>Federation Launcher</h1><p class="muted">Admin-only quick launch page for all federation functions.</p><p><a class="button" href="admin.php">Catalog Admin</a> <a class="button" href="federation/admin.php">Federation Admin</a> <a class="button" href="games.php">Games</a></p></div>';

    echo '<div class="grid">';
    echo '<div class="stat"><h2>' . $stats['peers'] . '</h2><p>Total peers</p></div>';
    echo '<div class="stat"><h2>' . $stats['active_peers'] . '</h2><p>Active peers</p></div>';
    echo '<div class="stat"><h2>' . $stats['peer_files'] . '</h2><p>Peer inventory rows</p></div>';
    echo '<div class="stat"><h2>' . $stats['requests'] . '</h2><p>Requests</p></div>';
    echo '<div class="stat"><h2>' . $stats['queued'] . '</h2><p>Queued jobs</p></div>';
    echo '<div class="stat"><h2>' . $stats['downloaded'] . '</h2><p>Waiting import</p></div>';
    echo '<div class="stat"><h2>' . $stats['failed'] . '</h2><p>Failed jobs</p></div>';
    echo '</div>';

    echo '<div class="card"><h2>Core federation</h2><div class="grid">';
    fl_card('Federation admin', 'federation/admin.php', 'Main federation dashboard.');
    fl_card('Settings', 'federation/settings.php', 'Identity, role, transfer limits, and worker token.');
    fl_card('Peers', 'federation/peers.php', 'Add/manage parent and child pairings.');
    fl_card('Queue', 'federation/queue.php', 'Queued/running/downloaded/imported/failed jobs.');
    fl_card('Bulk worker', 'federation/worker-run.php', 'Run multiple sequential transfer/import jobs.');
    fl_card('Maintenance', 'federation/maintenance.php', 'Prune logs/nonces and review incoming storage.');
    fl_card('Docs', 'federation/docs.php', 'DSM Task Scheduler and curl examples.');
    echo '</div></div>';

    echo '<div class="card"><h2>Parent/master</h2><div class="grid">';
    fl_card('Peer inventory', 'federation/peer-inventory.php', 'View child inventories separately.');
    fl_card('Parent pull', 'federation/parent-pull.php', 'Pull missing dependency files and other child files.');
    fl_card('Child requests', 'federation/requests.php', 'Approve/deny child dependency requests.');
    fl_card('Conflicts', 'federation/conflicts.php', 'Review GUID/package/hash conflicts.');
    echo '</div></div>';

    echo '<div class="card"><h2>Child site</h2><div class="grid">';
    fl_card('Push inventory', 'federation/inventory-push.php', 'Send verified local inventory to parent.');
    fl_card('Upload to parent', 'federation/upload-to-parent.php', 'Queue selected verified files for parent upload.');
    fl_card('Generate request', 'federation/request-generate.php', 'Request missing dependency files from parent.');
    fl_card('Request status/cancel', 'federation/request-status.php', 'Poll/cancel active parent request.');
    fl_card('Approved downloads', 'federation/approved-downloads.php', 'Queue parent-approved file downloads.');
    echo '</div></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation launcher error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
