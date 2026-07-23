<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationInventory.php';

const PI_PAGE_SIZE = 950;

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

function pi_need_count_sql(string $peerAlias = 'pf'): string
{
    return '(SELECT COUNT(DISTINCT needer.id)
        FROM ue_dependencies d
        JOIN ue_files needer ON needer.id=d.file_id
        WHERE d.status="missing"
          AND needer.scan_status="verified"
          AND ' . $peerAlias . '.game_id IS NOT NULL
          AND needer.game_id=' . $peerAlias . '.game_id
          AND d.required_package=' . $peerAlias . '.package_name)';
}

function pi_needed_sql(string $peerAlias = 'pf'): string
{
    return pi_need_count_sql($peerAlias) . ' > 0';
}

function pi_filter_value(string $value): string
{
    return in_array($value, ['all', 'needed', 'missing'], true) ? $value : 'all';
}

function pi_tab_value(string $value): string
{
    return in_array($value, ['parent', 'child'], true) ? $value : 'parent';
}

function pi_page_value(mixed $value): int
{
    return max(1, (int)$value);
}

/** @param array<string,string|int> $overrides */
function pi_url(int $peerId, string $filter, string $inventoryTab, string $downloadsTab, int $page, array $overrides = []): string
{
    $query = array_merge([
        'peer_id' => $peerId,
        'filter' => $filter,
        'inventory_tab' => $inventoryTab,
        'downloads_tab' => $downloadsTab,
        'page' => $page,
    ], $overrides);
    return 'peer-inventory.php?' . http_build_query($query);
}

function pi_identity_html(array $row): string
{
    $guid = trim((string)($row['package_guid'] ?? ''));
    $md5 = trim((string)($row['md5'] ?? ''));
    $sha1 = trim((string)($row['sha1'] ?? ''));
    return '<div class="mono small nowrap"><strong>GUID:</strong> ' . catalog_h($guid !== '' ? $guid : '—') . '</div>'
        . '<div class="mono small nowrap"><strong>MD5:</strong> ' . catalog_h($md5 !== '' ? $md5 : '—') . '</div>'
        . '<div class="mono small nowrap"><strong>SHA1:</strong> ' . catalog_h($sha1 !== '' ? $sha1 : '—') . '</div>';
}

