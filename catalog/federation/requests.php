<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/BaseGameProtection.php';

function requests_show_base_game(mixed $value): bool
{
    return in_array((string)$value, ['1', 'true', 'yes', 'on'], true);
}

function requests_update_header(PDO $db, int $requestId): void
{
    $c = catalog_one($db, 'SELECT COUNT(*) total, SUM(status="approved") approved, SUM(status="denied") denied, SUM(status="requested") requested FROM ue_federation_request_items WHERE request_id=?', [$requestId]);
    if (!$c || (int)$c['total'] === 0) {
        return;
    }
    if ((int)$c['approved'] > 0 && (int)$c['denied'] > 0) {
        $status = 'part_approved';
    } elseif ((int)$c['approved'] > 0 && (int)$c['requested'] === 0) {
        $status = 'approved';
    } elseif ((int)$c['denied'] >= (int)$c['total']) {
        $status = 'denied';
    } else {
        $status = 'submitted';
    }
    $db->prepare('UPDATE ue_federation_requests SET status=?, approved_at=IF(? IN ("approved","part_approved"), NOW(), approved_at), approved_by=? WHERE id=?')->execute([$status, $status, $_SESSION['user']['id'] ?? null, $requestId]);
}

function requests_item_file(PDO $db, int $requestId, int $itemId): ?array
{
    return catalog_one($db, 'SELECT i.*, f.id file_id, f.game_id, f.package_guid, f.original_name, f.package_name FROM ue_federation_request_items i LEFT JOIN ue_files f ON f.id=i.local_file_id WHERE i.request_id=? AND i.id=?', [$requestId, $itemId]);
}

function requests_url(int $requestId, bool $showBaseGame): string
{
    $query = ['request_id' => $requestId];
    if ($showBaseGame) {
        $query['show_base_game'] = 1;
    }
    return 'requests.php?' . http_build_query($query);
}

function requests_group_key(array $item): string
{
    $identity = strtolower(trim((string)($item['required_package'] ?? '')))
        . "\0" . strtolower(trim((string)($item['wanted_guid'] ?? '')))
        . "\0" . strtolower(trim((string)($item['wanted_md5'] ?? '')));
    return hash('sha256', $identity);
}

/** @return list<array<string,mixed>> */
function requests_raw_items(PDO $db, int $requestId, bool $showBaseGame): array
{
    $baseFilter = $showBaseGame ? '' : ' AND bg.id IS NULL';
    return catalog_all(
        $db,
        'SELECT i.*, f.package_name local_package, f.original_name local_file,
                f.game_id local_game_id, f.package_guid local_package_guid, bg.id base_game_id
         FROM ue_federation_request_items i
         LEFT JOIN ue_files f ON f.id=i.local_file_id
         LEFT JOIN ue_base_game_files bg ON bg.game_id=f.game_id AND bg.package_guid=f.package_guid
         WHERE i.request_id=?' . $baseFilter . '
         ORDER BY FIELD(i.status,"requested","approved","denied","imported","failed"), i.required_package, i.required_object_path, i.id',
        [$requestId]
    );
}

/**
 * Group legacy object-level rows and current package-level rows into the same
 * package-level display. Actions still update every underlying database row.
 *
 * @param list<array<string,mixed>> $items
 * @return array<string,array<string,mixed>>
 */
