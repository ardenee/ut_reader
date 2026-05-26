<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';

function logs_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_head('Federation Logs');

    if (!logs_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="../index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card"><h1>Federation Logs</h1><p><a class="button" href="admin.php">Federation admin</a> <a class="button" href="settings.php">Settings</a> <a class="button" href="peers.php">Peers</a></p></div>';

    $rows = catalog_all($db, 'SELECT l.*, p.site_name peer_name FROM ue_federation_transfer_logs l LEFT JOIN ue_federation_peers p ON p.id=l.peer_id ORDER BY l.created_at DESC, l.id DESC LIMIT 500');
    echo '<div class="card"><h2>Recent logs</h2>';
    if (!$rows) {
        echo '<p class="muted">No federation logs yet.</p>';
    } else {
        echo '<table><tr><th>Time</th><th>Level</th><th>Peer</th><th>Event</th><th>Details</th></tr>';
        foreach ($rows as $row) {
            echo '<tr><td class="small">' . catalog_h($row['created_at']) . '</td><td>' . catalog_h($row['level']) . '</td><td>' . catalog_h($row['peer_name'] ?? '') . '</td><td class="mono">' . catalog_h($row['event']) . '</td><td class="path">' . catalog_h($row['details']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation logs error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
