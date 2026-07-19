<?php
declare(strict_types=1);


require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

function ad_parent(PDO $db, int $peerId): array
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

function ad_poll_status(PDO $db, array $parent): array
{
    $url = rtrim((string)$parent['site_url'], '/') . '/api/federation/request-status.php';
    return fed_http_post_signed($url, (string)fed_setting($db, 'site_id', ''), (string)$parent['shared_secret_plain'], ['latest' => true]);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_approved_downloads');
        $action = (string)($_POST['action'] ?? 'poll');
        $peerId = (int)($_POST['peer_id'] ?? 0);
        $parent = ad_parent($db, $peerId);

        if ($action === 'poll') {
            $result = ad_poll_status($db, $parent);
            $_SESSION['fed_approved_result'] = $result;
            fed_log($db, (int)$parent['id'], null, !empty($result['ok']) ? 'INFO' : 'ERROR', 'REQUEST_STATUS_POLL', json_encode($result, JSON_UNESCAPED_SLASHES));
        } elseif ($action === 'queue') {
            $itemIds = array_values(array_unique(array_map('intval', $_POST['request_item_ids'] ?? [])));
            if (!$itemIds) {
                throw new RuntimeException('Select at least one approved item to queue.');
            }
            $insert = $db->prepare('INSERT INTO ue_federation_transfer_jobs(peer_id,remote_request_item_id,direction,remote_file_id,status,speed_limit_kbps,wait_after_seconds,bytes_total) VALUES(?,?,"download_from_parent",?,"queued",?,?,?)');
            $queued = 0;
            $status = ad_poll_status($db, $parent);
            foreach (($status['items'] ?? []) as $item) {
                if (!in_array((int)$item['id'], $itemIds, true)) {
                    continue;
                }
                if (($item['status'] ?? '') !== 'approved' || empty($item['local_file_id'])) {
                    continue;
                }
                $insert->execute([(int)$parent['id'], (int)$item['id'], (int)$item['local_file_id'], (int)fed_setting($db, 'max_download_kbps', '0'), (int)fed_setting($db, 'delay_between_downloads_seconds', '5'), (int)($item['file_size'] ?? 0)]);
                $queued++;
            }
            $_SESSION['fed_approved_result'] = ['ok' => true, 'queued' => $queued];
            fed_log($db, (int)$parent['id'], null, 'INFO', 'APPROVED_DOWNLOAD_QUEUE', 'Queued ' . $queued . ' approved parent download(s).');
        }

        header('Location: approved-downloads.php?peer_id=' . $peerId);
        exit;
    }

    if (!catalog_require_admin_page('Approved Downloads')) {
        exit;
    }

    catalog_head('Approved Downloads');

    $parents = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY site_name');
    $peerId = (int)($_GET['peer_id'] ?? ($parents[0]['id'] ?? 0));

    catalog_page_header('Approved Downloads From Parent', 'Child-side page. Poll parent request status, queue approved items, then use the transfer and import runners to download/import one file at a time.', catalog_federation_links() + ['Generate Request' => 'request-generate.php', 'Transfer Runner' => 'transfer-run.php', 'Import Runner' => 'import-run.php']);

    if (isset($_SESSION['fed_approved_result'])) {
        echo '<div class="card"><h2>Last result</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_approved_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></div>';
        unset($_SESSION['fed_approved_result']);
    }

    echo '<div class="card"><h2>Parent</h2>';
    if (!$parents) {
        echo '<p class="muted">No active parent peer configured.</p></div>';
        catalog_foot();
        exit;
    }
    echo '<form method="get"><select name="peer_id">';
    foreach ($parents as $parent) {
        $sel = (int)$parent['id'] === $peerId ? ' selected' : '';
        echo '<option value="' . (int)$parent['id'] . '"' . $sel . '>' . catalog_h($parent['site_name'] . ' - ' . $parent['site_url']) . '</option>';
    }
    echo '</select> <button>Open</button></form>';
    echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_approved_downloads')) . '"><input type="hidden" name="action" value="poll"><input type="hidden" name="peer_id" value="' . $peerId . '"><button>Poll latest request status</button></form></div>';

    if ($peerId > 0) {
        $parent = ad_parent($db, $peerId);
        try {
            $status = ad_poll_status($db, $parent);
        } catch (Throwable $e) {
            $status = ['ok' => false, 'error' => $e->getMessage()];
        }

        echo '<div class="card"><h2>Latest parent status</h2>';
        if (empty($status['ok'])) {
            echo '<p class="muted">' . catalog_h($status['error'] ?? 'Status unavailable') . '</p></div>';
        } else {
            $request = $status['request'] ?? null;
            if (!$request) {
                echo '<p class="muted">No request found on parent.</p></div>';
            } else {
                echo '<table><tr><th>Request ID</th><td>' . (int)$request['id'] . '</td></tr><tr><th>Status</th><td>' . catalog_h($request['status']) . '</td></tr><tr><th>Title</th><td>' . catalog_h($request['title']) . '</td></tr></table></div>';
                echo '<div class="card"><h2>Approved items</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_approved_downloads')) . '"><input type="hidden" name="action" value="queue"><input type="hidden" name="peer_id" value="' . $peerId . '">';
                echo '<table><tr><th>Queue</th><th>Status</th><th>Required package</th><th>Parent file</th><th>Size</th><th>MD5</th><th>Message</th></tr>';
                foreach (($status['items'] ?? []) as $item) {
                    $canQueue = ($item['status'] ?? '') === 'approved' && !empty($item['local_file_id']);
                    echo '<tr><td>' . ($canQueue ? '<input type="checkbox" name="request_item_ids[]" value="' . (int)$item['id'] . '">' : '') . '</td><td>' . catalog_h($item['status'] ?? '') . '</td><td class="mono">' . catalog_h($item['required_package'] ?? '') . '</td><td>' . catalog_h(($item['package_name'] ?? '') . ' / ' . ($item['original_name'] ?? '')) . '</td><td>' . catalog_h(catalog_bytes((int)($item['file_size'] ?? 0))) . '</td><td class="mono small">' . catalog_h($item['md5'] ?? '') . '</td><td>' . catalog_h($item['status_message'] ?? '') . '</td></tr>';
                }
                echo '</table><p><button>Queue selected approved downloads</button></p></form></div>';
            }
        }
    }

    $jobs = catalog_all($db, 'SELECT j.*, p.site_name peer_name FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id WHERE j.direction="download_from_parent" ORDER BY j.created_at DESC LIMIT 100');
    echo '<div class="card"><h2>Recent child download jobs</h2>';
    if (!$jobs) {
        echo '<p class="muted">No child download jobs queued yet.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Parent</th><th>Remote item</th><th>Remote file</th><th>Status</th><th>Bytes</th><th>Message</th><th>Created</th></tr>';
        foreach ($jobs as $job) {
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($job['peer_name']) . '</td><td class="mono">' . catalog_h($job['remote_request_item_id']) . '</td><td class="mono">' . catalog_h($job['remote_file_id']) . '</td><td>' . catalog_h($job['status']) . '</td><td>' . catalog_h((int)$job['bytes_done'] . ' / ' . (int)$job['bytes_total']) . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td>' . catalog_h($job['created_at']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Approved downloads error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
