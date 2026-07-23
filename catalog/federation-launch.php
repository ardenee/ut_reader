<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/lib/FederationAuth.php';
require_once __DIR__ . '/lib/FederationBaseGamePolicy.php';

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

    $role = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    $ignoreBaseGame = federation_ignore_base_game_files($db);
    $stats = [
        'peers' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers')['c'] ?? 0),
        'active_peers' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers WHERE is_active=1')['c'] ?? 0),
        'queued' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="queued"')['c'] ?? 0),
        'downloaded' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="downloaded"')['c'] ?? 0),
        'failed' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="failed"')['c'] ?? 0),
        'requests' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_requests')['c'] ?? 0),
        'peer_files' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peer_files' . ($ignoreBaseGame ? ' WHERE COALESCE(is_base_game,0)=0' : ''))['c'] ?? 0),
    ];

    echo '<div class="card"><h1>Federation Launcher</h1><p>Current server mode: <strong>' . catalog_h(ucfirst($role)) . '</strong>.</p><p>' . catalog_h(federation_base_game_policy_label($db)) . '</p><p><a class="button" href="federation/admin.php">Federation Overview</a> <a class="button" href="federation/settings.php">Settings</a> <a class="button" href="games.php">Games</a></p></div>';

    echo '<div class="grid">';
    echo '<div class="stat"><h2>' . $stats['peers'] . '</h2><p>Total connections</p></div>';
    echo '<div class="stat"><h2>' . $stats['active_peers'] . '</h2><p>Active connections</p></div>';
    echo '<div class="stat"><h2>' . $stats['peer_files'] . '</h2><p>Cached inventory rows under policy</p></div>';
    echo '<div class="stat"><h2>' . $stats['requests'] . '</h2><p>File requests</p></div>';
    echo '<div class="stat"><h2>' . $stats['queued'] . '</h2><p>Queued jobs</p></div>';
    echo '<div class="stat"><h2>' . $stats['downloaded'] . '</h2><p>Waiting import</p></div>';
    echo '<div class="stat"><h2>' . $stats['failed'] . '</h2><p>Failed jobs</p></div>';
    echo '</div>';

    echo '<div class="card"><h2>Connections and requests</h2><div class="grid">';
    fl_card('Parents', 'federation/peers.php?role=parent', 'View the parent connected to a Child server.');
    fl_card('Join a Parent', 'federation/join-main-parent.php', 'Join only when no parent connection exists.');
    fl_card('Children', 'federation/peers.php?role=child', 'View children connected to a Parent server.');
    fl_card('Incoming Child Join Requests', 'federation/join-requests.php', 'Parent-side approval of child pairing requests.');
    fl_card('Missing Files', 'federation/missing-files.php', 'Select locally missing dependency packages, including base-game dependency exceptions.');
    fl_card('Requests', 'federation/request-center.php', 'Role-aware request overview.');
    fl_card('Incoming File Requests', 'federation/requests.php', 'Parent-side approval of files requested by children.');
    fl_card('Outgoing File Requests', 'federation/request-status.php', 'Child-side status for requests sent to a parent.');
    fl_card('Approved Downloads', 'federation/approved-downloads.php', 'Child-side approved dependency download and import history.');
    echo '</div></div>';

    echo '<div class="card"><h2>Inventories and transfers</h2><div class="grid">';
    fl_card('Child Inventories', 'federation/peer-inventory.php', 'Parent-side child selection and three need views.');
    fl_card('Parent Pull', 'federation/parent-pull.php', 'Parent pulls selected files directly from children under the parent policy.');
    fl_card('Transfer Queue', 'federation/queue.php', 'Queued, running, downloaded, imported, and failed jobs.');
    fl_card('Run Worker', 'federation/worker-run.php', 'Poll approvals, transfer files, and import downloads.');
    fl_card('Conflicts', 'federation/conflicts.php', 'Review GUID, package, and hash conflicts.');
    echo '</div></div>';

    echo '<div class="card"><h2>Administration</h2><div class="grid">';
    fl_card('Federation Overview', 'federation/admin.php', 'Role-aware dashboard and next actions.');
    fl_card('Settings', 'federation/settings.php', 'Identity, role, base-game policy, transfer limits, and worker settings.');
    fl_card('Maintenance', 'federation/maintenance.php', 'Clean up federation data and review storage.');
    fl_card('Logs', 'federation/logs.php', 'Pairing, request, inventory, and transfer events.');
    fl_card('Documentation', 'federation/docs.php', 'Federation setup and worker guidance.');
    echo '</div></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation launcher error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
