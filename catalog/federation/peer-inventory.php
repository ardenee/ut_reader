<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationInventory.php';
require_once __DIR__ . '/../lib/BaseGameProtection.php';

const PI_PAGE_SIZE = 100;

function pi_local_presence_sql(string $peerAlias = 'pf'): string
{
    return 'EXISTS (
        SELECT 1 FROM ue_files local
        WHERE local.scan_status="verified"
          AND (
            (' . $peerAlias . '.package_guid IS NOT NULL AND ' . $peerAlias . '.package_guid<>"" AND local.package_guid=' . $peerAlias . '.package_guid)
            OR
            (' . $peerAlias . '.md5 IS NOT NULL AND ' . $peerAlias . '.md5<>"" AND local.md5=' . $peerAlias . '.md5)
          )
    )';
}

function pi_local_absence_sql(string $peerAlias = 'pf'): string
{
    return 'NOT (' . pi_local_presence_sql($peerAlias) . ')';
}

function pi_need_count_sql(string $peerAlias = 'pf'): string
{
    return '(SELECT COUNT(DISTINCT needer.id)
        FROM ue_dependencies d
        JOIN ue_files needer ON needer.id=d.file_id
        JOIN ue_games needer_game ON needer_game.id=needer.game_id
        WHERE d.status="missing"
          AND needer.scan_status="verified"
          AND LOWER(d.required_package)=LOWER(' . $peerAlias . '.package_name)
          AND (
            (' . $peerAlias . '.remote_game_name IS NOT NULL AND ' . $peerAlias . '.remote_game_name<>"" AND needer_game.name=' . $peerAlias . '.remote_game_name)
            OR
            ((' . $peerAlias . '.remote_game_name IS NULL OR ' . $peerAlias . '.remote_game_name="")
             AND ' . $peerAlias . '.game_id IS NOT NULL
             AND needer.game_id=' . $peerAlias . '.game_id)
          ))';
}

function pi_needed_sql(string $peerAlias = 'pf'): string
{
    return pi_need_count_sql($peerAlias) . ' > 0';
}

function pi_base_game_sql(string $peerAlias = 'pf'): string
{
    return 'EXISTS (
        SELECT 1
        FROM ue_base_game_files bg
        JOIN ue_games bg_game ON bg_game.id=bg.game_id
        WHERE ' . $peerAlias . '.package_guid IS NOT NULL
          AND ' . $peerAlias . '.package_guid<>""
          AND bg.package_guid=' . $peerAlias . '.package_guid
          AND (
            (' . $peerAlias . '.remote_game_name IS NOT NULL AND ' . $peerAlias . '.remote_game_name<>"" AND bg_game.name=' . $peerAlias . '.remote_game_name)
            OR
            ((' . $peerAlias . '.remote_game_name IS NULL OR ' . $peerAlias . '.remote_game_name="")
             AND ' . $peerAlias . '.game_id IS NOT NULL
             AND bg.game_id=' . $peerAlias . '.game_id)
          )
    )';
}

function pi_filter_value(string $value): string
{
    return in_array($value, ['needed', 'missing', 'present', 'all'], true) ? $value : 'needed';
}

function pi_view_value(string $value): string
{
    return match (strtolower(trim($value))) {
        'requests', 'child' => 'requests',
        'inventory', 'parent', '' => 'inventory',
        default => 'inventory',
    };
}

function pi_page_value(mixed $value): int
{
    return max(1, (int)$value);
}

