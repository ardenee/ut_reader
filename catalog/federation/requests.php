<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/BaseGameProtection.php';

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

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_requests');
        $requestId = (int)($_POST['request_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        $request = catalog_one($db, 'SELECT * FROM ue_federation_requests WHERE id=?', [$requestId]);
        if (!$request) {
            throw new RuntimeException('Request not found.');
        }

        if ($action === 'approve_all') {
            $items = catalog_all($db, 'SELECT i.id FROM ue_federation_request_items i WHERE i.request_id=? AND i.status="requested"', [$requestId]);
            foreach ($items as $itemRow) {
                $item = requests_item_file($db, $requestId, (int)$itemRow['id']);
                if (!$item || empty($item['local_file_id'])) {
                    $db->prepare('UPDATE ue_federation_request_items SET status="denied", status_message="Parent does not have matching file." WHERE id=?')->execute([(int)$itemRow['id']]);
                    continue;
                }
                if (base_game_file_is_protected($db, $item)) {
                    $db->prepare('UPDATE ue_federation_request_items SET status="denied", status_message=? WHERE id=?')->execute([base_game_block_message($item), (int)$itemRow['id']]);
                    continue;
                }
                $db->prepare('UPDATE ue_federation_request_items SET status="approved", status_message="Approved by parent admin." WHERE id=?')->execute([(int)$itemRow['id']]);
            }
            requests_update_header($db, $requestId);
            fed_log($db, (int)$request['peer_id'], null, 'INFO', 'REQUEST_APPROVE_ALL', 'Request ' . $requestId . ' available non-base-game items approved.');
        } elseif ($action === 'deny_all') {
            $db->prepare('UPDATE ue_federation_request_items SET status="denied", status_message="Denied by parent admin." WHERE request_id=? AND status IN ("requested","approved")')->execute([$requestId]);
            $db->prepare('UPDATE ue_federation_requests SET status="denied", approved_at=NULL, approved_by=? WHERE id=?')->execute([$_SESSION['user']['id'] ?? null, $requestId]);
            fed_log($db, (int)$request['peer_id'], null, 'INFO', 'REQUEST_DENY_ALL', 'Request ' . $requestId . ' denied.');
        } elseif ($action === 'approve_selected' || $action === 'deny_selected') {
            $ids = array_values(array_unique(array_map('intval', $_POST['item_ids'] ?? [])));
            if (!$ids) {
                throw new RuntimeException('Select at least one item.');
            }
            foreach ($ids as $id) {
                $item = requests_item_file($db, $requestId, $id);
                if (!$item || !in_array((string)$item['status'], ['requested','approved','denied'], true)) {
                    continue;
                }
                if ($action === 'deny_selected') {
                    $db->prepare('UPDATE ue_federation_request_items SET status="denied", status_message="Denied by parent admin." WHERE request_id=? AND id=?')->execute([$requestId, $id]);
                    continue;
                }
                if (empty($item['local_file_id'])) {
                    $db->prepare('UPDATE ue_federation_request_items SET status="denied", status_message="Parent does not have matching file." WHERE request_id=? AND id=?')->execute([$requestId, $id]);
                    continue;
                }
                if (base_game_file_is_protected($db, $item)) {
                    $db->prepare('UPDATE ue_federation_request_items SET status="denied", status_message=? WHERE request_id=? AND id=?')->execute([base_game_block_message($item), $requestId, $id]);
                    continue;
                }
                $db->prepare('UPDATE ue_federation_request_items SET status="approved", status_message="Approved by parent admin." WHERE request_id=? AND id=?')->execute([$requestId, $id]);
            }
            requests_update_header($db, $requestId);
            fed_log($db, (int)$request['peer_id'], null, 'INFO', strtoupper($action), 'Request ' . $requestId . ' item count=' . count($ids));
        }

        header('Location: requests.php?request_id=' . $requestId);
        exit;
    }

    if (!catalog_require_admin_page('Federation Requests')) {
        exit;
    }

    catalog_head('Federation Requests');

    $requestId = (int)($_GET['request_id'] ?? 0);
    catalog_page_header('Child File Requests', 'Parent-side approval page. Requests are kept per child. Protected base-game files are denied automatically and cannot be approved for transfer.', catalog_federation_links() + ['Conflicts' => 'conflicts.php', 'Base Game Files' => '../base-game-files.php']);

    $requests = catalog_all($db, 'SELECT r.*, p.site_name peer_name FROM ue_federation_requests r JOIN ue_federation_peers p ON p.id=r.peer_id WHERE r.direction="child_to_parent" ORDER BY r.created_at DESC LIMIT 200');
    echo '<div class="card"><h2>Requests</h2>';
    if (!$requests) {
        echo '<p class="muted">No child requests yet.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Child</th><th>Status</th><th>Title</th><th>Submitted</th><th>Open</th></tr>';
        foreach ($requests as $row) {
            echo '<tr><td class="mono">' . (int)$row['id'] . '</td><td>' . catalog_h($row['peer_name']) . '</td><td>' . catalog_h($row['status']) . '</td><td>' . catalog_h($row['title']) . '</td><td>' . catalog_h($row['submitted_at']) . '</td><td><a href="requests.php?request_id=' . (int)$row['id'] . '">open</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    if ($requestId > 0) {
        $request = catalog_one($db, 'SELECT r.*, p.site_name peer_name FROM ue_federation_requests r JOIN ue_federation_peers p ON p.id=r.peer_id WHERE r.id=?', [$requestId]);
        if (!$request) {
            throw new RuntimeException('Request not found.');
        }

        echo '<div class="card"><h2>Request #' . (int)$request['id'] . '</h2><table>';
        echo '<tr><th>Child</th><td>' . catalog_h($request['peer_name']) . '</td></tr>';
        echo '<tr><th>Status</th><td>' . catalog_h($request['status']) . '</td></tr>';
        echo '<tr><th>Hash</th><td class="mono">' . catalog_h($request['request_hash']) . '</td></tr>';
        echo '<tr><th>Notes</th><td>' . catalog_h($request['notes']) . '</td></tr>';
        echo '</table>';
        if (in_array((string)$request['status'], ['submitted','part_approved','approved'], true)) {
            echo '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_requests')) . '"><input type="hidden" name="request_id" value="' . (int)$request['id'] . '"><input type="hidden" name="action" value="approve_all"><button>Approve available non-base-game items</button></form> ';
            echo '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_requests')) . '"><input type="hidden" name="request_id" value="' . (int)$request['id'] . '"><input type="hidden" name="action" value="deny_all"><button>Deny all</button></form>';
        }
        echo '</div>';

        $items = catalog_all($db, 'SELECT i.*, f.package_name local_package, f.original_name local_file, f.game_id local_game_id, f.package_guid local_package_guid, bg.id base_game_id FROM ue_federation_request_items i LEFT JOIN ue_files f ON f.id=i.local_file_id LEFT JOIN ue_base_game_files bg ON bg.game_id=f.game_id AND bg.package_guid=f.package_guid WHERE i.request_id=? ORDER BY FIELD(i.status,"requested","approved","denied","imported","failed"), i.required_package, i.required_object_path', [$requestId]);
        echo '<div class="card"><h2>Request items</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_requests')) . '"><input type="hidden" name="request_id" value="' . (int)$requestId . '">';
        echo '<p><button name="action" value="approve_selected">Approve selected</button> <button name="action" value="deny_selected">Deny selected</button></p>';
        echo '<table><tr><th>Select</th><th>Status</th><th>Required package</th><th>Required object</th><th>Parent match</th><th>Message</th></tr>';
        foreach ($items as $item) {
            $isBase = !empty($item['base_game_id']);
            $canSelect = in_array((string)$item['status'], ['requested','approved','denied'], true);
            $match = $item['local_file_id'] ? '<a href="../file-info.php?id=' . (int)$item['local_file_id'] . '" target="_blank">' . catalog_h($item['local_package'] ?: $item['local_file']) . '</a>' . ($isBase ? ' <span class="pill amber">base-game blocked</span>' : '') : '<span class="muted">not available</span>';
            echo '<tr><td>' . ($canSelect ? '<input type="checkbox" name="item_ids[]" value="' . (int)$item['id'] . '">' : '') . '</td><td>' . catalog_h($item['status']) . '</td><td class="mono">' . catalog_h($item['required_package']) . '</td><td class="mono path">' . catalog_h($item['required_object_path']) . '</td><td>' . $match . '</td><td>' . catalog_h($item['status_message']) . '</td></tr>';
        }
        echo '</table></form></div>';
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation requests error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
