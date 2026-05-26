<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';

function requests_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function requests_csrf(): string
{
    $_SESSION['fed_requests_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['fed_requests_csrf'];
}

function requests_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['fed_requests_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
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

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!requests_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        requests_check_csrf();
        $requestId = (int)($_POST['request_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        $request = catalog_one($db, 'SELECT * FROM ue_federation_requests WHERE id=?', [$requestId]);
        if (!$request) {
            throw new RuntimeException('Request not found.');
        }

        if ($action === 'approve_all') {
            $db->prepare('UPDATE ue_federation_request_items SET status="approved" WHERE request_id=? AND local_file_id IS NOT NULL AND status="requested"')->execute([$requestId]);
            $db->prepare('UPDATE ue_federation_request_items SET status="denied", status_message="Parent does not have matching file." WHERE request_id=? AND local_file_id IS NULL AND status="requested"')->execute([$requestId]);
            requests_update_header($db, $requestId);
            fed_log($db, (int)$request['peer_id'], null, 'INFO', 'REQUEST_APPROVE_ALL', 'Request ' . $requestId . ' available items approved.');
        } elseif ($action === 'deny_all') {
            $db->prepare('UPDATE ue_federation_request_items SET status="denied", status_message="Denied by parent admin." WHERE request_id=? AND status IN ("requested","approved")')->execute([$requestId]);
            $db->prepare('UPDATE ue_federation_requests SET status="denied", approved_at=NULL, approved_by=? WHERE id=?')->execute([$_SESSION['user']['id'] ?? null, $requestId]);
            fed_log($db, (int)$request['peer_id'], null, 'INFO', 'REQUEST_DENY_ALL', 'Request ' . $requestId . ' denied.');
        } elseif ($action === 'approve_selected' || $action === 'deny_selected') {
            $ids = array_values(array_unique(array_map('intval', $_POST['item_ids'] ?? [])));
            if (!$ids) {
                throw new RuntimeException('Select at least one item.');
            }
            $newStatus = $action === 'approve_selected' ? 'approved' : 'denied';
            $message = $action === 'approve_selected' ? 'Approved by parent admin.' : 'Denied by parent admin.';
            $stmt = $db->prepare('UPDATE ue_federation_request_items SET status=?, status_message=? WHERE request_id=? AND id=? AND status IN ("requested","approved","denied")' . ($newStatus === 'approved' ? ' AND local_file_id IS NOT NULL' : ''));
            foreach ($ids as $id) {
                $stmt->execute([$newStatus, $message, $requestId, $id]);
            }
            requests_update_header($db, $requestId);
            fed_log($db, (int)$request['peer_id'], null, 'INFO', strtoupper($action), 'Request ' . $requestId . ' item count=' . count($ids));
        }

        header('Location: requests.php?request_id=' . $requestId);
        exit;
    }

    catalog_head('Federation Requests');

    if (!requests_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="../index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    $requestId = (int)($_GET['request_id'] ?? 0);
    echo '<div class="card"><h1>Child File Requests</h1><p class="muted">Parent-side approval page. Requests are kept per child. Regenerated child requests mark old submitted/approved requests as updated.</p><p><a class="button" href="admin.php">Federation admin</a> <a class="button" href="conflicts.php">Conflicts</a> <a class="button" href="logs.php">Logs</a></p></div>';

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
            echo '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(requests_csrf()) . '"><input type="hidden" name="request_id" value="' . (int)$request['id'] . '"><input type="hidden" name="action" value="approve_all"><button>Approve available items</button></form> ';
            echo '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(requests_csrf()) . '"><input type="hidden" name="request_id" value="' . (int)$request['id'] . '"><input type="hidden" name="action" value="deny_all"><button>Deny all</button></form>';
        }
        echo '</div>';

        $items = catalog_all($db, 'SELECT i.*, f.package_name local_package, f.original_name local_file FROM ue_federation_request_items i LEFT JOIN ue_files f ON f.id=i.local_file_id WHERE i.request_id=? ORDER BY FIELD(i.status,"requested","approved","denied","imported","failed"), i.required_package, i.required_object_path', [$requestId]);
        echo '<div class="card"><h2>Request items</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(requests_csrf()) . '"><input type="hidden" name="request_id" value="' . (int)$requestId . '">';
        echo '<p><button name="action" value="approve_selected">Approve selected</button> <button name="action" value="deny_selected">Deny selected</button></p>';
        echo '<table><tr><th>Select</th><th>Status</th><th>Required package</th><th>Required object</th><th>Parent match</th><th>Message</th></tr>';
        foreach ($items as $item) {
            $canSelect = in_array((string)$item['status'], ['requested','approved','denied'], true);
            $match = $item['local_file_id'] ? '<a href="../file-info.php?id=' . (int)$item['local_file_id'] . '" target="_blank">' . catalog_h($item['local_package'] ?: $item['local_file']) . '</a>' : '<span class="muted">not available</span>';
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
