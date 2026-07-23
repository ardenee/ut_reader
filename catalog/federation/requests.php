<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/BaseGameProtection.php';
require_once __DIR__ . '/../lib/FederationRequestLifecycle.php';

function requests_show_base_game(mixed $value): bool
{
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function requests_update_header(PDO $db, int $requestId): void
{
    federation_request_recalculate_header($db, $requestId);
}

function requests_item_file(PDO $db, int $requestId, int $itemId): ?array
{
    return catalog_one(
        $db,
        'SELECT i.*, f.id file_id, f.game_id, f.package_guid, f.original_name, f.package_name
         FROM ue_federation_request_items i
         LEFT JOIN ue_files f ON f.id=i.local_file_id
         WHERE i.request_id=? AND i.id=?',
        [$requestId, $itemId]
    );
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
    return hash(
        'sha256',
        strtolower(trim((string)($item['required_package'] ?? '')))
        . "\0" . strtolower(trim((string)($item['wanted_guid'] ?? '')))
        . "\0" . strtolower(trim((string)($item['wanted_md5'] ?? '')))
    );
}

function requests_clarify_message(string $message): string
{
    $message = trim($message);
    return match ($message) {
        'Parent does not currently have a matching file.',
        'Parent does not have matching file.',
        'Not found in this parent catalog; this package cannot be approved.',
        'Not found in this parent\'s catalog. This package cannot be approved until the parent imports a matching file.'
            => 'Not available yet. This request can be approved and kept active until a matching file is imported.',
        'Approved by parent admin.' => 'Approved for this child by the parent administrator.',
        'Denied by parent admin.' => 'Denied by this parent administrator.',
        default => $message,
    };
}

/** @return list<array<string,mixed>> */
function requests_raw_items(PDO $db, int $requestId, bool $showBaseGame): array
{
    $baseFilter = $showBaseGame ? '' : ' AND bg.id IS NULL AND LOWER(COALESCE(i.status_message,"")) NOT LIKE "%official base-game package%"';
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
                'is_base_game' => false,
            ];
        }

        $groups[$key]['item_ids'][] = (int)$item['id'];
        $groups[$key]['raw_count']++;
        $groups[$key]['is_base_game'] = !empty($groups[$key]['is_base_game'])
            || !empty($item['base_game_id'])
            || str_contains(strtolower((string)($item['status_message'] ?? '')), 'official base-game package');

        $path = trim((string)($item['required_object_path'] ?? ''));
        if ($path !== '') {
            $groups[$key]['object_paths'][strtolower($path)] = $path;
        }
        $status = trim((string)($item['status'] ?? ''));
        if ($status !== '') {
            $groups[$key]['statuses'][$status] = true;
        }
        $message = requests_clarify_message((string)($item['status_message'] ?? ''));
        if ($message !== '') {
            $groups[$key]['messages'][$message] = true;
        }
        if (empty($groups[$key]['local_file_id']) && !empty($item['local_file_id'])) {
            foreach (['local_file_id', 'local_package', 'local_file', 'local_game_id', 'local_package_guid', 'base_game_id'] as $field) {
                $groups[$key][$field] = $item[$field] ?? null;
            }
        }
    }

    foreach ($groups as &$group) {
        $statuses = array_keys($group['statuses']);
        $group['display_status'] = count($statuses) === 1 ? $statuses[0] : 'mixed';
        $group['object_count'] = max(1, count($group['object_paths']));
        $group['example_object'] = (string)(reset($group['object_paths']) ?: ($group['required_object_path'] ?? ''));
        $group['display_message'] = implode(' ', array_keys($group['messages']));
        $group['waiting_for_file'] = $group['display_status'] === 'approved' && empty($group['local_file_id']);
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
        $db->prepare(
            'UPDATE ue_federation_request_items
             SET status="denied", status_message="Denied by this parent administrator."
             WHERE request_id=? AND id=?'
        )->execute([$requestId, $itemId]);
        return;
    }

    if (!empty($item['local_file_id']) && base_game_file_is_protected($db, $item)) {
        $db->prepare(
            'UPDATE ue_federation_request_items SET status="denied", status_message=? WHERE request_id=? AND id=?'
        )->execute([base_game_block_message($item), $requestId, $itemId]);
        return;
    }

    if (empty($item['local_file_id'])) {
        $db->prepare(
            'UPDATE ue_federation_request_items SET status="approved", status_message=? WHERE request_id=? AND id=?'
        )->execute([federation_request_waiting_message(), $requestId, $itemId]);
        return;
    }

    $db->prepare(
        'UPDATE ue_federation_request_items
         SET status="approved", status_message="Approved for this child by the parent administrator."
         WHERE request_id=? AND id=?'
    )->execute([$requestId, $itemId]);
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

        federation_refresh_request_matches($db, $requestId);

        if ($action === 'approve_all') {
            $items = catalog_all($db, 'SELECT id FROM ue_federation_request_items WHERE request_id=? AND status="requested"', [$requestId]);
            foreach ($items as $itemRow) {
                requests_set_item_decision($db, $requestId, (int)$itemRow['id'], 'approve');
            }
            requests_update_header($db, $requestId);
            fed_log($db, (int)$request['peer_id'], null, 'INFO', 'REQUEST_APPROVE_ALL', 'Request ' . $requestId . ': all non-base-game package requests were approved, including files not yet available.');
        } elseif ($action === 'deny_all') {
            $db->prepare(
                'UPDATE ue_federation_request_items
                 SET status="denied", status_message="Denied by this parent administrator."
                 WHERE request_id=? AND status IN ("requested","approved")'
            )->execute([$requestId]);
            requests_update_header($db, $requestId);
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
        'Requests from children for missing dependency packages. Approving an unavailable package keeps the request open until the file is found.',
        catalog_federation_links() + ['Request Centre' => 'request-center.php', 'Children' => 'peers.php?role=child', 'Child Inventories' => 'peer-inventory.php']
    );

    if ($role !== 'parent') {
        echo '<div class="card"><h2>Incoming child requests disabled</h2><p>This site is not in Parent mode.</p></div>';
        catalog_foot();
        exit;
    }

    $requests = catalog_all(
        $db,
        'SELECT r.*, p.site_name peer_name,
                (SELECT COUNT(DISTINCT LOWER(i.required_package)) FROM ue_federation_request_items i WHERE i.request_id=r.id) package_count
         FROM ue_federation_requests r
         JOIN ue_federation_peers p ON p.id=r.peer_id
         WHERE r.direction="child_to_parent"
         ORDER BY r.created_at DESC LIMIT 200'
    );

    echo '<div class="card"><h2>Requests from children</h2>';
    if (!$requests) {
        echo '<p class="muted">No child requests have been received.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>From child</th><th>Status</th><th>Packages</th><th>Title</th><th>Submitted</th><th>Action</th></tr>';
        foreach ($requests as $row) {
            echo '<tr><td class="mono">' . (int)$row['id'] . '</td><td>' . catalog_h($row['peer_name']) . '</td><td>' . catalog_h($row['status']) . '</td><td>' . (int)$row['package_count'] . '</td><td>' . catalog_h($row['title']) . '</td><td>' . catalog_h($row['submitted_at']) . '</td><td><a href="requests.php?request_id=' . (int)$row['id'] . '">Review request</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    if ($requestId > 0) {
        $request = catalog_one(
            $db,
            'SELECT r.*, p.site_name peer_name
             FROM ue_federation_requests r
             JOIN ue_federation_peers p ON p.id=r.peer_id
             WHERE r.id=? AND r.direction="child_to_parent"',
            [$requestId]
        );
        if (!$request) {
            throw new RuntimeException('Incoming child request not found.');
        }

        $refresh = federation_refresh_request_matches($db, $requestId);
        $request = catalog_one($db, 'SELECT r.*, p.site_name peer_name FROM ue_federation_requests r JOIN ue_federation_peers p ON p.id=r.peer_id WHERE r.id=?', [$requestId]) ?: $request;
        $allRawItems = requests_raw_items($db, $requestId, true);
        $allGroups = requests_group_items($allRawItems);
        $baseGameCount = count(array_filter($allGroups, static fn(array $group): bool => !empty($group['is_base_game'])));
        $waitingCount = count(array_filter($allGroups, static fn(array $group): bool => !empty($group['waiting_for_file'])));

        echo '<div class="card"><h2>Request #' . (int)$request['id'] . ' from ' . catalog_h($request['peer_name']) . '</h2><table>';
        echo '<tr><th>Status</th><td>' . catalog_h($request['status']) . '</td></tr>';
        echo '<tr><th>Requested packages</th><td>' . count($allGroups) . '</td></tr>';
        echo '<tr><th>Approved and waiting for a file</th><td>' . $waitingCount . '</td></tr>';
        echo '<tr><th>Official base-game packages</th><td>' . $baseGameCount . ($showBaseGame ? ' shown' : ' hidden') . '</td></tr>';
        echo '<tr><th>Automatically linked now</th><td>' . (int)($refresh['linked'] ?? 0) . '</td></tr>';
        echo '<tr><th>Notes</th><td>' . catalog_h($request['notes']) . '</td></tr>';
        echo '</table>';

        echo '<form method="get" action="requests.php" class="filter-bar"><input type="hidden" name="request_id" value="' . $requestId . '"><label><input type="checkbox" name="show_base_game" value="1"' . ($showBaseGame ? ' checked' : '') . '> Show official base-game packages</label> <button>Apply filter</button></form>';

        if (in_array((string)$request['status'], ['submitted', 'part_approved', 'approved'], true)) {
            echo '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_requests')) . '"><input type="hidden" name="request_id" value="' . $requestId . '"><input type="hidden" name="show_base_game" value="' . ($showBaseGame ? '1' : '0') . '"><input type="hidden" name="action" value="approve_all"><button>Approve all non-base-game requests</button></form> ';
            echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Deny all remaining packages in this child request?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_requests')) . '"><input type="hidden" name="request_id" value="' . $requestId . '"><input type="hidden" name="show_base_game" value="' . ($showBaseGame ? '1' : '0') . '"><input type="hidden" name="action" value="deny_all"><button>Deny remaining request</button></form>';
        }
        echo '</div>';

        $groups = requests_group_items(requests_raw_items($db, $requestId, $showBaseGame));
        echo '<div class="card"><h2>Packages requested by the child</h2>';
        echo '<p>Unavailable packages can be approved. They stay active as <strong>approved — waiting for file</strong> and are linked automatically after the parent imports a matching package.</p>';
        if (!$groups) {
            echo '<p class="muted">No requested packages match the current filter.</p></div>';
        } else {
            echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_requests')) . '"><input type="hidden" name="request_id" value="' . $requestId . '"><input type="hidden" name="show_base_game" value="' . ($showBaseGame ? '1' : '0') . '">';
            echo '<p><button name="action" value="approve_selected">Approve selected requests</button> <button name="action" value="deny_selected">Deny selected requests</button></p>';
            echo '<table><tr><th>Select</th><th>Status</th><th>Package</th><th>Example missing object</th><th>Missing objects</th><th>Parent availability</th><th>Current state</th></tr>';
            foreach ($groups as $group) {
                $isBase = !empty($group['is_base_game']);
                $match = !empty($group['local_file_id'])
                    ? '<a href="../file-info.php?id=' . (int)$group['local_file_id'] . '" target="_blank">' . catalog_h($group['local_package'] ?: $group['local_file']) . '</a>' . ($isBase ? ' <span class="pill amber">official base-game blocked</span>' : '')
                    : '<span class="muted">Not available yet</span>';
                $displayStatus = !empty($group['waiting_for_file']) ? 'approved — waiting for file' : (string)$group['display_status'];
                $message = (string)$group['display_message'];
                if (empty($group['local_file_id']) && $message === '') {
                    $message = 'Not available yet. Approve to keep this request active until the file is found.';
                }
                echo '<tr><td>' . (!empty($group['can_select']) && !$isBase ? '<input type="checkbox" name="group_keys[]" value="' . catalog_h($group['group_key']) . '">' : '') . '</td><td>' . catalog_h($displayStatus) . '</td><td class="mono">' . catalog_h($group['required_package']) . '</td><td class="mono path">' . catalog_h($group['example_object']) . '</td><td>' . (int)$group['object_count'] . '</td><td>' . $match . '</td><td>' . catalog_h($message) . '</td></tr>';
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