function pi_url(int $peerId, string $filter, string $view, int $page): string
{
    return 'peer-inventory.php?' . http_build_query([
        'peer_id' => $peerId,
        'filter' => $filter,
        'tab' => $view,
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

function pi_pagination(int $peerId, string $filter, string $view, int $page, int $pages): void
{
    if ($pages <= 1) {
        return;
    }
    echo '<p class="page-links">';
    if ($page > 1) {
        echo '<a class="button" href="' . catalog_h(pi_url($peerId, $filter, $view, $page - 1)) . '">Previous</a> ';
    }
    echo '<span>Page ' . $page . ' of ' . $pages . '</span> ';
    if ($page < $pages) {
        echo '<a class="button" href="' . catalog_h(pi_url($peerId, $filter, $view, $page + 1)) . '">Next</a>';
    }
    echo '</p>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);
    $siteRole = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        if ($siteRole !== 'parent') {
            throw new RuntimeException('Child inventories are available only while this server is in Parent mode.');
        }
        catalog_check_csrf('fed_peer_inventory');

        $peerId = (int)($_POST['peer_id'] ?? 0);
        $filter = pi_filter_value((string)($_POST['filter'] ?? 'needed'));
        $view = pi_view_value((string)($_POST['tab'] ?? $_POST['inventory_tab'] ?? 'inventory'));
        $page = pi_page_value($_POST['page'] ?? 1);
        $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="child" AND is_active=1', [$peerId]);
        if (!$peer) {
            throw new RuntimeException('Active child connection not found.');
        }

        $action = (string)($_POST['action'] ?? 'refresh');
        if ($action === 'refresh') {
            $result = federation_pull_inventory_from_child($db, $peerId);
            $_SESSION['fed_peer_inventory_flash'] = 'Inventory refreshed from ' . (string)$peer['site_name'] . ': ' . (int)$result['received'] . ' file row(s), ' . (int)$result['removed_stale'] . ' stale row(s) removed.';
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
                'SELECT pf.*
                 FROM ue_federation_peer_files pf
                 WHERE pf.peer_id=?
                   AND pf.id IN (' . $placeholders . ')
                   AND ' . pi_local_absence_sql('pf') . '
                   AND NOT (' . pi_base_game_sql('pf') . ')',
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
            $_SESSION['fed_peer_inventory_flash'] = 'Queued ' . $queued . ' file(s) from this child. Files already present locally and official base-game files were skipped.';
        } else {
            throw new RuntimeException('Unknown child inventory action.');
        }

        header('Location: ' . pi_url($peerId, $filter, $view, $page));
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
    $filter = pi_filter_value((string)($_GET['filter'] ?? 'needed'));
    $view = pi_view_value((string)($_GET['tab'] ?? $_GET['inventory_tab'] ?? 'inventory'));
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
        'Select a child and immediately see the actual files this parent needs from it. Other inventory views remain available as filters.',
        catalog_federation_links() + ['Children' => 'peers.php?role=child', 'Incoming Requests' => 'requests.php', 'Transfer Queue' => 'queue.php']
    );

    echo '<div class="card"><h2>Server mode</h2><p>This server is running in <strong>' . catalog_h(ucfirst($siteRole)) . '</strong> mode.</p></div>';
    if ($siteRole !== 'parent') {
        echo '<div class="card"><h2>No child inventories on this server</h2>';
        if ($siteRole === 'child') {
            echo '<p>A child server cannot have children. Use Outgoing Requests and Approved Downloads for the child-side workflow.</p><p><a class="button" href="request-status.php">Outgoing Requests</a> <a class="button" href="approved-downloads.php">Approved Downloads</a></p>';
        } else {
            echo '<p>Change this server to Parent mode before accepting children.</p><p><a class="button" href="settings.php">Federation Settings</a></p>';
        }
        echo '</div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card"><h2>Connected children</h2>';
    if (!$children) {
        echo '<p class="muted">No child sites are connected.</p><p><a class="button" href="join-requests.php">Incoming Child Join Requests</a></p></div>';
        catalog_foot();
        exit;
    }
    echo '<table><tr><th>Child</th><th>Active</th><th>Cached files</th><th>Requests</th><th>Inventory updated</th><th>Open</th></tr>';
    foreach ($children as $child) {
        $viewUrl = pi_url((int)$child['id'], 'needed', 'inventory', 1);
        echo '<tr><td><strong>' . catalog_h($child['site_name']) . '</strong><div class="mono small path">' . catalog_h($child['site_url']) . '</div></td><td>' . ((int)$child['is_active'] === 1 ? 'yes' : 'no') . '</td><td>' . (int)$child['cached_files'] . '</td><td>' . (int)$child['request_count'] . '</td><td class="nowrap">' . catalog_h($child['inventory_seen_at'] ?? 'never') . '</td><td><a href="' . catalog_h($viewUrl) . '">Show files parent needs</a> · <a href="requests.php">Requests</a> · <a href="peers.php?role=child">Manage</a></td></tr>';
    }
    echo '</table></div>';

    if ($syncError !== '') {
        echo CatalogUi::alert('warning', $syncError, 'Child inventory synchronization failed');
    }
    if (!$peer) {
        echo '<div class="card"><h2>Select a child</h2><p>No active child is selected.</p></div>';
        catalog_foot();
        exit;
    }
    if ((int)$peer['is_active'] !== 1) {
        echo '<div class="card"><h2>Child disabled</h2><p>Enable this child connection before reading or downloading its inventory.</p><p><a class="button" href="peers.php?role=child">Manage Children</a></p></div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card"><h2>Selected child: ' . catalog_h($peer['site_name']) . '</h2>';
    echo '<form method="get" action="peer-inventory.php"><label>Child<br><select name="peer_id">';
    foreach ($activeChildren as $row) {
        $selected = (int)$row['id'] === $peerId ? ' selected' : '';
        echo '<option value="' . (int)$row['id'] . '"' . $selected . '>' . catalog_h($row['site_name'] . ' - ' . $row['site_url']) . '</option>';
    }
    echo '</select></label> <input type="hidden" name="filter" value="needed"><input type="hidden" name="tab" value="inventory"><button>Show files this parent needs</button></form>';
    echo '<form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peer_inventory')) . '"><input type="hidden" name="action" value="refresh"><input type="hidden" name="peer_id" value="' . $peerId . '"><input type="hidden" name="filter" value="' . catalog_h($filter) . '"><input type="hidden" name="tab" value="' . catalog_h($view) . '"><input type="hidden" name="page" value="' . $page . '"><button>Refresh inventory from child</button></form></div>';

    $presenceSql = pi_local_presence_sql('pf');
    $absenceSql = pi_local_absence_sql('pf');
    $neededSql = pi_needed_sql('pf');
    $needCountSql = pi_need_count_sql('pf');
    $baseGameSql = pi_base_game_sql('pf');

    $summary = catalog_one(
        $db,
        'SELECT COUNT(*) total_files,
                SUM(CASE WHEN ' . $absenceSql . ' AND ' . $neededSql . ' THEN 1 ELSE 0 END) needed,
                SUM(CASE WHEN ' . $absenceSql . ' AND NOT (' . $neededSql . ') THEN 1 ELSE 0 END) other_missing,
                SUM(CASE WHEN ' . $presenceSql . ' THEN 1 ELSE 0 END) already_present,
                SUM(CASE WHEN ' . $baseGameSql . ' THEN 1 ELSE 0 END) protected_files,
                MAX(pf.last_seen_at) last_received_at
         FROM ue_federation_peer_files pf
         WHERE pf.peer_id=?',
        [$peerId]
    ) ?: [];

    $neededCount = (int)($summary['needed'] ?? 0);
    $missingCount = (int)($summary['other_missing'] ?? 0);
    $presentCount = (int)($summary['already_present'] ?? 0);
    $totalCount = (int)($summary['total_files'] ?? 0);

    echo '<div class="card"><h2>Choose what to show</h2><p class="page-links">';
    echo '<a class="button" href="' . catalog_h(pi_url($peerId, 'needed', 'inventory', 1)) . '">Parent needs from child (' . $neededCount . ')</a> ';
    echo '<a class="button" href="' . catalog_h(pi_url($peerId, 'missing', 'inventory', 1)) . '">Other files parent lacks (' . $missingCount . ')</a> ';
    echo '<a class="button" href="' . catalog_h(pi_url($peerId, 'present', 'inventory', 1)) . '">Already on parent (' . $presentCount . ')</a> ';
    echo '<a class="button" href="' . catalog_h(pi_url($peerId, 'all', 'inventory', 1)) . '">Full child inventory (' . $totalCount . ')</a> ';
    echo '<a class="button" href="' . catalog_h(pi_url($peerId, 'needed', 'requests', 1)) . '">Files child requested from parent</a>';
    echo '</p></div>';

    if ($view === 'requests') {
        $latestRequest = catalog_one(
            $db,
            'SELECT * FROM ue_federation_requests WHERE peer_id=? AND direction="child_to_parent" ORDER BY created_at DESC, id DESC LIMIT 1',
            [$peerId]
        );
        echo '<div class="card"><h2>Files this child requested from the parent</h2>';
        if (!$latestRequest) {
            echo '<p class="muted">This child has not submitted a dependency request.</p>';
        } else {
            $totalRows = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_request_items WHERE request_id=?', [(int)$latestRequest['id']])['c'] ?? 0);
            $pages = max(1, (int)ceil($totalRows / PI_PAGE_SIZE));
            $page = min($page, $pages);
            $offset = ($page - 1) * PI_PAGE_SIZE;
            $rows = catalog_all(
                $db,
                'SELECT i.*, f.id parent_file_id, f.package_name parent_package, f.original_name parent_file,
                        f.package_guid, f.md5, f.sha1, f.file_size, g.name game_name
                 FROM ue_federation_request_items i
                 LEFT JOIN ue_files f ON f.id=i.local_file_id
                 LEFT JOIN ue_games g ON g.id=f.game_id
                 WHERE i.request_id=?
                 ORDER BY i.updated_at DESC, i.id DESC
                 LIMIT ' . PI_PAGE_SIZE . ' OFFSET ' . $offset,
                [(int)$latestRequest['id']]
            );
            echo '<p>Latest request: <a href="requests.php?request_id=' . (int)$latestRequest['id'] . '">#' . (int)$latestRequest['id'] . '</a> — ' . catalog_h($latestRequest['status']) . '</p>';
            echo '<table><tr><th>Status</th><th>Child needs</th><th>Parent file</th><th>Size</th><th>Current state</th></tr>';
            foreach ($rows as $row) {
                $parentFile = !empty($row['parent_file_id'])
                    ? '<a href="../file-info.php?id=' . (int)$row['parent_file_id'] . '">' . catalog_h(($row['parent_package'] ?? '') . ' / ' . ($row['parent_file'] ?? '')) . '</a>'
                    : '<span class="muted">Not available yet</span>';
                echo '<tr><td>' . catalog_h($row['status']) . '</td><td><div class="mono">' . catalog_h($row['required_package']) . '</div><div class="mono small path">' . catalog_h($row['required_object_path']) . '</div></td><td>' . $parentFile . '</td><td class="nowrap">' . catalog_h(catalog_bytes((int)($row['file_size'] ?? 0))) . '</td><td class="path">' . catalog_h($row['status_message']) . '</td></tr>';
            }
            echo '</table>';
            pi_pagination($peerId, 'needed', 'requests', $page, $pages);
        }
        echo '</div>';
    } else {
        $filterSql = match ($filter) {
            'needed' => ' AND ' . $absenceSql . ' AND ' . $neededSql,
            'missing' => ' AND ' . $absenceSql . ' AND NOT (' . $neededSql . ')',
            'present' => ' AND ' . $presenceSql,
            default => '',
        };

        $totalRows = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peer_files pf WHERE pf.peer_id=?' . $filterSql, [$peerId])['c'] ?? 0);
        $pages = max(1, (int)ceil($totalRows / PI_PAGE_SIZE));
        $page = min($page, $pages);
        $offset = ($page - 1) * PI_PAGE_SIZE;
        $rows = catalog_all(
            $db,
            'SELECT pf.*, COALESCE(NULLIF(pf.remote_game_name,""),g.name) display_game,
                    ' . $needCountSql . ' needed_by_files,
                    CASE WHEN ' . $presenceSql . ' THEN "present"
                         WHEN ' . $neededSql . ' THEN "needed"
                         ELSE "missing" END availability_type,
                    CASE WHEN ' . $baseGameSql . ' THEN 1 ELSE 0 END is_base_game
             FROM ue_federation_peer_files pf
             LEFT JOIN ue_games g ON g.id=pf.game_id
             WHERE pf.peer_id=?' . $filterSql . '
             ORDER BY FIELD(availability_type,"needed","missing","present"), needed_by_files DESC,
                      COALESCE(NULLIF(pf.remote_game_name,""),g.name), pf.package_name, pf.original_name, pf.id
             LIMIT ' . PI_PAGE_SIZE . ' OFFSET ' . $offset,
            [$peerId]
        );

        $heading = match ($filter) {
            'needed' => 'Files this parent needs from ' . (string)$peer['site_name'],
            'missing' => 'Other files this parent does not have',
            'present' => 'Files already present on this parent',
            default => 'Full inventory from ' . (string)$peer['site_name'],
        };
        $description = match ($filter) {
            'needed' => 'These are actual files in the selected child inventory whose package names match missing dependencies on this parent.',
            'missing' => 'These files exist on the child and are absent from the parent, but are not currently required by a known missing dependency.',
            'present' => 'These child files already match a verified file held by this parent.',
            default => 'Every cached file reported by the selected child.',
        };

        echo '<div class="card"><h2>' . catalog_h($heading) . '</h2><p>' . catalog_h($description) . '</p><p>Showing <strong>' . count($rows) . '</strong> of <strong>' . $totalRows . '</strong> matching files.</p>';
        if (!$rows) {
            echo '<p class="muted">No matching files were found. Refresh the child inventory, then check that the child game names match the parent game names.</p>';
        } else {
            echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peer_inventory')) . '"><input type="hidden" name="action" value="queue"><input type="hidden" name="peer_id" value="' . $peerId . '"><input type="hidden" name="filter" value="' . catalog_h($filter) . '"><input type="hidden" name="tab" value="inventory"><input type="hidden" name="page" value="' . $page . '">';
            echo '<p><label><input type="checkbox" data-check-all="parent-files"> Check all downloadable files on this page</label> <button>Download selected files from child</button></p>';
            echo '<table><tr><th>Select</th><th>Parent status</th><th>Game</th><th>Needed by parent files</th><th>Package</th><th>Child file</th><th>I/E</th><th>GUID / MD5 / SHA1</th><th>Size</th></tr>';
            foreach ($rows as $row) {
                $type = (string)$row['availability_type'];
                $isBase = !empty($row['is_base_game']);
                $downloadable = $type !== 'present' && !$isBase;
                $status = $isBase ? 'official base-game' : ($type === 'present' ? 'already have' : ($type === 'needed' ? 'needed' : 'missing'));
                $pill = $isBase || $type === 'needed' ? 'amber' : '';
                $select = $downloadable
                    ? '<input type="checkbox" data-check-group="parent-files" name="peer_file_ids[]" value="' . (int)$row['id'] . '">'
                    : '<span class="muted">—</span>';
                echo '<tr><td>' . $select . '</td><td><span class="pill ' . $pill . '">' . catalog_h($status) . '</span></td><td>' . catalog_h($row['display_game'] ?? '') . '<div class="muted small">' . catalog_h($row['remote_engine_key']) . '</div></td><td>' . (int)$row['needed_by_files'] . '</td><td class="mono">' . catalog_h($row['package_name']) . '</td><td>' . catalog_h($row['original_name']) . '</td><td class="nowrap">' . (int)$row['import_count'] . ' / ' . (int)$row['export_count'] . '</td><td>' . pi_identity_html($row) . '</td><td class="nowrap">' . catalog_h(catalog_bytes((int)$row['file_size'])) . '</td></tr>';
            }
            echo '</table><p><button>Download selected files from child</button></p></form>';
            pi_pagination($peerId, $filter, 'inventory', $page, $pages);
        }
        echo '</div>';
    }

    echo '<div class="card"><h2>Inventory totals for ' . catalog_h($peer['site_name']) . '</h2><table>';
    echo '<tr><th>Total child files</th><td>' . $totalCount . '</td></tr>';
    echo '<tr><th>Files this parent needs for dependencies</th><td>' . $neededCount . '</td></tr>';
    echo '<tr><th>Other child files parent lacks</th><td>' . $missingCount . '</td></tr>';
    echo '<tr><th>Files parent already has</th><td>' . $presentCount . '</td></tr>';
    echo '<tr><th>Official base-game files</th><td>' . (int)($summary['protected_files'] ?? 0) . '</td></tr>';
    echo '<tr><th>Last inventory refresh</th><td>' . catalog_h((string)($summary['last_received_at'] ?? 'never')) . '</td></tr>';
    echo '</table></div>';

    $jobs = catalog_all(
        $db,
        'SELECT j.*, pf.package_name, pf.original_name
         FROM ue_federation_transfer_jobs j
         LEFT JOIN ue_federation_peer_files pf ON pf.peer_id=j.peer_id AND pf.remote_file_id=j.remote_file_id
         WHERE j.peer_id=? AND j.direction="parent_pull_from_child"
         ORDER BY j.created_at DESC, j.id DESC LIMIT 100',
        [$peerId]
    );
    echo '<div class="card"><h2>Recent downloads from this child</h2>';
    if (!$jobs) {
        echo '<p class="muted">No files have been queued from this child.</p>';
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
