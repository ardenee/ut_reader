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
    catalog_page_header(
        'Peer Inventory',
        'View inventory metadata already pushed by each child. This page does not contact the child or request a new inventory.',
        catalog_federation_links() + ['Push Inventory' => 'inventory-push.php', 'Parent Pull' => 'parent-pull.php']
    );

    $peers = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="child" ORDER BY site_name');
    if ($peerId <= 0 && $peers) {
        $peerId = (int)$peers[0]['id'];
    }

    echo '<div class="card"><h2>Select child peer</h2>';
    if (!$peers) {
        echo '<p class="muted">No child peers are configured on this parent.</p>';
    } else {
        echo '<form method="get" action="peer-inventory.php"><label>Child peer<br><select name="peer_id">';
        foreach ($peers as $peer) {
            $selected = (int)$peer['id'] === $peerId ? ' selected' : '';
            echo '<option value="' . (int)$peer['id'] . '"' . $selected . '>' . catalog_h($peer['site_name'] . ' - ' . $peer['site_url']) . '</option>';
        }
        echo '</select></label> <button>Show stored inventory</button></form>';
        echo '<p class="muted">This reads the parent database only. To refresh it, run <strong>Push Inventory to Parent</strong> on the child site.</p>';
    }
    echo '</div>';

    if ($peerId > 0) {
        $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="child"', [$peerId]);
        if (!$peer) {
            throw new RuntimeException('Child peer not found.');
        }

        $summary = catalog_one(
            $db,
            'SELECT COUNT(*) total_files,
                    COALESCE(SUM(local.id IS NULL),0) missing_locally,
                    COALESCE(SUM(local.id IS NOT NULL),0) already_have,
                    MAX(pf.last_seen_at) last_received_at
             FROM ue_federation_peer_files pf
             LEFT JOIN ue_files local
               ON local.package_guid=pf.package_guid
              AND local.scan_status="verified"
             WHERE pf.peer_id=?',
            [$peerId]
        );
        $totalFiles = (int)($summary['total_files'] ?? 0);
        $lastReceivedAt = trim((string)($summary['last_received_at'] ?? ''));
        $childPushUrl = rtrim((string)$peer['site_url'], '/') . '/federation/inventory-push.php';

        echo '<div class="card"><h2>' . catalog_h($peer['site_name']) . '</h2><table>';
        echo '<tr><th>Child URL</th><td class="mono path">' . catalog_h($peer['site_url']) . '</td></tr>';
        echo '<tr><th>Stored inventory rows</th><td>' . $totalFiles . '</td></tr>';
        echo '<tr><th>Last inventory received</th><td>' . catalog_h($lastReceivedAt !== '' ? $lastReceivedAt : 'never') . '</td></tr>';
        echo '<tr><th>Parent does not have</th><td>' . (int)($summary['missing_locally'] ?? 0) . '</td></tr>';
        echo '<tr><th>Both have</th><td>' . (int)($summary['already_have'] ?? 0) . '</td></tr>';
        echo '</table></div>';

        if ($totalFiles === 0) {
            echo CatalogUi::alert(
                'warning',
                'No inventory has been received from this child yet. Open the child administration page and run Push Inventory to Parent. After the push completes, reload this page.',
                'No stored inventory'
            );
            echo '<div class="card"><h2>Refresh from child</h2>';
            echo '<p><a class="button" href="' . catalog_h($childPushUrl) . '" target="_blank" rel="noopener">Open child Push Inventory page</a></p>';
            echo '<p class="mono path">' . catalog_h($childPushUrl) . '</p></div>';
        } else {
            echo '<div class="card"><h2>Refresh inventory</h2>';
            echo '<p class="muted">The parent cannot silently read the child database. Inventory is authenticated and pushed by the child.</p>';
            echo '<p><a class="button" href="' . catalog_h($childPushUrl) . '" target="_blank" rel="noopener">Open child Push Inventory page</a></p></div>';
        }

        $missing = catalog_all(
            $db,
            'SELECT pf.*
             FROM ue_federation_peer_files pf
             LEFT JOIN ue_files local
               ON local.package_guid=pf.package_guid
              AND local.scan_status="verified"
             WHERE pf.peer_id=? AND local.id IS NULL
             ORDER BY pf.remote_game_name, pf.package_name, pf.original_name
             LIMIT 500',
            [$peerId]
        );
        echo '<div class="card"><h2>Files this parent does not have yet</h2>';
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

        $shared = catalog_all(
            $db,
            'SELECT pf.*, local.id local_id, local.original_name local_name
             FROM ue_federation_peer_files pf
             JOIN ue_files local
               ON local.package_guid=pf.package_guid
              AND local.scan_status="verified"
             WHERE pf.peer_id=?
             ORDER BY pf.remote_game_name, pf.package_name, pf.original_name
             LIMIT 500',
            [$peerId]
        );
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
