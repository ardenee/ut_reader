<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationInventory.php';

const PI_PAGE_SIZE = 100;

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

function pi_url(int $peerId, string $filter, string $tab, int $page): string
{
    return 'peer-inventory.php?' . http_build_query([
        'peer_id' => $peerId,
        'filter' => $filter,
        'tab' => $tab,
        'page' => $page,
    ]);
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

function pi_pagination(int $peerId, string $filter, string $tab, int $page, int $pages): void
{
    if ($pages <= 1) {
        return;
    }
    echo '<p class="page-links">';
    if ($page > 1) {
        echo '<a class="button" href="' . catalog_h(pi_url($peerId, $filter, $tab, $page - 1)) . '">Previous</a> ';
    }
    echo '<span>Page ' . $page . ' of ' . $pages . '</span> ';
    if ($page < $pages) {
        echo '<a class="button" href="' . catalog_h(pi_url($peerId, $filter, $tab, $page + 1)) . '">Next</a>';
    }
    echo '</p>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $siteRole = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        if ($siteRole !== 'parent') {
            throw new RuntimeException('Child inventories are available only while this server is in Parent mode. A child server cannot have children.');
        }
        catalog_check_csrf('fed_peer_inventory');
        $peerId = (int)($_POST['peer_id'] ?? 0);
        $filter = pi_filter_value((string)($_POST['filter'] ?? 'all'));
        $tab = pi_tab_value((string)($_POST['tab'] ?? 'parent'));
        $page = pi_page_value($_POST['page'] ?? 1);
        $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="child" AND is_active=1', [$peerId]);
        if (!$peer) {
            throw new RuntimeException('Active child connection not found.');
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
            $rows = catalog_all(
                $db,
                'SELECT pf.* FROM ue_federation_peer_files pf
                 WHERE pf.peer_id=? AND pf.id IN (' . $placeholders . ')
                   AND ' . pi_local_absence_sql('pf'),
                array_merge([$peerId], $ids)
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
                       AND status IN ("queued","running","downloaded") LIMIT 1',
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
            throw new RuntimeException('Unknown child inventory action.');
        }

        header('Location: ' . pi_url($peerId, $filter, $tab, $page));
        exit;
    }

    if (!catalog_require_admin_page('Child Inventories')) {
        exit;
    }

    $children = catalog_all(
        $db,
        'SELECT p.*,
                (SELECT COUNT(*) FROM ue_federation_peer_files pf WHERE pf.peer_id=p.id) cached_files,
                (SELECT MAX(pf.last_seen_at) FROM ue_federation_peer_files pf WHERE pf.peer_id=p.id) inventory_seen_at,
                (SELECT COUNT(*) FROM ue_federation_requests r WHERE r.peer_id=p.id AND r.direction="child_to_parent") request_count
         FROM ue_federation_peers p
         WHERE p.peer_role="child"
         ORDER BY p.is_active DESC, p.site_name, p.id'
    );
    $activeChildren = array_values(array_filter($children, static fn(array $row): bool => (int)$row['is_active'] === 1));
    $peerId = (int)($_GET['peer_id'] ?? ($activeChildren[0]['id'] ?? 0));
    $filter = pi_filter_value((string)($_GET['filter'] ?? 'all'));
    $tab = pi_tab_value((string)($_GET['tab'] ?? 'parent'));
    $page = pi_page_value($_GET['page'] ?? 1);
    $peer = $peerId > 0
        ? catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="child"', [$peerId])
        : null;
    $syncError = '';

    if ($siteRole === 'parent' && $peer && (int)$peer['is_active'] === 1) {
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

    catalog_head('Child Inventories');
    catalog_flash($_SESSION['fed_peer_inventory_flash'] ?? null);
    unset($_SESSION['fed_peer_inventory_flash']);
    catalog_page_header(
        'Child Inventories',
        'Parent-side view of connected child catalogs, missing files, dependency needs, incoming requests, and parent pull actions.',
        catalog_federation_links() + ['Children' => 'peers.php?role=child', 'Incoming Requests' => 'requests.php', 'Parent Pull' => 'parent-pull.php', 'Transfer Queue' => 'queue.php']
    );

    echo '<div class="card"><h2>Server mode</h2><p>This server is running in <strong>' . catalog_h(ucfirst($siteRole)) . '</strong> mode.</p></div>';
    if ($siteRole !== 'parent') {
        echo '<div class="card"><h2>Child inventories unavailable</h2>';
        if ($siteRole === 'child') {
            echo '<p>A Child server cannot have children, so no child inventory list exists here. This child can view its parent connection and outgoing file requests instead.</p>';
            echo '<p><a class="button" href="peers.php?role=parent">View Parent</a> <a class="button" href="request-status.php">Outgoing Requests</a> <a class="button" href="approved-downloads.php">Approved Downloads</a></p>';
        } else {
            echo '<p>Change this server to Parent mode before accepting children or reading child inventories.</p><p><a class="button" href="settings.php">Federation Settings</a></p>';
        }
        echo '</div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card"><h2>Children</h2>';
    if (!$children) {
        echo '<p class="muted">No child sites are connected. Approve incoming child join requests to add children.</p><p><a class="button" href="join-requests.php">Incoming Child Join Requests</a></p>';
        echo '</div>';
        catalog_foot();
        exit;
    }
    echo '<table><tr><th>Child</th><th>URL</th><th>Active</th><th>Cached files</th><th>Requests</th><th>Last inventory</th><th>Actions</th></tr>';
    foreach ($children as $child) {
        $view = 'peer-inventory.php?peer_id=' . (int)$child['id'];
        echo '<tr><td><strong>' . catalog_h($child['site_name']) . '</strong></td><td class="mono path">' . catalog_h($child['site_url']) . '</td><td>' . ((int)$child['is_active'] === 1 ? 'yes' : 'no') . '</td><td>' . (int)$child['cached_files'] . '</td><td>' . (int)$child['request_count'] . '</td><td class="nowrap">' . catalog_h($child['inventory_seen_at'] ?? 'never') . '</td><td><a href="' . catalog_h($view) . '">View inventory</a> · <a href="requests.php">Incoming requests</a> · <a href="parent-pull.php?peer_id=' . (int)$child['id'] . '">Parent pull</a> · <a href="peers.php?role=child">Manage</a></td></tr>';
    }
    echo '</table></div>';

    if ($syncError !== '') {
        echo CatalogUi::alert('warning', $syncError, 'Child inventory synchronization failed');
    }
    if (!$peer) {
        echo '<div class="card"><h2>Select an active child</h2><p>No active child is selected.</p></div>';
        catalog_foot();
        exit;
    }
    if ((int)$peer['is_active'] !== 1) {
        echo '<div class="card"><h2>Child disabled</h2><p>This child connection is disabled. Enable it on the Children page before refreshing or downloading files.</p><p><a class="button" href="peers.php?role=child">Manage Children</a></p></div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card"><h2>Selected child: ' . catalog_h($peer['site_name']) . '</h2>';
    echo '<form method="get" action="peer-inventory.php"><label>Child<br><select name="peer_id">';
    foreach ($activeChildren as $row) {
        $selected = (int)$row['id'] === $peerId ? ' selected' : '';
        echo '<option value="' . (int)$row['id'] . '"' . $selected . '>' . catalog_h($row['site_name'] . ' - ' . $row['site_url']) . '</option>';
    }
    echo '</select></label> <label>Parent needs<br><select name="filter">';
    foreach (['all' => 'All files parent lacks', 'needed' => 'Needed dependency files', 'missing' => 'Other missing files'] as $value => $label) {
        echo '<option value="' . $value . '"' . ($filter === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label><input type="hidden" name="tab" value="' . catalog_h($tab) . '"><button>View</button></form>';
    echo '<form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peer_inventory')) . '"><input type="hidden" name="action" value="refresh"><input type="hidden" name="peer_id" value="' . $peerId . '"><input type="hidden" name="filter" value="' . catalog_h($filter) . '"><input type="hidden" name="tab" value="' . catalog_h($tab) . '"><input type="hidden" name="page" value="' . $page . '"><button>Refresh directly from child</button></form></div>';

    $absenceSql = pi_local_absence_sql('pf');
    $neededSql = pi_needed_sql('pf');
    $needCountSql = pi_need_count_sql('pf');
    $summary = catalog_one(
        $db,
        'SELECT COUNT(*) unavailable,
                SUM(CASE WHEN ' . $neededSql . ' THEN 1 ELSE 0 END) needed,
                SUM(CASE WHEN NOT (' . $neededSql . ') THEN 1 ELSE 0 END) other_missing,
                MAX(pf.last_seen_at) last_received_at
         FROM ue_federation_peer_files pf
         WHERE pf.peer_id=? AND ' . $absenceSql,
        [$peerId]
    ) ?: [];
    $latestRequest = catalog_one(
        $db,
        'SELECT * FROM ue_federation_requests WHERE peer_id=? AND direction="child_to_parent" ORDER BY created_at DESC, id DESC LIMIT 1',
        [$peerId]
    );
    $childMatchedCount = $latestRequest
        ? (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_request_items WHERE request_id=? AND local_file_id IS NOT NULL', [(int)$latestRequest['id']])['c'] ?? 0)
        : 0;

    echo '<div class="card"><h2>Inventory summary</h2><table>';
    echo '<tr><th>Files parent needs for dependencies</th><td>' . (int)($summary['needed'] ?? 0) . '</td></tr>';
    echo '<tr><th>Other child files parent lacks</th><td>' . (int)($summary['other_missing'] ?? 0) . '</td></tr>';
    echo '<tr><th>Total files parent lacks</th><td>' . (int)($summary['unavailable'] ?? 0) . '</td></tr>';
    echo '<tr><th>Parent files matched to latest child request</th><td>' . $childMatchedCount . '</td></tr>';
    echo '<tr><th>Last inventory synchronized</th><td>' . catalog_h((string)($summary['last_received_at'] ?? 'never')) . '</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><p class="page-links"><a class="button" href="' . catalog_h(pi_url($peerId, $filter, 'parent', 1)) . '">Parent needs from child</a> <a class="button" href="' . catalog_h(pi_url($peerId, $filter, 'child', 1)) . '">Child needs from parent</a></p>';
    if ($tab === 'parent') {
        $filterSql = $filter === 'needed' ? ' AND ' . $neededSql : ($filter === 'missing' ? ' AND NOT (' . $neededSql . ')' : '');
        $totalRows = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peer_files pf WHERE pf.peer_id=? AND ' . $absenceSql . $filterSql, [$peerId])['c'] ?? 0);
        $pages = max(1, (int)ceil($totalRows / PI_PAGE_SIZE));
        $page = min($page, $pages);
        $offset = ($page - 1) * PI_PAGE_SIZE;
        $rows = catalog_all(
            $db,
            'SELECT pf.*, g.name local_game_name, ' . $needCountSql . ' needed_by_files,
                    CASE WHEN ' . $neededSql . ' THEN "needed" ELSE "missing" END availability_type
             FROM ue_federation_peer_files pf
             LEFT JOIN ue_games g ON g.id=pf.game_id
             WHERE pf.peer_id=? AND ' . $absenceSql . $filterSql . '
             ORDER BY FIELD(availability_type,"needed","missing"), needed_by_files DESC, COALESCE(g.name,pf.remote_game_name), pf.package_name, pf.original_name
             LIMIT ' . PI_PAGE_SIZE . ' OFFSET ' . $offset,
            [$peerId]
        );
        echo '<h2>Files this parent does not have</h2>';
        if (!$rows) {
            echo '<p class="muted">No matching child files were found.</p>';
        } else {
            echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peer_inventory')) . '"><input type="hidden" name="action" value="queue"><input type="hidden" name="peer_id" value="' . $peerId . '"><input type="hidden" name="filter" value="' . catalog_h($filter) . '"><input type="hidden" name="tab" value="parent"><input type="hidden" name="page" value="' . $page . '">';
            echo '<p><label><input type="checkbox" data-check-all="parent-files"> Check all on this page</label> <button>Queue selected downloads from child</button></p>';
            echo '<table><tr><th>Select</th><th>Type</th><th>Game</th><th>Needed by files</th><th>Package</th><th>File</th><th>GUID / MD5 / SHA1</th><th>Size</th></tr>';
            foreach ($rows as $row) {
                $type = (string)$row['availability_type'];
                $gameName = trim((string)($row['local_game_name'] ?? '')) ?: (string)$row['remote_game_name'];
                echo '<tr><td><input type="checkbox" data-check-group="parent-files" name="peer_file_ids[]" value="' . (int)$row['id'] . '"></td><td><span class="pill ' . ($type === 'needed' ? 'amber' : '') . '">' . catalog_h($type) . '</span></td><td>' . catalog_h($gameName) . '</td><td>' . (int)$row['needed_by_files'] . '</td><td class="mono">' . catalog_h($row['package_name']) . '</td><td>' . catalog_h($row['original_name']) . '</td><td>' . pi_identity_html($row) . '</td><td class="nowrap">' . catalog_h(catalog_bytes((int)$row['file_size'])) . '</td></tr>';
            }
            echo '</table><p><button>Queue selected downloads from child</button></p></form>';
            pi_pagination($peerId, $filter, $tab, $page, $pages);
        }
    } else {
        echo '<h2>Files this child requested from the parent</h2>';
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
                 ORDER BY i.updated_at DESC, i.id DESC
                 LIMIT ' . PI_PAGE_SIZE . ' OFFSET ' . $offset,
                [(int)$latestRequest['id']]
            );
            echo '<p>Latest incoming child request: <a href="requests.php?request_id=' . (int)$latestRequest['id'] . '">#' . (int)$latestRequest['id'] . '</a> — ' . catalog_h($latestRequest['status']) . '</p>';
            if (!$childRows) {
                echo '<p class="muted">The parent does not currently have any matching files for this request.</p>';
            } else {
                echo '<table><tr><th>Status</th><th>Game</th><th>Child requires</th><th>Parent package</th><th>Parent file</th><th>GUID / MD5 / SHA1</th><th>Size</th><th>Message</th></tr>';
                foreach ($childRows as $row) {
                    echo '<tr><td>' . catalog_h($row['status']) . '</td><td>' . catalog_h($row['game_name']) . '</td><td><div class="mono">' . catalog_h($row['required_package']) . '</div><div class="mono small path">' . catalog_h($row['required_object_path']) . '</div></td><td class="mono"><a href="../file-info.php?id=' . (int)$row['parent_file_id'] . '">' . catalog_h($row['parent_package']) . '</a></td><td><a href="../file-examine.php?id=' . (int)$row['parent_file_id'] . '">' . catalog_h($row['parent_file']) . '</a></td><td>' . pi_identity_html($row) . '</td><td class="nowrap">' . catalog_h(catalog_bytes((int)$row['file_size'])) . '</td><td class="path">' . nl2br(catalog_h($row['status_message'])) . '</td></tr>';
                }
                echo '</table>';
                pi_pagination($peerId, $filter, $tab, $page, $pages);
            }
        }
    }
    echo '</div>';

    $jobs = catalog_all(
        $db,
        'SELECT j.*, pf.package_name, pf.original_name
         FROM ue_federation_transfer_jobs j
         LEFT JOIN ue_federation_peer_files pf ON pf.peer_id=j.peer_id AND pf.remote_file_id=j.remote_file_id
         WHERE j.peer_id=? AND j.direction="parent_pull_from_child"
         ORDER BY j.created_at DESC, j.id DESC LIMIT 100',
        [$peerId]
    );
    echo '<div class="card"><h2>Recent parent downloads from this child</h2>';
    if (!$jobs) {
        echo '<p class="muted">No parent pull jobs have been queued for this child.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Package / file</th><th>Status</th><th>Bytes</th><th>Message</th><th>Created</th></tr>';
        foreach ($jobs as $job) {
            $fileLabel = trim((string)($job['package_name'] ?? '') . ' / ' . (string)($job['original_name'] ?? ''), ' /');
            if ($fileLabel === '') {
                $fileLabel = 'Remote file #' . (int)$job['remote_file_id'];
            }
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($fileLabel) . '</td><td>' . catalog_h($job['status']) . '</td><td class="nowrap">' . catalog_h((int)$job['bytes_done'] . ' / ' . (int)$job['bytes_total']) . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td class="nowrap">' . catalog_h($job['created_at']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    echo '<script>(function(){document.querySelectorAll("[data-check-all]").forEach(function(master){master.addEventListener("change",function(){var group=master.getAttribute("data-check-all");document.querySelectorAll("[data-check-group=\""+group+"\"]").forEach(function(box){box.checked=master.checked;});});});})();</script>';
    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Child inventories error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
