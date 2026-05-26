<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

function admin_page_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function admin_tool_card(string $title, string $href, string $description): void
{
    echo '<a class="stat" href="' . catalog_h($href) . '"><h2>' . catalog_h($title) . '</h2><p>' . catalog_h($description) . '</p></a>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    catalog_head('Catalog Admin');

    if (!admin_page_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="index.php?page=login">Admin Login</a> first.</p></div>';
        catalog_foot();
        exit;
    }

    $stats = [
        'games' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_games')['c'] ?? 0),
        'files' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_files')['c'] ?? 0),
        'verified' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_files WHERE scan_status="verified"')['c'] ?? 0),
        'duplicates' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_files WHERE scan_status="duplicate"')['c'] ?? 0),
        'sources' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_sources')['c'] ?? 0),
        'source_links' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_file_locations')['c'] ?? 0),
        'missing_deps' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="missing"')['c'] ?? 0),
        'fed_peers' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers')['c'] ?? 0),
        'fed_queue' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="queued"')['c'] ?? 0),
        'fed_downloaded' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="downloaded"')['c'] ?? 0),
        'fed_failed' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="failed"')['c'] ?? 0),
    ];

    echo '<div class="card"><h1>Catalog Admin</h1><p class="muted">Central admin dashboard for catalog browsing, upload/scanning, dependency checks, source locations, duplicate cleanup, download testing, and federation.</p><p><a class="button" href="index.php?page=admin">Old admin page</a> <a class="button" href="games.php">Public/catalog view</a> <a class="button" href="federation/admin.php">Federation Admin</a> <a class="button" href="index.php?page=logout">Logout</a></p></div>';

    echo '<div class="grid">';
    echo '<div class="stat"><h2>' . $stats['games'] . '</h2><p>Games</p></div>';
    echo '<div class="stat"><h2>' . $stats['files'] . '</h2><p>Total files</p></div>';
    echo '<div class="stat"><h2>' . $stats['verified'] . '</h2><p>Verified files</p></div>';
    echo '<div class="stat"><h2>' . $stats['duplicates'] . '</h2><p>Retired duplicates</p></div>';
    echo '<div class="stat"><h2>' . $stats['sources'] . '</h2><p>Sources</p></div>';
    echo '<div class="stat"><h2>' . $stats['source_links'] . '</h2><p>Source links</p></div>';
    echo '<div class="stat"><h2>' . $stats['missing_deps'] . '</h2><p>Missing dependency rows</p></div>';
    echo '<div class="stat"><h2>' . $stats['fed_peers'] . '</h2><p>Federation peers</p></div>';
    echo '<div class="stat"><h2>' . $stats['fed_queue'] . '</h2><p>Fed queued jobs</p></div>';
    echo '<div class="stat"><h2>' . $stats['fed_downloaded'] . '</h2><p>Fed waiting import</p></div>';
    echo '<div class="stat"><h2>' . $stats['fed_failed'] . '</h2><p>Fed failed jobs</p></div>';
    echo '</div>';

    echo '<div class="card"><h2>Main tools</h2><div class="grid">';
    admin_tool_card('Games / popup file browser', 'games.php', 'Browse games and files with popup details/download windows.');
    admin_tool_card('Upload / game admin', 'index.php?page=admin', 'Open the original admin page for game upload and game management.');
    admin_tool_card('Search catalog', 'index.php?page=search', 'Search by MD5, SHA1, GUID, package name, filename, import, or export.');
    admin_tool_card('GUID duplicate manager', 'duplicates.php', 'Find duplicate Unreal package GUIDs and retire duplicate rows into a canonical file.');
    echo '</div></div>';

    echo '<div class="card"><h2>Federation</h2><div class="grid">';
    admin_tool_card('Federation admin', 'federation/admin.php', 'Main parent/child federation dashboard.');
    admin_tool_card('Federation settings', 'federation/settings.php', 'Site identity, role, speed limits, transfer limits, and worker key.');
    admin_tool_card('Peers', 'federation/peers.php', 'Add/manage parent and child site pairings.');
    admin_tool_card('Queue overview', 'federation/queue.php', 'View queued/running/downloaded/imported/failed jobs.');
    admin_tool_card('Bulk worker', 'federation/worker-run.php', 'Run multiple sequential transfers/imports based on configured limit.');
    admin_tool_card('Parent pull', 'federation/parent-pull.php', 'Parent pulls missing dependencies and other needed files from children.');
    admin_tool_card('Child requests', 'federation/requests.php', 'Parent approves/denies child missing-dependency requests.');
    admin_tool_card('Approved downloads', 'federation/approved-downloads.php', 'Child downloads parent-approved files.');
    admin_tool_card('Upload to parent', 'federation/upload-to-parent.php', 'Child queues selected verified files for controlled upload to parent.');
    admin_tool_card('Conflict report', 'federation/conflicts.php', 'Review GUID/name/hash conflicts between local and peer inventories.');
    admin_tool_card('Maintenance', 'federation/maintenance.php', 'Prune logs/nonces and review incoming federation storage.');
    admin_tool_card('Docs / DSM cron', 'federation/docs.php', 'Worker setup and DSM Task Scheduler command examples.');
    echo '</div></div>';

    echo '<div class="card"><h2>Source / server scanning</h2><div class="grid">';
    admin_tool_card('Sources', 'sources.php', 'Add local server folders, HTTP mirrors, or redirect-server sources.');
    admin_tool_card('Local source scanner', 'source-scan.php', 'Scan configured local_path sources and link files by MD5/GUID.');
    admin_tool_card('HTTP / redirect scanner', 'http-source-scan.php', 'Scan manifest files from HTTP mirrors or redirect servers, including optional deep GUID scan.');
    echo '</div></div>';

    echo '<div class="card"><h2>Direct game upload links</h2><table><tr><th>Game</th><th>Engine</th><th>Upload/admin</th><th>Popup browser</th></tr>';
    $games = catalog_all($db, 'SELECT id,name,engine_key FROM ue_games ORDER BY name');
    foreach ($games as $game) {
        echo '<tr><td>' . catalog_h($game['name']) . '</td><td class="mono">' . catalog_h($game['engine_key']) . '</td><td><a class="button" href="index.php?page=game&id=' . (int)$game['id'] . '">upload/admin</a></td><td><a class="button" href="game-files.php?id=' . (int)$game['id'] . '">popup browser</a></td></tr>';
    }
    echo '</table></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Admin error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