function requests_group_items(array $items): array
{
    $groups = [];
    foreach ($items as $item) {
        $key = requests_group_key($item);
        if (!isset($groups[$key])) {
            $groups[$key] = $item + [
                'group_key' => $key,
                'item_ids' => [],
                'object_paths' => [],
                'statuses' => [],
                'messages' => [],
                'raw_count' => 0,
            ];
        }

        $groups[$key]['item_ids'][] = (int)$item['id'];
        $groups[$key]['raw_count']++;
        $path = trim((string)($item['required_object_path'] ?? ''));
        if ($path !== '') {
            $groups[$key]['object_paths'][strtolower($path)] = $path;
        }
        $status = trim((string)($item['status'] ?? ''));
        if ($status !== '') {
            $groups[$key]['statuses'][$status] = true;
        }
        $message = trim((string)($item['status_message'] ?? ''));
        if ($message !== '') {
            $groups[$key]['messages'][$message] = true;
        }
        if (empty($groups[$key]['local_file_id']) && !empty($item['local_file_id'])) {
            foreach (['local_file_id', 'local_package', 'local_file', 'local_game_id', 'local_package_guid', 'base_game_id'] as $field) {
                $groups[$key][$field] = $item[$field] ?? null;
            }
        }
        if (!empty($item['base_game_id'])) {
            $groups[$key]['base_game_id'] = $item['base_game_id'];
        }
    }

    foreach ($groups as &$group) {
        $statuses = array_keys($group['statuses']);
        $group['display_status'] = count($statuses) === 1 ? $statuses[0] : 'mixed';
        $group['object_count'] = max(1, count($group['object_paths']));
        $group['example_object'] = (string)(reset($group['object_paths']) ?: ($group['required_object_path'] ?? ''));
        $group['display_message'] = implode(' ', array_keys($group['messages']));
        $group['can_select'] = (bool)array_intersect($statuses, ['requested', 'approved', 'denied']);
    }
    unset($group);

    return $groups;
}

