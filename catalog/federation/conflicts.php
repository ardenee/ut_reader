<?php
declare(strict_types=1);


require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();

function fc_rows(PDO $db, string $type, int $peerId): array
{
    $peerSql = $peerId > 0 ? ' AND pf.peer_id=' . $peerId . ' ' : '';
    if ($type === 'same_guid_diff_hash') {
        return catalog_all($db, 'SELECT pf.*, p.site_name peer_name, f.id local_id, f.original_name local_file, f.md5 local_md5, f.sha1 local_sha1 FROM ue_federation_peer_files pf JOIN ue_federation_peers p ON p.id=pf.peer_id JOIN ue_files f ON f.package_guid=pf.package_guid AND f.scan_status="verified" WHERE pf.package_guid IS NOT NULL AND pf.package_guid<>"" AND pf.md5 IS NOT NULL AND pf.md5<>"" AND f.md5<>pf.md5 ' . $peerSql . ' ORDER BY p.site_name, pf.package_name, pf.original_name LIMIT 1000');
    }
    if ($type === 'same_name_diff_guid') {
        return catalog_all($db, 'SELECT pf.*, p.site_name peer_name, f.id local_id, f.original_name local_file, f.package_guid local_guid, f.md5 local_md5 FROM ue_federation_peer_files pf JOIN ue_federation_peers p ON p.id=pf.peer_id JOIN ue_files f ON f.package_name=pf.package_name AND f.scan_status="verified" WHERE pf.package_guid IS NOT NULL AND pf.package_guid<>"" AND f.package_guid<>pf.package_guid ' . $peerSql . ' ORDER BY p.site_name, pf.package_name, pf.original_name LIMIT 1000');
    }
    return catalog_all($db, 'SELECT pf.*, p.site_name peer_name, f.id local_id, f.original_name local_file, f.file_size local_size, f.package_guid local_guid FROM ue_federation_peer_files pf JOIN ue_federation_peers p ON p.id=pf.peer_id JOIN ue_files f ON f.md5=pf.md5 AND f.scan_status="verified" WHERE pf.md5 IS NOT NULL AND pf.md5<>"" AND (f.package_guid<>pf.package_guid OR f.file_size<>pf.file_size) ' . $peerSql . ' ORDER BY p.site_name, pf.package_name, pf.original_name LIMIT 1000');
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Federation Conflicts')) {
        exit;
    }

    catalog_head('Federation Conflicts');

    $peerId = (int)($_GET['peer_id'] ?? 0);
    $peers = catalog_all($db, 'SELECT id, site_name, peer_role FROM ue_federation_peers ORDER BY peer_role, site_name');
    catalog_page_header('Federation Conflict Report', 'Shows package identity mismatches between local verified files and peer inventories. Review these before pulling/importing blindly.', catalog_federation_links() + ['Peer Inventory' => 'peer-inventory.php', 'Parent Pull' => 'parent-pull.php']);

    echo '<div class="card"><form><label>Peer filter<br><select name="peer_id"><option value="0">All peers</option>';
    foreach ($peers as $peer) {
        $sel = (int)$peer['id'] === $peerId ? ' selected' : '';
        echo '<option value="' . (int)$peer['id'] . '"' . $sel . '>' . catalog_h($peer['peer_role'] . ' - ' . $peer['site_name']) . '</option>';
    }
    echo '</select></label> <button>Filter</button></form></div>';

    $sections = [
        'same_guid_diff_hash' => 'Same GUID but different MD5',
        'same_name_diff_guid' => 'Same package name but different GUID',
        'same_md5_diff_meta' => 'Same MD5 but different metadata',
    ];

    foreach ($sections as $type => $title) {
        $rows = fc_rows($db, $type, $peerId);
        echo '<div class="card"><h2>' . catalog_h($title) . '</h2>';
        if (!$rows) {
            echo '<p class="muted">No rows found.</p>';
        } else {
            echo '<table><tr><th>Peer</th><th>Package</th><th>Peer file</th><th>Local file</th><th>Peer GUID</th><th>Local GUID</th><th>Peer MD5</th><th>Local MD5</th><th>Sizes</th></tr>';
            foreach ($rows as $row) {
                $localGuid = $row['local_guid'] ?? $row['package_guid'];
                $localMd5 = $row['local_md5'] ?? '';
                echo '<tr><td>' . catalog_h($row['peer_name']) . '</td><td class="mono">' . catalog_h($row['package_name']) . '</td><td>' . catalog_h($row['original_name']) . '</td><td><a href="../file-info.php?id=' . (int)$row['local_id'] . '" target="_blank">' . catalog_h($row['local_file']) . '</a></td><td class="mono small">' . catalog_h($row['package_guid']) . '</td><td class="mono small">' . catalog_h($localGuid) . '</td><td class="mono small">' . catalog_h($row['md5']) . '</td><td class="mono small">' . catalog_h($localMd5) . '</td><td>' . catalog_h(catalog_bytes((int)$row['file_size']) . ' / ' . catalog_bytes((int)($row['local_size'] ?? 0))) . '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation conflicts error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
