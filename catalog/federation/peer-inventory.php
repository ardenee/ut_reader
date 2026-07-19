<?php
declare(strict_types=1);


require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Peer Inventory')) {
        exit;
    }

    catalog_head('Peer Inventory');

    $peerId = (int)($_GET['peer_id'] ?? 0);
    catalog_page_header('Peer Inventory', 'Parent-side view of child inventories. Each child remains separate so you can compare missing, shared, compressed, and duplicate packages per peer.', catalog_federation_links() + ['Push Inventory' => 'inventory-push.php', 'Parent Pull' => 'parent-pull.php']);

    $peers = catalog_all($db, 'SELECT * FROM ue_federation_peers ORDER BY peer_role, site_name');
    echo '<div class="card"><h2>Select peer</h2><form><select name="peer_id">';
    foreach ($peers as $peer) {
        $sel = (int)$peer['id'] === $peerId ? ' selected' : '';
        echo '<option value="' . (int)$peer['id'] . '"' . $sel . '>' . catalog_h($peer['peer_role'] . ' - ' . $peer['site_name']) . '</option>';
    }
    echo '</select> <button>View inventory</button></form></div>';

    if ($peerId <= 0 && $peers) {
        $peerId = (int)$peers[0]['id'];
    }

    if ($peerId > 0) {
        $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=?', [$peerId]);
        if (!$peer) {
            throw new RuntimeException('Peer not found');
        }

        $summary = catalog_one($db, 'SELECT COUNT(*) total_files, SUM(local.id IS NULL) missing_locally, SUM(local.id IS NOT NULL) already_have FROM ue_federation_peer_files pf LEFT JOIN ue_files local ON local.package_guid=pf.package_guid AND local.scan_status="verified" WHERE pf.peer_id=?', [$peerId]);
        echo '<div class="card"><h2>' . catalog_h($peer['site_name']) . '</h2><table>';
        echo '<tr><th>URL</th><td class="mono path">' . catalog_h($peer['site_url']) . '</td></tr>';
        echo '<tr><th>Total peer files</th><td>' . (int)($summary['total_files'] ?? 0) . '</td></tr>';
        echo '<tr><th>Parent does not have</th><td>' . (int)($summary['missing_locally'] ?? 0) . '</td></tr>';
        echo '<tr><th>Both have</th><td>' . (int)($summary['already_have'] ?? 0) . '</td></tr>';
        echo '</table></div>';

        $missing = catalog_all($db, 'SELECT pf.* FROM ue_federation_peer_files pf LEFT JOIN ue_files local ON local.package_guid=pf.package_guid AND local.scan_status="verified" WHERE pf.peer_id=? AND local.id IS NULL ORDER BY pf.remote_game_name, pf.package_name, pf.original_name LIMIT 500', [$peerId]);
        echo '<div class="card"><h2>Files this site does not have yet</h2>';
        if (!$missing) {
            echo '<p class="muted">No missing peer files found.</p>';
        } else {
            echo '<table><tr><th>Game</th><th>Package</th><th>File</th><th>GUID</th><th>MD5</th><th>Size</th><th>Type</th><th>Last seen</th></tr>';
            foreach ($missing as $row) {
                $compressed = (int)$row['is_compressed'] === 1;
                echo '<tr><td>' . catalog_h($row['remote_game_name']) . '</td><td class="mono">' . catalog_h($row['package_name']) . '</td><td>' . catalog_h($row['original_name']) . '</td><td class="mono small">' . catalog_h($row['package_guid']) . '</td><td class="mono small">' . catalog_h($row['md5']) . '</td><td>' . catalog_h(catalog_bytes((int)$row['file_size'])) . '</td><td><span class="dep ' . ($compressed ? 'compressed' : 'uncompressed') . '">' . ($compressed ? 'compressed' : 'uncompressed') . '</span></td><td>' . catalog_h($row['last_seen_at']) . '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';

        $shared = catalog_all($db, 'SELECT pf.*, local.id local_id, local.original_name local_name FROM ue_federation_peer_files pf JOIN ue_files local ON local.package_guid=pf.package_guid AND local.scan_status="verified" WHERE pf.peer_id=? ORDER BY pf.remote_game_name, pf.package_name, pf.original_name LIMIT 500', [$peerId]);
        echo '<div class="card"><h2>Files both sites have</h2>';
        if (!$shared) {
            echo '<p class="muted">No shared files found.</p>';
        } else {
            echo '<table><tr><th>Game</th><th>Package</th><th>Peer file</th><th>Local file</th><th>GUID</th><th>Peer MD5</th><th>Local</th></tr>';
            foreach ($shared as $row) {
                echo '<tr><td>' . catalog_h($row['remote_game_name']) . '</td><td class="mono">' . catalog_h($row['package_name']) . '</td><td>' . catalog_h($row['original_name']) . '</td><td>' . catalog_h($row['local_name']) . '</td><td class="mono small">' . catalog_h($row['package_guid']) . '</td><td class="mono small">' . catalog_h($row['md5']) . '</td><td><a href="../file-info.php?id=' . (int)$row['local_id'] . '" target="_blank">local info</a></td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Peer inventory error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
