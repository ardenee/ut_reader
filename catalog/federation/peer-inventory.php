<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationInventory.php';

function pi_local_absence_sql(string $peerAlias = 'pf'): string
{
    return 'NOT EXISTS (
        SELECT 1 FROM ue_files local
        WHERE local.scan_status="verified"
          AND (
            (' . $peerAlias . '.package_guid IS NOT NULL AND ' . $peerAlias . '.package_guid<>"" AND local.package_guid=' . $peerAlias . '.package_guid)
            OR
            (' . $peerAlias . '.md5 IS NOT NULL AND ' . $peerAlias . '.md5<>"" AND local.md5=' . $peerAlias . '.md5)
          )
    )';
}

function pi_needed_sql(string $peerAlias = 'pf'): string
{
    return 'EXISTS (
        SELECT 1 FROM ue_dependencies d
        WHERE d.status="missing" AND d.required_package=' . $peerAlias . '.package_name
    )';
}

function pi_filter_value(string $value): string
{
    return in_array($value, ['all', 'needed', 'missing'], true) ? $value : 'all';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_peer_inventory');
        $peerId = (int)($_POST['peer_id'] ?? 0);
        $filter = pi_filter_value((string)($_POST['filter'] ?? 'all'));
        $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="child" AND is_active=1', [$peerId]);
        if (!$peer) {
            throw new RuntimeException('Active child peer not found.');
        }

        $action = (string)($_POST['action'] ?? 'refresh');
        if ($action === 'refresh') {
            $result = federation_pull_inventory_from_child($db, $peerId);
            $_SESSION['fed_peer_inventory_flash'] = 'Inventory synchronized from ' . (string)$peer['site_name'] . ': ' . (int)$result['received'] . ' file row(s), ' . (int)$result['removed_stale'] . ' stale row(s) removed.';
        } elseif ($action === 'queue') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['peer_file_ids'] ?? []), static fn(int $id): bool => $id > 0)));
            if (!$ids) {
                throw new RuntimeException('Select at least one child file to download.');
            }
            if (count($ids) > 1000) {
                throw new RuntimeException('Select no more than 1000 files at once.');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $args = array_merge([$peerId], $ids);
            $rows = catalog_all(
                $db,
                'SELECT pf.* FROM ue_federation_peer_files pf
                 WHERE pf.peer_id=? AND pf.id IN (' . $placeholders . ')
                   AND ' . pi_local_absence_sql('pf'),
                $args
            );

            $insert = $db->prepare(
                'INSERT INTO ue_federation_transfer_jobs(
                    peer_id,direction,remote_file_id,status,
                    speed_limit_kbps,wait_after_seconds,bytes_total
                 ) VALUES(? ,"parent_pull_from_child",? ,"queued",?,?,?)'
            );
            $queued = 0;
            foreach ($rows as $row) {
                $remoteFileId = (int)($row['remote_file_id'] ?? 0);
                if ($remoteFileId <= 0) {
                    continue;
                }
                $existing = catalog_one(
                    $db,
                    'SELECT id FROM ue_federation_transfer_jobs
                     WHERE peer_id=? AND direction="parent_pull_from_child" AND remote_file_id=?
                       AND status IN ("queued","running","downloaded")
                     LIMIT 1',
                    [$peerId, $remoteFileId]
                );
                if ($existing) {
                    continue;
                }
                $insert->execute([
                    $peerId,
                    $remoteFileId,
                    (int)fed_setting($db, 'max_download_kbps', '0'),
                    (int)fed_setting($db, 'delay_between_downloads_seconds', '5'),
                    (int)$row['file_size'],
                ]);
                $queued++;
            }
            fed_log($db, $peerId, null, 'INFO', 'PARENT_PULL_QUEUE', 'Queued ' . $queued . ' child file(s) from peer inventory.');
            $_SESSION['fed_peer_inventory_flash'] = 'Queued ' . $queued . ' child file(s) for parent download.';
        } else {
            throw new RuntimeException('Unknown peer inventory action.');
        }

        header('Location: peer-inventory.php?peer_id=' . $peerId . '&filter=' . rawurlencode($filter));
        exit;
    }

    if (!catalog_require_admin_page('Peer Inventory')) {
        exit;
    }

    $peers = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="child" AND is_active=1 ORDER BY site_name');
    $peerId = (int)($_GET['peer_id'] ?? ($peers[0]['id'] ?? 0));
    $filter = pi_filter_value((string)($_GET['filter'] ?? 'all'));
    $peer = $peerId > 0
        ? catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="child" AND is_active=1', [$peerId])
        : null;
    $syncError = '';

    if ($peer) {
        $storedCount = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peer_files WHERE peer_id=?', [$peerId])['c'] ?? 0);
        if ($storedCount === 0) {
            try {
                $result = federation_pull_inventory_from_child($db, $peerId);
                $_SESSION['fed_peer_inventory_flash'] = 'Initial inventory synchronized automatically: ' . (int)$result['received'] . ' file row(s).';
            } catch (Throwable $error) {
                $syncError = $error->getMessage();
            }
        }
    }

    catalog_head('Peer Inventory');
    catalog_flash($_SESSION['fed_peer_inventory_flash'] ?? null);
    unset($_SESSION['fed_peer_inventory_flash']);

    catalog_page_header(
        'Child Inventory',
        'The parent/master reads child inventory directly. Only files absent from the parent are shown; the child does not approve inventory access or parent downloads.',
        catalog_federation_links() + ['Parent Pull Queue' => 'parent-pull.php', 'Run Transfer Queue' => 'transfer-run.php', 'Import Downloads' => 'import-run.php']
    );

    if ($syncError !== '') {
        echo CatalogUi::alert('warning', $syncError, 'Child inventory synchronization failed');
    }

    echo '<div class="card"><h2>Select child</h2>';
    if (!$peers) {
        echo '<p class="muted">No active child peers are configured on this parent.</p></div>';
        catalog_foot();
        exit;
    }
    echo '<form method="get" action="peer-inventory.php"><label>Child peer<br><select name="peer_id">';
    foreach ($peers as $row) {
        $selected = (int)$row['id'] === $peerId ? ' selected' : '';
        echo '<option value="' . (int)$row['id'] . '"' . $selected . '>' . catalog_h($row['site_name'] . ' - ' . $row['site_url']) . '</option>';
    }
    echo '</select></label> <label>Show<br><select name="filter">';
    foreach (['all' => 'All files parent lacks', 'needed' => 'Needed dependency files', 'missing' => 'Other missing files'] as $value => $label) {
        echo '<option value="' . $value . '"' . ($filter === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label> <button>View inventory</button></form>';
    echo '<form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peer_inventory')) . '"><input type="hidden" name="action" value="refresh"><input type="hidden" name="peer_id" value="' . $peerId . '"><input type="hidden" name="filter" value="' . catalog_h($filter) . '"><button>Refresh directly from child</button></form>';
    echo '</div>';

    if (!$peer) {
        echo CatalogUi::alert('warning', 'The selected child peer does not exist or is inactive.', 'Child unavailable');
        catalog_foot();
        exit;
    }

    $absenceSql = pi_local_absence_sql('pf');
    $neededSql = pi_needed_sql('pf');
    $summary = catalog_one(
        $db,
        'SELECT
            COUNT(*) unavailable,
            SUM(CASE WHEN ' . $neededSql . ' THEN 1 ELSE 0 END) needed,
            SUM(CASE WHEN NOT (' . $neededSql . ') THEN 1 ELSE 0 END) other_missing,
            MAX(pf.last_seen_at) last_received_at
         FROM ue_federation_peer_files pf
         WHERE pf.peer_id=? AND ' . $absenceSql,
        [$peerId]
    ) ?: [];

    echo '<div class="card"><h2>' . catalog_h($peer['site_name']) . '</h2><table>';
    echo '<tr><th>Child URL</th><td class="mono path">' . catalog_h($peer['site_url']) . '</td></tr>';
    echo '<tr><th>Needed dependency files</th><td>' . (int)($summary['needed'] ?? 0) . '</td></tr>';
    echo '<tr><th>Other files parent lacks</th><td>' . (int)($summary['other_missing'] ?? 0) . '</td></tr>';
    echo '<tr><th>Total files parent lacks</th><td>' . (int)($summary['unavailable'] ?? 0) . '</td></tr>';
    echo '<tr><th>Last inventory synchronized</th><td>' . catalog_h((string)($summary['last_received_at'] ?? 'never')) . '</td></tr>';
    echo '</table></div>';

    $filterSql = '';
    if ($filter === 'needed') {
        $filterSql = ' AND ' . $neededSql;
    } elseif ($filter === 'missing') {
        $filterSql = ' AND NOT (' . $neededSql . ')';
    }
    $rows = catalog_all(
        $db,
        'SELECT pf.*,
                CASE WHEN ' . $neededSql . ' THEN "needed" ELSE "missing" END availability_type
         FROM ue_federation_peer_files pf
         WHERE pf.peer_id=? AND ' . $absenceSql . $filterSql . '
         ORDER BY FIELD(availability_type,"needed","missing"), pf.remote_game_name, pf.package_name, pf.original_name
         LIMIT 500',
        [$peerId]
    );

    echo '<div class="card"><h2>' . catalog_h(match ($filter) {
        'needed' => 'Needed dependency files',
        'missing' => 'Other missing files',
        default => 'All files the parent does not have',
    }) . '</h2>';
    if (!$rows) {
        echo '<p class="muted">No matching child files were found.</p>';
    } else {
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peer_inventory')) . '"><input type="hidden" name="action" value="queue"><input type="hidden" name="peer_id" value="' . $peerId . '"><input type="hidden" name="filter" value="' . catalog_h($filter) . '">';
        echo '<p><button>Queue selected downloads from child</button></p>';
        echo '<table><tr><th>Select</th><th>Type</th><th>Game</th><th>Package</th><th>File</th><th>GUID</th><th>MD5</th><th>Size</th><th>Last seen</th></tr>';
        foreach ($rows as $row) {
            $type = (string)$row['availability_type'];
            echo '<tr><td><input type="checkbox" name="peer_file_ids[]" value="' . (int)$row['id'] . '"></td><td><span class="pill ' . ($type === 'needed' ? 'amber' : '') . '">' . catalog_h($type) . '</span></td><td>' . catalog_h($row['remote_game_name']) . '</td><td class="mono">' . catalog_h($row['package_name']) . '</td><td>' . catalog_h($row['original_name']) . '</td><td class="mono small">' . catalog_h($row['package_guid']) . '</td><td class="mono small">' . catalog_h($row['md5']) . '</td><td>' . catalog_h(catalog_bytes((int)$row['file_size'])) . '</td><td>' . catalog_h($row['last_seen_at']) . '</td></tr>';
        }
        echo '</table><p><button>Queue selected downloads from child</button></p></form>';
        if (count($rows) === 500) {
            echo '<p class="muted">Showing the first 500 matching files.</p>';
        }
    }
    echo '</div>';

    $jobs = catalog_all(
        $db,
        'SELECT j.* FROM ue_federation_transfer_jobs j
         WHERE j.peer_id=? AND j.direction="parent_pull_from_child"
         ORDER BY j.created_at DESC LIMIT 100',
        [$peerId]
    );
    echo '<div class="card"><h2>Recent parent downloads from this child</h2>';
    if (!$jobs) {
        echo '<p class="muted">No parent download jobs have been queued for this child.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Remote file</th><th>Status</th><th>Bytes</th><th>Message</th><th>Created</th></tr>';
        foreach ($jobs as $job) {
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td class="mono">' . catalog_h($job['remote_file_id']) . '</td><td>' . catalog_h($job['status']) . '</td><td>' . catalog_h((int)$job['bytes_done'] . ' / ' . (int)$job['bytes_total']) . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td>' . catalog_h($job['created_at']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Peer inventory error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