function pi_pagination(int $peerId, string $filter, string $inventoryTab, string $downloadsTab, int $page, int $pages): void
{
    if ($pages <= 1) {
        return;
    }
    echo '<p class="page-links">';
    if ($page > 1) {
        echo '<a class="button" href="' . catalog_h(pi_url($peerId, $filter, $inventoryTab, $downloadsTab, $page - 1)) . '">Previous</a> ';
    }
    echo '<span>Page ' . $page . ' of ' . $pages . '</span> ';
    if ($page < $pages) {
        echo '<a class="button" href="' . catalog_h(pi_url($peerId, $filter, $inventoryTab, $downloadsTab, $page + 1)) . '">Next</a>';
    }
    echo '</p>';
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
        $inventoryTab = pi_tab_value((string)($_POST['inventory_tab'] ?? 'parent'));
        $downloadsTab = pi_tab_value((string)($_POST['downloads_tab'] ?? 'parent'));
        $page = pi_page_value($_POST['page'] ?? 1);
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
            if (count($ids) > PI_PAGE_SIZE) {
                throw new RuntimeException('Select no more than ' . PI_PAGE_SIZE . ' files at once.');
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

        header('Location: ' . pi_url($peerId, $filter, $inventoryTab, $downloadsTab, $page));
        exit;
    }

    if (!catalog_require_admin_page('Peer Inventory')) {
        exit;
    }

    $peers = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="child" AND is_active=1 ORDER BY site_name');
    $peerId = (int)($_GET['peer_id'] ?? ($peers[0]['id'] ?? 0));
    $filter = pi_filter_value((string)($_GET['filter'] ?? 'all'));
    $inventoryTab = pi_tab_value((string)($_GET['inventory_tab'] ?? 'parent'));
    $downloadsTab = pi_tab_value((string)($_GET['downloads_tab'] ?? 'parent'));
    $page = pi_page_value($_GET['page'] ?? 1);
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
        'Parent shows files each side needs. Parent downloads do not require child approval; child downloads remain restricted to approved missing dependencies.',
        catalog_federation_links() + ['Parent Pull Queue' => 'parent-pull.php', 'Child Requests' => 'requests.php', 'Run Transfer Queue' => 'transfer-run.php']
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
    echo '</select></label> <label>Parent tab filter<br><select name="filter">';
    foreach (['all' => 'All files parent lacks', 'needed' => 'Needed dependency files', 'missing' => 'Other missing files'] as $value => $label) {
        echo '<option value="' . $value . '"' . ($filter === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label>';
    echo '<input type="hidden" name="inventory_tab" value="' . catalog_h($inventoryTab) . '">';
    echo '<input type="hidden" name="downloads_tab" value="' . catalog_h($downloadsTab) . '">';
    echo '<button>View inventory</button></form>';
    echo '<form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peer_inventory')) . '"><input type="hidden" name="action" value="refresh"><input type="hidden" name="peer_id" value="' . $peerId . '"><input type="hidden" name="filter" value="' . catalog_h($filter) . '"><input type="hidden" name="inventory_tab" value="' . catalog_h($inventoryTab) . '"><input type="hidden" name="downloads_tab" value="' . catalog_h($downloadsTab) . '"><input type="hidden" name="page" value="' . $page . '"><button>Refresh directly from child</button></form>';
    echo '</div>';

    if (!$peer) {
        echo CatalogUi::alert('warning', 'The selected child peer does not exist or is inactive.', 'Child unavailable');
        catalog_foot();
        exit;
    }

    $absenceSql = pi_local_absence_sql('pf');
    $neededSql = pi_needed_sql('pf');
    $needCountSql = pi_need_count_sql('pf');
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

    $latestRequest = catalog_one(
        $db,
        'SELECT * FROM ue_federation_requests
         WHERE peer_id=? AND direction="child_to_parent"
         ORDER BY created_at DESC, id DESC LIMIT 1',
        [$peerId]
    );
    $childMatchedCount = $latestRequest
        ? (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_request_items WHERE request_id=? AND local_file_id IS NOT NULL', [(int)$latestRequest['id']])['c'] ?? 0)
        : 0;

    echo '<div class="card"><h2>' . catalog_h($peer['site_name']) . '</h2><table>';
    echo '<tr><th>Child URL</th><td class="mono path">' . catalog_h($peer['site_url']) . '</td></tr>';
    echo '<tr><th>Files parent needs for dependencies</th><td>' . (int)($summary['needed'] ?? 0) . '</td></tr>';
    echo '<tr><th>Other child files parent lacks</th><td>' . (int)($summary['other_missing'] ?? 0) . '</td></tr>';
    echo '<tr><th>Total files parent lacks</th><td>' . (int)($summary['unavailable'] ?? 0) . '</td></tr>';
    echo '<tr><th>Parent files matched to latest child request</th><td>' . $childMatchedCount . '</td></tr>';
    echo '<tr><th>Last inventory synchronized</th><td>' . catalog_h((string)($summary['last_received_at'] ?? 'never')) . '</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><div class="page-links" role="tablist" aria-label="Inventory direction">';
    echo '<a class="button" aria-current="' . ($inventoryTab === 'parent' ? 'page' : 'false') . '" href="' . catalog_h(pi_url($peerId, $filter, 'parent', $downloadsTab, 1)) . '"><strong>Parent</strong></a> ';
    echo '<a class="button" aria-current="' . ($inventoryTab === 'child' ? 'page' : 'false') . '" href="' . catalog_h(pi_url($peerId, $filter, 'child', $downloadsTab, 1)) . '"><strong>Child</strong></a>';
    echo '</div>';

    if ($inventoryTab === 'parent') {
        $filterSql = '';
        if ($filter === 'needed') {
            $filterSql = ' AND ' . $neededSql;
        } elseif ($filter === 'missing') {
            $filterSql = ' AND NOT (' . $neededSql . ')';
        }
        $totalRows = (int)(catalog_one(
            $db,
            'SELECT COUNT(*) c FROM ue_federation_peer_files pf WHERE pf.peer_id=? AND ' . $absenceSql . $filterSql,
            [$peerId]
        )['c'] ?? 0);
        $pages = max(1, (int)ceil($totalRows / PI_PAGE_SIZE));
        $page = min($page, $pages);
        $offset = ($page - 1) * PI_PAGE_SIZE;
        $rows = catalog_all(
            $db,
            'SELECT pf.*, g.name local_game_name,
                    ' . $needCountSql . ' needed_by_files,
                    CASE WHEN ' . $neededSql . ' THEN "needed" ELSE "missing" END availability_type
             FROM ue_federation_peer_files pf
             LEFT JOIN ue_games g ON g.id=pf.game_id
             WHERE pf.peer_id=? AND ' . $absenceSql . $filterSql . '
             ORDER BY FIELD(availability_type,"needed","missing"), needed_by_files DESC, COALESCE(g.name,pf.remote_game_name), pf.package_name, pf.original_name
             LIMIT ' . PI_PAGE_SIZE . ' OFFSET ' . $offset,
            [$peerId]
        );

        echo '<h2>' . catalog_h(match ($filter) {
            'needed' => 'Files the parent needs for dependencies',
            'missing' => 'Other files the parent does not have',
            default => 'Files the parent does not have',
        }) . '</h2>';
        echo '<p class="muted">The “Needed by files” count is restricted to missing dependencies in the mapped parent game.</p>';
        if (!$rows) {
            echo '<p class="muted">No matching child files were found.</p>';
        } else {
            echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peer_inventory')) . '"><input type="hidden" name="action" value="queue"><input type="hidden" name="peer_id" value="' . $peerId . '"><input type="hidden" name="filter" value="' . catalog_h($filter) . '"><input type="hidden" name="inventory_tab" value="parent"><input type="hidden" name="downloads_tab" value="' . catalog_h($downloadsTab) . '"><input type="hidden" name="page" value="' . $page . '">';
            echo '<p><label><input type="checkbox" data-check-all="parent-files"> Check all on this page</label> <button>Queue selected downloads from child</button></p>';
            echo '<table><tr><th>Select</th><th>Type</th><th>Game</th><th>Needed by files</th><th>Package</th><th>File</th><th>GUID / MD5 / SHA1</th><th>Size</th></tr>';
            foreach ($rows as $row) {
                $type = (string)$row['availability_type'];
                $gameName = trim((string)($row['local_game_name'] ?? '')) ?: (string)$row['remote_game_name'];
                echo '<tr><td><input type="checkbox" data-check-group="parent-files" name="peer_file_ids[]" value="' . (int)$row['id'] . '"></td><td><span class="pill ' . ($type === 'needed' ? 'amber' : '') . '">' . catalog_h($type) . '</span></td><td>' . catalog_h($gameName) . '</td><td>' . (int)$row['needed_by_files'] . '</td><td class="mono">' . catalog_h($row['package_name']) . '</td><td>' . catalog_h($row['original_name']) . '</td><td>' . pi_identity_html($row) . '</td><td class="nowrap">' . catalog_h(catalog_bytes((int)$row['file_size'])) . '</td></tr>';
            }
            echo '</table><p><button>Queue selected downloads from child</button></p></form>';
            pi_pagination($peerId, $filter, $inventoryTab, $downloadsTab, $page, $pages);
        }
    } else {
        echo '<h2>Files the parent has that the child needs</h2>';
        if (!$latestRequest) {
            echo '<p class="muted">This child has not submitted a dependency request.</p>';
        } else {
            $childTotal = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_request_items WHERE request_id=? AND local_file_id IS NOT NULL', [(int)$latestRequest['id']])['c'] ?? 0);
            $pages = max(1, (int)ceil($childTotal / PI_PAGE_SIZE));
            $page = min($page, $pages);
            $offset = ($page - 1) * PI_PAGE_SIZE;
            $childRows = catalog_all(
                $db,
                'SELECT i.*, f.id parent_file_id, f.package_name parent_package, f.original_name parent_file,
                        f.package_guid, f.md5, f.sha1, f.file_size, g.name game_name
                 FROM ue_federation_request_items i
                 JOIN ue_files f ON f.id=i.local_file_id
                 JOIN ue_games g ON g.id=f.game_id
                 WHERE i.request_id=?
                 ORDER BY FIELD(i.status,"requested","approved","queued","downloading","downloaded","imported","failed","denied","skipped_already_have"), g.name, f.package_name, f.original_name
                 LIMIT ' . PI_PAGE_SIZE . ' OFFSET ' . $offset,
                [(int)$latestRequest['id']]
            );
            echo '<p>Latest child request: <a href="requests.php?request_id=' . (int)$latestRequest['id'] . '">#' . (int)$latestRequest['id'] . '</a> — ' . catalog_h($latestRequest['status']) . '</p>';
            if (!$childRows) {
                echo '<p class="muted">The parent does not currently have any matching files for this request.</p>';
            } else {
                echo '<table><tr><th>Status</th><th>Game</th><th>Child requires</th><th>Parent package</th><th>Parent file</th><th>GUID / MD5 / SHA1</th><th>Size</th><th>Message</th></tr>';
                foreach ($childRows as $row) {
                    echo '<tr><td>' . catalog_h($row['status']) . '</td><td>' . catalog_h($row['game_name']) . '</td><td><div class="mono">' . catalog_h($row['required_package']) . '</div><div class="mono small path">' . catalog_h($row['required_object_path']) . '</div></td><td class="mono"><a href="../file-info.php?id=' . (int)$row['parent_file_id'] . '">' . catalog_h($row['parent_package']) . '</a></td><td><a href="../file-examine.php?id=' . (int)$row['parent_file_id'] . '">' . catalog_h($row['parent_file']) . '</a></td><td>' . pi_identity_html($row) . '</td><td class="nowrap">' . catalog_h(catalog_bytes((int)$row['file_size'])) . '</td><td class="path">' . nl2br(catalog_h($row['status_message'])) . '</td></tr>';
                }
                echo '</table>';
                pi_pagination($peerId, $filter, $inventoryTab, $downloadsTab, $page, $pages);
            }
        }
    }
    echo '</div>';

    echo '<div class="card"><div class="page-links" role="tablist" aria-label="Download direction">';
    echo '<a class="button" aria-current="' . ($downloadsTab === 'parent' ? 'page' : 'false') . '" href="' . catalog_h(pi_url($peerId, $filter, $inventoryTab, 'parent', $page)) . '"><strong>Parent downloads</strong></a> ';
    echo '<a class="button" aria-current="' . ($downloadsTab === 'child' ? 'page' : 'false') . '" href="' . catalog_h(pi_url($peerId, $filter, $inventoryTab, 'child', $page)) . '"><strong>Child downloads</strong></a>';
    echo '</div>';

    if ($downloadsTab === 'parent') {
        $jobs = catalog_all(
            $db,
            'SELECT j.*, pf.package_name, pf.original_name
             FROM ue_federation_transfer_jobs j
             LEFT JOIN ue_federation_peer_files pf ON pf.peer_id=j.peer_id AND pf.remote_file_id=j.remote_file_id
             WHERE j.peer_id=? AND j.direction="parent_pull_from_child"
             ORDER BY j.created_at DESC LIMIT 100',
            [$peerId]
        );
        echo '<h2>Downloads the parent got from this child</h2>';
        if (!$jobs) {
            echo '<p class="muted">No parent download jobs have been queued for this child.</p>';
        } else {
            echo '<table><tr><th>ID</th><th>Package / file</th><th>Status</th><th>Bytes</th><th>Message</th><th>Created</th></tr>';
            foreach ($jobs as $job) {
                $fileLabel = trim((string)($job['package_name'] ?? '')) . ' / ' . trim((string)($job['original_name'] ?? ''));
                if (trim($fileLabel, ' /') === '') {
                    $fileLabel = 'Remote file #' . (int)$job['remote_file_id'];
                }
                echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($fileLabel) . '</td><td>' . catalog_h($job['status']) . '</td><td class="nowrap">' . catalog_h((int)$job['bytes_done'] . ' / ' . (int)$job['bytes_total']) . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td class="nowrap">' . catalog_h($job['created_at']) . '</td></tr>';
            }
            echo '</table>';
        }
    } else {
        $childDownloads = catalog_all(
            $db,
            'SELECT i.*, r.id request_id, f.package_name, f.original_name, f.file_size
             FROM ue_federation_request_items i
             JOIN ue_federation_requests r ON r.id=i.request_id
             LEFT JOIN ue_files f ON f.id=i.local_file_id
             WHERE r.peer_id=? AND r.direction="child_to_parent"
               AND i.status IN ("queued","downloading","downloaded","imported","failed","skipped_already_have")
             ORDER BY i.updated_at DESC LIMIT 100',
            [$peerId]
        );
        echo '<h2>Downloads the child got from the parent</h2>';
        if (!$childDownloads) {
            echo '<p class="muted">The child has not reported any completed or failed parent downloads.</p>';
        } else {
            echo '<table><tr><th>Request</th><th>Required package</th><th>Parent file</th><th>Status</th><th>Size</th><th>Message</th><th>Updated</th></tr>';
            foreach ($childDownloads as $item) {
                echo '<tr><td><a href="requests.php?request_id=' . (int)$item['request_id'] . '">#' . (int)$item['request_id'] . '</a></td><td class="mono">' . catalog_h($item['required_package']) . '</td><td>' . catalog_h(trim((string)($item['package_name'] ?? '')) . ' / ' . trim((string)($item['original_name'] ?? ''))) . '</td><td>' . catalog_h($item['status']) . '</td><td class="nowrap">' . catalog_h(catalog_bytes((int)($item['file_size'] ?? 0))) . '</td><td class="path">' . nl2br(catalog_h($item['status_message'])) . '</td><td class="nowrap">' . catalog_h($item['updated_at']) . '</td></tr>';
            }
            echo '</table>';
        }
    }
    echo '</div>';

    echo '<script>(function(){document.querySelectorAll("[data-check-all]").forEach(function(master){master.addEventListener("change",function(){var group=master.getAttribute("data-check-all");document.querySelectorAll("[data-check-group=\""+group+"\"]").forEach(function(box){box.checked=master.checked;});});});})();</script>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Peer inventory error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