function requests_set_item_decision(PDO $db, int $requestId, int $itemId, string $decision): void
{
    $item = requests_item_file($db, $requestId, $itemId);
    if (!$item || !in_array((string)$item['status'], ['requested', 'approved', 'denied'], true)) {
        return;
    }

    if ($decision === 'deny') {
        $db->prepare('UPDATE ue_federation_request_items SET status="denied", status_message="Denied by this parent administrator." WHERE request_id=? AND id=?')->execute([$requestId, $itemId]);
        return;
    }

    if (empty($item['local_file_id'])) {
        $db->prepare('UPDATE ue_federation_request_items SET status="denied", status_message="Not found in this parent catalog; this package cannot be approved." WHERE request_id=? AND id=?')->execute([$requestId, $itemId]);
        return;
    }
    if (base_game_file_is_protected($db, $item)) {
        $db->prepare('UPDATE ue_federation_request_items SET status="denied", status_message=? WHERE request_id=? AND id=?')->execute([base_game_block_message($item), $requestId, $itemId]);
        return;
    }
    $db->prepare('UPDATE ue_federation_request_items SET status="approved", status_message="Approved for this child by the parent administrator." WHERE request_id=? AND id=?')->execute([$requestId, $itemId]);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);
    $role = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    $showBaseGame = requests_show_base_game($_REQUEST['show_base_game'] ?? '0');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        if ($role !== 'parent') {
            throw new RuntimeException('Incoming child requests may be approved only while this site is in Parent mode.');
        }
        catalog_check_csrf('fed_requests');
        $requestId = (int)($_POST['request_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        $request = catalog_one($db, 'SELECT * FROM ue_federation_requests WHERE id=? AND direction="child_to_parent"', [$requestId]);
        if (!$request) {
            throw new RuntimeException('Incoming child request not found.');
        }

        if ($action === 'approve_all') {
            $items = catalog_all($db, 'SELECT id FROM ue_federation_request_items WHERE request_id=? AND status="requested"', [$requestId]);
            foreach ($items as $itemRow) {
                requests_set_item_decision($db, $requestId, (int)$itemRow['id'], 'approve');
            }
            requests_update_header($db, $requestId);
            fed_log($db, (int)$request['peer_id'], null, 'INFO', 'REQUEST_APPROVE_ALL', 'Request ' . $requestId . ': all packages available on this parent were approved.');
        } elseif ($action === 'deny_all') {
            $db->prepare('UPDATE ue_federation_request_items SET status="denied", status_message="Denied by this parent administrator." WHERE request_id=? AND status IN ("requested","approved")')->execute([$requestId]);
            $db->prepare('UPDATE ue_federation_requests SET status="denied", approved_at=NULL, approved_by=? WHERE id=?')->execute([$_SESSION['user']['id'] ?? null, $requestId]);
            fed_log($db, (int)$request['peer_id'], null, 'INFO', 'REQUEST_DENY_ALL', 'Incoming request ' . $requestId . ' denied.');
        } elseif ($action === 'approve_selected' || $action === 'deny_selected') {
            $selectedKeys = array_values(array_unique(array_filter(
                array_map('strval', $_POST['group_keys'] ?? []),
                static fn(string $key): bool => preg_match('/^[a-f0-9]{64}$/', $key) === 1
            )));
            if (!$selectedKeys) {
                throw new RuntimeException('Select at least one requested package.');
            }

            $groups = requests_group_items(requests_raw_items($db, $requestId, true));
            $decision = $action === 'approve_selected' ? 'approve' : 'deny';
            $updated = 0;
            foreach ($selectedKeys as $key) {
                if (!isset($groups[$key])) {
                    continue;
                }
                foreach ($groups[$key]['item_ids'] as $itemId) {
                    requests_set_item_decision($db, $requestId, (int)$itemId, $decision);
                    $updated++;
                }
            }
            requests_update_header($db, $requestId);
            fed_log($db, (int)$request['peer_id'], null, 'INFO', strtoupper($action), 'Incoming request ' . $requestId . ': updated ' . $updated . ' underlying item row(s).');
        }

        header('Location: ' . requests_url($requestId, $showBaseGame));
        exit;
    }

    if (!catalog_require_admin_page('Incoming Requests')) {
        exit;
    }

    catalog_head('Incoming Requests');
    $requestId = (int)($_GET['request_id'] ?? 0);
    catalog_page_header(
        'Incoming Requests',
        'Parent-side workflow. Every request shown here was sent by a child asking this parent to provide missing dependency packages.',
        catalog_federation_links() + ['Request Centre' => 'request-center.php', 'Children' => 'peers.php?role=child', 'Child Inventories' => 'peer-inventory.php']
    );

    if ($role !== 'parent') {
        echo '<div class="card"><h2>Incoming child requests disabled</h2><p>This site is not in Parent mode. A child does not approve file requests from other children.</p><p><a class="button" href="request-center.php">Open Requests</a> <a class="button" href="settings.php">Federation Settings</a></p></div>';
        catalog_foot();
        exit;
    }

    $requests = catalog_all(
        $db,
        'SELECT r.*, p.site_name peer_name,
                (SELECT COUNT(DISTINCT LOWER(i.required_package)) FROM ue_federation_request_items i WHERE i.request_id=r.id) package_count,
                (SELECT COUNT(*) FROM ue_federation_request_items i WHERE i.request_id=r.id) raw_item_count
         FROM ue_federation_requests r
         JOIN ue_federation_peers p ON p.id=r.peer_id
         WHERE r.direction="child_to_parent"
         ORDER BY r.created_at DESC LIMIT 200'
    );
    echo '<div class="card"><h2>Requests from children</h2>';
    echo '<p><strong>Direction:</strong> child &rarr; this parent. Opening a request never means the parent is asking the child for these files.</p>';
    if (!$requests) {
        echo '<p class="muted">No child requests have been received.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>From child</th><th>Status</th><th>Requested packages</th><th>Title</th><th>Submitted</th><th>Action</th></tr>';
        foreach ($requests as $row) {
            echo '<tr><td class="mono">' . (int)$row['id'] . '</td><td>' . catalog_h($row['peer_name']) . '</td><td>' . catalog_h($row['status']) . '</td><td>' . (int)$row['package_count'] . '</td><td>' . catalog_h($row['title']) . '</td><td>' . catalog_h($row['submitted_at']) . '</td><td><a href="requests.php?request_id=' . (int)$row['id'] . '">Review request</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    if ($requestId > 0) {
        $request = catalog_one($db, 'SELECT r.*, p.site_name peer_name FROM ue_federation_requests r JOIN ue_federation_peers p ON p.id=r.peer_id WHERE r.id=? AND r.direction="child_to_parent"', [$requestId]);
        if (!$request) {
            throw new RuntimeException('Incoming child request not found.');
        }

        $allRawItems = requests_raw_items($db, $requestId, true);
        $allGroups = requests_group_items($allRawItems);
        $baseGameCount = 0;
        foreach ($allGroups as $group) {
            if (!empty($group['base_game_id'])) {
                $baseGameCount++;
            }
        }

        echo '<div class="card"><h2>Incoming request #' . (int)$request['id'] . '</h2><table>';
        echo '<tr><th>Direction</th><td><strong>' . catalog_h($request['peer_name']) . '</strong> child &rarr; <strong>this parent</strong></td></tr>';
        echo '<tr><th>Meaning</th><td>The child is missing these packages and is asking this parent to provide them.</td></tr>';
        echo '<tr><th>Status</th><td>' . catalog_h($request['status']) . '</td></tr>';
        echo '<tr><th>Distinct packages</th><td>' . count($allGroups) . '</td></tr>';
        echo '<tr><th>Received item rows</th><td>' . count($allRawItems) . (count($allRawItems) > count($allGroups) ? ' <span class="muted">(legacy object-level rows are grouped below)</span>' : '') . '</td></tr>';
        echo '<tr><th>Hash</th><td class="mono">' . catalog_h($request['request_hash']) . '</td></tr>';
        echo '<tr><th>Notes</th><td>' . catalog_h($request['notes']) . '</td></tr>';
        echo '<tr><th>Official base-game packages</th><td>' . $baseGameCount . ($showBaseGame ? ' shown' : ' hidden') . '</td></tr>';
        echo '</table>';

        echo '<form method="get" action="requests.php" class="filter-bar">';
        echo '<input type="hidden" name="request_id" value="' . (int)$requestId . '">';
        echo '<label><input type="checkbox" name="show_base_game" value="1"' . ($showBaseGame ? ' checked' : '') . '> Show official base-game packages</label> ';
        echo '<button>Apply filter</button></form>';

        if (in_array((string)$request['status'], ['submitted', 'part_approved', 'approved'], true)) {
            echo '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_requests')) . '"><input type="hidden" name="request_id" value="' . (int)$request['id'] . '"><input type="hidden" name="show_base_game" value="' . ($showBaseGame ? '1' : '0') . '"><input type="hidden" name="action" value="approve_all"><button>Approve every package this parent can supply</button></form> ';
            echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Deny all remaining packages in this child request?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_requests')) . '"><input type="hidden" name="request_id" value="' . (int)$request['id'] . '"><input type="hidden" name="show_base_game" value="' . ($showBaseGame ? '1' : '0') . '"><input type="hidden" name="action" value="deny_all"><button>Deny request</button></form>';
        }
        echo '</div>';

        $groups = requests_group_items(requests_raw_items($db, $requestId, $showBaseGame));
        echo '<div class="card"><h2>Packages requested by the child</h2>';
        echo '<p>The availability column refers to this parent. The child is requesting these packages because it does not currently have the dependencies it needs.</p>';
        if (!$groups) {
            echo '<p class="muted">No requested packages match the current filter.</p></div>';
        } else {
            echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_requests')) . '"><input type="hidden" name="request_id" value="' . (int)$requestId . '"><input type="hidden" name="show_base_game" value="' . ($showBaseGame ? '1' : '0') . '">';
            echo '<p><button name="action" value="approve_selected">Approve selected available packages</button> <button name="action" value="deny_selected">Deny selected packages</button></p>';
            echo '<table><tr><th>Select</th><th>Decision status</th><th>Package requested by child</th><th>Example missing object</th><th>Missing objects</th><th>Availability on this parent</th><th>Decision / reason</th></tr>';
            foreach ($groups as $group) {
                $isBase = !empty($group['base_game_id']);
                $match = !empty($group['local_file_id'])
                    ? '<a href="../file-info.php?id=' . (int)$group['local_file_id'] . '" target="_blank">' . catalog_h($group['local_package'] ?: $group['local_file']) . '</a>' . ($isBase ? ' <span class="pill amber">official base-game blocked</span>' : '')
                    : '<span class="muted">Not available on this parent</span>';
                $message = (string)$group['display_message'];
                if (empty($group['local_file_id']) && $message === '') {
                    $message = 'Not found in this parent catalog; this package cannot be approved.';
                }
                echo '<tr><td>' . (!empty($group['can_select']) ? '<input type="checkbox" name="group_keys[]" value="' . catalog_h($group['group_key']) . '">' : '') . '</td><td>' . catalog_h($group['display_status']) . '</td><td class="mono">' . catalog_h($group['required_package']) . '</td><td class="mono path">' . catalog_h($group['example_object']) . '</td><td>' . (int)$group['object_count'] . '</td><td>' . $match . '</td><td>' . catalog_h($message) . '</td></tr>';
            }
            echo '</table></form></div>';
        }
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Incoming requests error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
