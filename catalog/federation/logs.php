<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Federation Logs')) {
        exit;
    }

    catalog_head('Federation Logs');
    catalog_page_header('Federation Logs', 'Recent federation API, pairing, upload, download, import, and worker events.', catalog_federation_links());

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
