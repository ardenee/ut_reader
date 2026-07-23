<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationInventory.php';
require_once __DIR__ . '/../lib/FederationInventoryRefresh.php';
require_once __DIR__ . '/../lib/BaseGameProtection.php';
require_once __DIR__ . '/../lib/FederationBaseGamePolicy.php';

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

function pi_parent_need_count_sql(string $peerAlias = 'pf'): string
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

function pi_parent_dependency_sql(string $peerAlias = 'pf'): string
{
    return pi_parent_need_count_sql($peerAlias) . ' > 0';
}

function pi_filter_value(string $value): string
{
    return match (strtolower(trim($value))) {
        'needed', 'parent_dependency', 'parent-dependency' => 'parent_dependency',
        'missing', 'all', 'parent_needs', 'parent-needs' => 'parent_needs',
        'requests', 'child', 'child_dependency', 'child-dependency' => 'child_dependency',
        default => 'parent_dependency',
    };
}

function pi_page_value(mixed $value): int
{
    return max(1, (int)$value);
}

function pi_url(int $peerId, string $filter, int $page = 1): string
{
    return 'peer-inventory.php?' . http_build_query([
        'peer_id' => $peerId,
        'filter' => $filter,
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

function pi_pagination(int $peerId, string $filter, int $page, int $pages): void
{
    if ($pages <= 1) {
        return;
    }
    echo '<p class="page-links">';
    if ($page > 1) {
        echo '<a class="button" href="' . catalog_h(pi_url($peerId, $filter, $page - 1)) . '">Previous</a> ';
    }
    echo '<span>Page ' . $page . ' of ' . $pages . '</span> ';
    if ($page < $pages) {
        echo '<a class="button" href="' . catalog_h(pi_url($peerId, $filter, $page + 1)) . '">Next</a>';
    }
    echo '</p>';
}

/** @return list<array<string,mixed>> */
function pi_child_dependency_rows(PDO $db, int $peerId): array
{
    $rows = catalog_all(
        $db,
        'SELECT i.id item_id, i.required_package, i.required_object_path, i.status item_status,
                i.status_message, i.updated_at item_updated_at,
                r.id request_id, r.status request_status,
                f.id parent_file_id, f.package_name parent_package, f.original_name parent_file,
                f.file_size, f.package_guid, f.md5, f.sha1, g.name game_name,
                CASE WHEN bg.id IS NOT NULL THEN 1 ELSE 0 END is_base_game
         FROM ue_federation_requests r
         JOIN ue_federation_request_items i ON i.request_id=r.id
         JOIN ue_files f ON f.id=i.local_file_id AND f.scan_status="verified"
         JOIN ue_games g ON g.id=f.game_id
         LEFT JOIN ue_base_game_files bg ON bg.game_id=f.game_id AND bg.package_guid=f.package_guid
         WHERE r.peer_id=?
           AND r.direction="child_to_parent"
           AND r.status NOT IN ("cancelled","completed","denied")
           AND i.status IN ("requested","approved","queued","downloading","downloaded","failed")
         ORDER BY i.updated_at DESC, i.id DESC',
        [$peerId]
    );
    $byPackage = [];
    foreach ($rows as $row) {
        $key = strtolower(trim((string)$row['required_package']));
        if ($key !== '' && !isset($byPackage[$key])) {
            $byPackage[$key] = $row;
        }
    }
    return array_values($byPackage);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);
    $siteRole = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    $ignoreBaseGame = federation_ignore_base_game_files($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required.');
        }
        if ($siteRole !== 'parent') {
            throw new RuntimeException('Child inventory controls are available only in Parent mode.');
        }
        catalog_check_csrf('fed_peer_inventory');

        $peerId = (int)($_POST['peer_id'] ?? 0);
        $filter = pi_filter_value((string)($_POST['filter'] ?? 'parent_dependency'));
        $page = pi_page_value($_POST['page'] ?? 1);
        $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="child" AND is_active=1', [$peerId]);
        if (!$peer) {
            throw new RuntimeException('Active child connection not found.');
        }

        $action = (string)($_POST['action'] ?? 'refresh');
        if ($action === 'refresh') {
            $childInventory = federation_pull_inventory_from_child($db, $peerId);
            try {
                $parentInventory = federation_request_child_refresh_parent_inventory($db, $peerId);
                $_SESSION['fed_peer_inventory_flash'] = 'Both inventories refreshed. Parent received '
                    . (int)$childInventory['received'] . ' child file(s); child received '
                    . (int)($parentInventory['received'] ?? 0) . ' parent file(s).';
            } catch (Throwable $refreshError) {
                fed_log($db, $peerId, null, 'ERROR', 'BIDIRECTIONAL_INVENTORY_REFRESH_PARTIAL', $refreshError->getMessage());
                $_SESSION['fed_peer_inventory_flash'] = 'The parent refreshed this child inventory ('
                    . (int)$childInventory['received'] . ' file(s)), but the child could not refresh the parent inventory: '
                    . $refreshError->getMessage();
            }
        } elseif ($action === 'queue') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['peer_file_ids'] ?? []), static fn(int $id): bool => $id > 0)));
            if (!$ids) {
                throw new RuntimeException('Select at least one child file to download.');
            }
            if (count($ids) > PI_PAGE_SIZE) {
                throw new RuntimeException('Select no more than ' . PI_PAGE_SIZE . ' files at once.');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $policySql = $ignoreBaseGame
                ? ' AND (COALESCE(pf.is_base_game,0)=0 OR (' . pi_parent_dependency_sql('pf') . '))'
                : '';
            $rows = catalog_all(
                $db,
                'SELECT pf.*, ' . pi_parent_need_count_sql('pf') . ' needed_by_parent_files
                 FROM ue_federation_peer_files pf
                 WHERE pf.peer_id=?
                   AND pf.id IN (' . $placeholders . ')
                   AND ' . pi_local_absence_sql('pf') . $policySql,
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
            fed_log($db, $peerId, null, 'INFO', 'PARENT_PULL_QUEUE', 'Queued ' . $queued . ' child file(s). Base-game rows obeyed the parent policy; dependency exceptions remained eligible.');
            $_SESSION['fed_peer_inventory_flash'] = 'Queued ' . $queued . ' file(s) from this child. Existing files and policy-excluded ordinary base-game files were skipped.';
        } else {
            throw new RuntimeException('Unknown child inventory action.');
        }

        header('Location: ' . pi_url($peerId, $filter, $page));
        exit;
    }

    if (!catalog_require_admin_page('Child Inventories')) {
        exit;
    }

    $legacyTab = strtolower(trim((string)($_GET['tab'] ?? $_GET['inventory_tab'] ?? '')));
    $filter = pi_filter_value($legacyTab === 'requests' || $legacyTab === 'child' ? 'child_dependency' : (string)($_GET['filter'] ?? 'parent_dependency'));
    $page = pi_page_value($_GET['page'] ?? 1);

    catalog_head('Child Inventory');
    catalog_flash($_SESSION['fed_peer_inventory_flash'] ?? null);
    unset($_SESSION['fed_peer_inventory_flash']);
    catalog_page_header(
        'Child Inventory',
        'Select a child and open one of the three file-need views.',
        []
    );

    if ($siteRole !== 'parent') {
        echo '<div class="card"><h2>Parent mode required</h2>';
        if ($siteRole === 'child') {
            echo '<p>This server is a child and cannot have child inventories. A child may download from its parent only through approved missing-dependency requests.</p>';
        } else {
            echo '<p>Change this server to Parent mode before accepting children.</p>';
        }
        echo '</div>';
        catalog_foot();
        exit;
    }

    $children = catalog_all(
        $db,
        'SELECT p.*,
                (SELECT COUNT(*) FROM ue_federation_peer_files pf WHERE pf.peer_id=p.id) cached_files,
                (SELECT MAX(pf.last_seen_at) FROM ue_federation_peer_files pf WHERE pf.peer_id=p.id) inventory_seen_at
         FROM ue_federation_peers p
         WHERE p.peer_role="child" AND p.is_active=1
         ORDER BY p.site_name,p.id'
    );
    if (!$children) {
        echo '<div class="card"><h2>No connected children</h2><p>No active child sites are connected.</p></div>';
        catalog_foot();
        exit;
    }

    $peerId = (int)($_GET['peer_id'] ?? $children[0]['id']);
    $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="child" AND is_active=1', [$peerId]);
    if (!$peer) {
        $peerId = (int)$children[0]['id'];
        $peer = $children[0];
    }

    $storedCount = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peer_files WHERE peer_id=?', [$peerId])['c'] ?? 0);
    $syncError = '';
    if ($storedCount === 0) {
        try {
            federation_pull_inventory_from_child($db, $peerId);
        } catch (Throwable $error) {
            $syncError = $error->getMessage();
        }
    }
    if ($syncError !== '') {
        echo CatalogUi::alert('warning', $syncError, 'Child inventory synchronization failed');
    }

    $lastInventory = (string)(catalog_one($db, 'SELECT MAX(last_seen_at) t FROM ue_federation_peer_files WHERE peer_id=?', [$peerId])['t'] ?? '');
    $lastSync = federation_inventory_last_sync_at($db, $peerId) ?? $lastInventory;
    $syncHours = federation_inventory_sync_interval_hours($db);
    $nextSync = 'disabled';
    if ($syncHours > 0) {
        $lastTimestamp = $lastSync !== '' ? strtotime($lastSync) : false;
        $nextSync = $lastTimestamp !== false ? date('Y-m-d H:i:s', $lastTimestamp + ($syncHours * 3600)) : 'on next worker run';
    }

    echo '<div class="card"><h2>Selected child</h2>';
    echo '<form method="get" action="peer-inventory.php"><label>Child<br><select name="peer_id" onchange="this.form.submit()">';
    foreach ($children as $child) {
        echo '<option value="' . (int)$child['id'] . '"' . ((int)$child['id'] === $peerId ? ' selected' : '') . '>' . catalog_h((string)$child['site_name']) . '</option>';
    }
    echo '</select></label><input type="hidden" name="filter" value="' . catalog_h($filter) . '"></form>';
    echo '<table style="margin-top:12px">';
    echo '<tr><th>Child</th><td><strong>' . catalog_h((string)$peer['site_name']) . '</strong></td></tr>';
    echo '<tr><th>URL</th><td class="mono path">' . catalog_h((string)$peer['site_url']) . '</td></tr>';
    echo '<tr><th>Connection</th><td>active</td></tr>';
    echo '<tr><th>Last contact</th><td class="nowrap">' . catalog_h((string)($peer['last_seen_at'] ?? 'never')) . '</td></tr>';
    echo '<tr><th>Inventory last refreshed</th><td class="nowrap">' . catalog_h($lastSync !== '' ? $lastSync : 'never') . '</td></tr>';
    echo '<tr><th>Automatic refresh</th><td>' . ($syncHours > 0 ? 'Every ' . $syncHours . ' hour(s); next due ' . catalog_h($nextSync) : 'disabled') . '</td></tr>';
    echo '<tr><th>Base-game policy</th><td>' . catalog_h(federation_base_game_policy_label($db)) . '</td></tr>';
    echo '</table>';
    echo '<form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peer_inventory')) . '"><input type="hidden" name="action" value="refresh"><input type="hidden" name="peer_id" value="' . $peerId . '"><input type="hidden" name="filter" value="' . catalog_h($filter) . '"><input type="hidden" name="page" value="1"><button>Refresh both inventories now</button></form></div>';

    $presenceSql = pi_local_presence_sql('pf');
    $absenceSql = pi_local_absence_sql('pf');
    $parentDependencySql = pi_parent_dependency_sql('pf');
    $parentNeedCountSql = pi_parent_need_count_sql('pf');
    $ordinaryBaseFilter = $ignoreBaseGame ? ' AND COALESCE(pf.is_base_game,0)=0' : '';

    $summary = catalog_one(
        $db,
        'SELECT
            SUM(CASE WHEN ' . $absenceSql . ' AND ' . $parentDependencySql . ' THEN 1 ELSE 0 END) parent_dependency_needs,
            SUM(CASE WHEN ' . $absenceSql . ($ignoreBaseGame ? ' AND COALESCE(pf.is_base_game,0)=0' : '') . ' THEN 1 ELSE 0 END) parent_needs
         FROM ue_federation_peer_files pf
         WHERE pf.peer_id=?',
        [$peerId]
    ) ?: [];
    $parentDependencyCount = (int)($summary['parent_dependency_needs'] ?? 0);
    $parentNeedsCount = (int)($summary['parent_needs'] ?? 0);
    $childDependencyRows = pi_child_dependency_rows($db, $peerId);
    $childDependencyCount = count($childDependencyRows);

    echo '<div class="card"><h2>Choose what to show</h2><div class="grid">';
    echo '<div><a class="button" href="' . catalog_h(pi_url($peerId, 'parent_dependency')) . '">Parent Dependency Needs (' . $parentDependencyCount . ')</a><p class="muted small">Files this child has that the parent needs to complete known missing dependencies. Base-game dependency matches are included.</p></div>';
    echo '<div><a class="button" href="' . catalog_h(pi_url($peerId, 'parent_needs')) . '">Parent Needs (' . $parentNeedsCount . ')</a><p class="muted small">Ordinary files this child has that the parent does not have.' . ($ignoreBaseGame ? ' Base-game files are excluded by parent policy.' : ' Base-game files are included by parent policy.') . '</p></div>';
    echo '<div><a class="button" href="' . catalog_h(pi_url($peerId, 'child_dependency')) . '">Child Dependency Needs (' . $childDependencyCount . ')</a><p class="muted small">Files this parent has that the child requested for missing dependencies, including base-game dependency exceptions. The child cannot download anything else.</p></div>';
    echo '</div></div>';

    if ($filter === 'child_dependency') {
        $pages = max(1, (int)ceil($childDependencyCount / PI_PAGE_SIZE));
        $page = min($page, $pages);
        $rows = array_slice($childDependencyRows, ($page - 1) * PI_PAGE_SIZE, PI_PAGE_SIZE);
        echo '<div class="card"><h2>Child Dependency Needs</h2><p>These are dependency requests from <strong>' . catalog_h((string)$peer['site_name']) . '</strong> for which this parent currently has a file. Review the request; the child downloads only after approval.</p>';
        if (!$rows) {
            echo '<p class="muted">No child dependency needs currently match a parent file.</p>';
        } else {
            echo '<table><tr><th>Status</th><th>Game</th><th>Required package</th><th>Example required object</th><th>Parent file</th><th>Request</th><th>Action</th></tr>';
            foreach ($rows as $row) {
                $badge = !empty($row['is_base_game']) ? ' <span class="pill amber">base-game dependency</span>' : '';
                echo '<tr><td>' . catalog_h((string)$row['item_status']) . '</td><td>' . catalog_h((string)$row['game_name']) . '</td><td class="mono">' . catalog_h((string)$row['required_package']) . $badge . '</td><td class="mono path">' . catalog_h((string)$row['required_object_path']) . '</td><td><a href="../file-info.php?id=' . (int)$row['parent_file_id'] . '">' . catalog_h((string)$row['parent_package'] . ' / ' . (string)$row['parent_file']) . '</a><div class="muted small">' . catalog_h(catalog_bytes((int)$row['file_size'])) . '</div></td><td><a href="requests.php?request_id=' . (int)$row['request_id'] . '">#' . (int)$row['request_id'] . '</a></td><td><a class="button" href="requests.php?request_id=' . (int)$row['request_id'] . '">Review request</a></td></tr>';
            }
            echo '</table>';
            pi_pagination($peerId, $filter, $page, $pages);
        }
        echo '</div>';
    } else {
        $filterSql = $filter === 'parent_dependency'
            ? ' AND ' . $absenceSql . ' AND ' . $parentDependencySql
            : ' AND ' . $absenceSql . $ordinaryBaseFilter;
        $totalRows = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peer_files pf WHERE pf.peer_id=?' . $filterSql, [$peerId])['c'] ?? 0);
        $pages = max(1, (int)ceil($totalRows / PI_PAGE_SIZE));
        $page = min($page, $pages);
        $offset = ($page - 1) * PI_PAGE_SIZE;
        $rows = catalog_all(
            $db,
            'SELECT pf.*, COALESCE(NULLIF(pf.remote_game_name,""),g.name) display_game,
                    ' . $parentNeedCountSql . ' needed_by_parent_files
             FROM ue_federation_peer_files pf
             LEFT JOIN ue_games g ON g.id=pf.game_id
             WHERE pf.peer_id=?' . $filterSql . '
             ORDER BY needed_by_parent_files DESC, COALESCE(NULLIF(pf.remote_game_name,""),g.name), pf.package_name, pf.original_name, pf.id
             LIMIT ' . PI_PAGE_SIZE . ' OFFSET ' . $offset,
            [$peerId]
        );

        $heading = $filter === 'parent_dependency' ? 'Parent Dependency Needs' : 'Parent Needs';
        $description = $filter === 'parent_dependency'
            ? 'Files held by this child that directly satisfy missing dependencies on the parent. Base-game files are included only because this is a dependency view.'
            : 'Every ordinary child file absent from the parent under the current parent-controlled base-game policy.';
        echo '<div class="card"><h2>' . $heading . '</h2><p>' . catalog_h($description) . '</p><p>Showing <strong>' . count($rows) . '</strong> of <strong>' . $totalRows . '</strong> matching files.</p>';
        if (!$rows) {
            echo '<p class="muted">No matching files were found.</p>';
        } else {
            echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peer_inventory')) . '"><input type="hidden" name="action" value="queue"><input type="hidden" name="peer_id" value="' . $peerId . '"><input type="hidden" name="filter" value="' . catalog_h($filter) . '"><input type="hidden" name="page" value="' . $page . '">';
            echo '<p><label><input type="checkbox" data-check-all="parent-files"> Check all files on this page</label> <button>Download selected files from child</button></p>';
            echo '<table><tr><th>Select</th><th>Need</th><th>Game</th><th>Needed by parent files</th><th>Package</th><th>Child file</th><th>I/E</th><th>GUID / MD5 / SHA1</th><th>Size</th></tr>';
            foreach ($rows as $row) {
                $dependencyCount = (int)$row['needed_by_parent_files'];
                $baseBadge = !empty($row['is_base_game']) ? ' <span class="pill amber">base-game dependency</span>' : '';
                echo '<tr><td><input type="checkbox" data-check-group="parent-files" name="peer_file_ids[]" value="' . (int)$row['id'] . '"></td><td><span class="pill ' . ($dependencyCount > 0 ? 'amber' : '') . '">' . ($dependencyCount > 0 ? 'dependency' : 'not held') . '</span>' . $baseBadge . '</td><td>' . catalog_h((string)($row['display_game'] ?? '')) . '<div class="muted small">' . catalog_h((string)$row['remote_engine_key']) . '</div></td><td>' . $dependencyCount . '</td><td class="mono">' . catalog_h((string)$row['package_name']) . '</td><td>' . catalog_h((string)$row['original_name']) . '</td><td class="nowrap">' . (int)$row['import_count'] . ' / ' . (int)$row['export_count'] . '</td><td>' . pi_identity_html($row) . '</td><td class="nowrap">' . catalog_h(catalog_bytes((int)$row['file_size'])) . '</td></tr>';
            }
            echo '</table><p><button>Download selected files from child</button></p></form>';
            pi_pagination($peerId, $filter, $page, $pages);
        }
        echo '</div>';
    }

    echo '<script>(function(){document.querySelectorAll("[data-check-all]").forEach(function(master){master.addEventListener("change",function(){var group=master.getAttribute("data-check-all");document.querySelectorAll("[data-check-group=\""+group+"\"]").forEach(function(box){box.checked=master.checked;});});});})();</script>';
    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Child inventory error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
