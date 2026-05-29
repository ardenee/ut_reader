<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';

function crs_csrf(): string
{
    $_SESSION['fed_child_request_status_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['fed_child_request_status_csrf'];
}

function crs_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['fed_child_request_status_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function crs_parent(PDO $db, int $peerId): array
{
    $parent = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1', [$peerId]);
    if (!$parent) {
        throw new RuntimeException('Active parent peer not found.');
    }
    if (empty($parent['shared_secret_plain'])) {
        throw new RuntimeException('Parent peer has no stored API key.');
    }
    return $parent;
}

function crs_poll(PDO $db, array $parent, int $requestId = 0): array
{
    $url = rtrim((string)$parent['site_url'], '/') . '/api/federation/request-status.php';
    $payload = $requestId > 0 ? ['request_id' => $requestId] : ['latest' => true];
    return fed_http_post_signed($url, (string)fed_setting($db, 'site_id', ''), (string)$parent['shared_secret_plain'], $payload);
}

function crs_cancel(PDO $db, array $parent, int $requestId): array
{
    $url = rtrim((string)$parent['site_url'], '/') . '/api/federation/request-cancel.php';
    return fed_http_post_signed($url, (string)fed_setting($db, 'site_id', ''), (string)$parent['shared_secret_plain'], [
        'request_id' => $requestId,
        'reason' => 'Cancelled from child request status page.',
    ]);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        crs_check_csrf();
        $peerId = (int)($_POST['peer_id'] ?? 0);
        $requestId = (int)($_POST['request_id'] ?? 0);
        $action = (string)($_POST['action'] ?? 'poll');
        $parent = crs_parent($db, $peerId);

        if ($action === 'cancel') {
            $result = crs_cancel($db, $parent, $requestId);
            fed_log($db, (int)$parent['id'], null, !empty($result['ok']) ? 'INFO' : 'ERROR', 'REQUEST_CANCEL_SEND', json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $result = crs_poll($db, $parent, $requestId);
            fed_log($db, (int)$parent['id'], null, !empty($result['ok']) ? 'INFO' : 'ERROR', 'REQUEST_STATUS_VIEW_POLL', json_encode($result, JSON_UNESCAPED_SLASHES));
        }
        $_SESSION['fed_child_request_status_result'] = $result;
        header('Location: request-status.php?peer_id=' . $peerId . '&request_id=' . $requestId);
        exit;
    }

    if (!catalog_require_admin_page('Child Request Status')) {
        exit;
    }

    catalog_head('Child Request Status');

    $parents = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY site_name');
    $peerId = (int)($_GET['peer_id'] ?? ($parents[0]['id'] ?? 0));
    $requestId = (int)($_GET['request_id'] ?? 0);

    catalog_page_header('Child Request Status', 'Child-side status page. Poll the parent for latest request status or cancel an active request.', catalog_federation_links() + ['Generate Request' => 'request-generate.php', 'Approved Downloads' => 'approved-downloads.php']);

    if (isset($_SESSION['fed_child_request_status_result'])) {
        echo '<div class="card"><h2>Last action result</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_child_request_status_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></div>';
        unset($_SESSION['fed_child_request_status_result']);
    }

    echo '<div class="card"><h2>Parent / request</h2>';
    if (!$parents) {
        echo '<p class="muted">No active parent peer configured.</p></div>';
        catalog_foot();
        exit;
    }
    echo '<form method="get"><p><label>Parent<br><select name="peer_id">';
    foreach ($parents as $parentRow) {
        $sel = (int)$parentRow['id'] === $peerId ? ' selected' : '';
        echo '<option value="' . (int)$parentRow['id'] . '"' . $sel . '>' . catalog_h($parentRow['site_name'] . ' - ' . $parentRow['site_url']) . '</option>';
    }
    echo '</select></label></p><p><label>Request ID, blank/latest = 0<br><input name="request_id" value="' . $requestId . '" style="width:120px"></label></p><button>Poll status</button></form></div>';

    if ($peerId > 0) {
        $parent = crs_parent($db, $peerId);
        try {
            $status = crs_poll($db, $parent, $requestId);
        } catch (Throwable $e) {
            $status = ['ok' => false, 'error' => $e->getMessage()];
        }

        echo '<div class="card"><h2>Current parent status</h2>';
        if (empty($status['ok'])) {
            echo '<p class="muted">' . catalog_h($status['error'] ?? 'Status unavailable') . '</p></div>';
        } else {
            $request = $status['request'] ?? null;
            if (!$request) {
                echo '<p class="muted">No request found on parent.</p></div>';
            } else {
                $active = in_array((string)$request['status'], ['submitted','approved','part_approved','downloading'], true);
                echo '<table><tr><th>Request ID</th><td>' . (int)$request['id'] . '</td></tr><tr><th>Status</th><td>' . catalog_h($request['status']) . '</td></tr><tr><th>Title</th><td>' . catalog_h($request['title']) . '</td></tr><tr><th>Submitted</th><td>' . catalog_h($request['submitted_at']) . '</td></tr><tr><th>Approved</th><td>' . catalog_h($request['approved_at']) . '</td></tr></table>';
                if ($active) {
                    echo '<form method="post" onsubmit="return confirm(\'Cancel this request on the parent?\')"><input type="hidden" name="csrf" value="' . catalog_h(crs_csrf()) . '"><input type="hidden" name="action" value="cancel"><input type="hidden" name="peer_id" value="' . $peerId . '"><input type="hidden" name="request_id" value="' . (int)$request['id'] . '"><button>Cancel request</button></form>';
                }
                echo '</div>';

                echo '<div class="card"><h2>Request items</h2><table><tr><th>ID</th><th>Status</th><th>Required package</th><th>Required object</th><th>Parent file</th><th>Message</th></tr>';
                foreach (($status['items'] ?? []) as $item) {
                    echo '<tr><td class="mono">' . (int)$item['id'] . '</td><td>' . catalog_h($item['status'] ?? '') . '</td><td class="mono">' . catalog_h($item['required_package'] ?? '') . '</td><td class="mono path">' . catalog_h($item['required_object_path'] ?? '') . '</td><td>' . catalog_h(($item['package_name'] ?? '') . ' / ' . ($item['original_name'] ?? '')) . '</td><td>' . catalog_h($item['status_message'] ?? '') . '</td></tr>';
                }
                echo '</table></div>';
            }
        }
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Child request status error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
