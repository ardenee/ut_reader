<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationDependencyDownloads.php';

function ad_parent(PDO $db, int $peerId): array
{
    $parent = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1', [$peerId]);
    if (!$parent) {
        throw new RuntimeException('Active parent peer not found.');
    }
    if (fed_peer_secret($db, $parent) === '') {
        throw new RuntimeException('Parent peer has no stored API key.');
    }
    return $parent;
}

/** @return array<string,mixed> */
function ad_poll_status(PDO $db, array $parent): array
{
    return fed_http_post_signed(
        rtrim((string)$parent['site_url'], '/') . '/api/federation/request-status.php',
        (string)fed_setting($db, 'site_id', ''),
        fed_peer_secret($db, $parent),
        ['latest' => true]
    );
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_approved_downloads');
        $peerId = (int)($_POST['peer_id'] ?? 0);
        ad_parent($db, $peerId);
        $result = federation_queue_approved_dependency_downloads($db);
        $_SESSION['fed_approved_result'] = $result;
        header('Location: approved-downloads.php?peer_id=' . $peerId);
        exit;
    }

    if (!catalog_require_admin_page('Approved Downloads')) {
        exit;
    }

    $parents = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY site_name');
    $peerId = (int)($_GET['peer_id'] ?? ($parents[0]['id'] ?? 0));

    catalog_head('Approved Downloads');
    catalog_page_header(
        'Approved Dependency Downloads',
        'Child-side status page. The federation worker polls parent approvals and automatically queues only files still required by local missing dependencies.',
        catalog_federation_links() + ['Generate Request' => 'request-generate.php', 'Worker' => 'worker-run.php', 'Transfer Queue' => 'queue.php']
    );

    if (isset($_SESSION['fed_approved_result'])) {
        echo '<div class="card"><h2>Last approval check</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_approved_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></div>';
        unset($_SESSION['fed_approved_result']);
    }

    echo '<div class="card"><h2>Parent</h2>';
    if (!$parents) {
        echo '<p class="muted">No active parent peer is configured.</p></div>';
        catalog_foot();
        exit;
    }
    echo '<form method="get"><select name="peer_id">';
    foreach ($parents as $parent) {
        $selected = (int)$parent['id'] === $peerId ? ' selected' : '';
        echo '<option value="' . (int)$parent['id'] . '"' . $selected . '>' . catalog_h($parent['site_name'] . ' - ' . $parent['site_url']) . '</option>';
    }
    echo '</select> <button>View status</button></form>';
    echo '<form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_approved_downloads')) . '"><input type="hidden" name="peer_id" value="' . $peerId . '"><button>Check approvals and queue needed downloads now</button></form>';
    echo '<p class="muted">The scheduled federation worker performs the same check automatically before each transfer run.</p></div>';

    $parent = ad_parent($db, $peerId);
    try {
        $status = ad_poll_status($db, $parent);
    } catch (Throwable $error) {
        $status = ['ok' => false, 'error' => $error->getMessage()];
    }

    echo '<div class="card"><h2>Latest parent decision</h2>';
    if (empty($status['ok'])) {
        echo '<p class="muted">' . catalog_h($status['error'] ?? 'Status unavailable.') . '</p></div>';
    } elseif (empty($status['request'])) {
        echo '<p class="muted">No dependency request exists on the parent.</p></div>';
    } else {
        $request = $status['request'];
        echo '<table><tr><th>Request ID</th><td>' . (int)$request['id'] . '</td></tr><tr><th>Status</th><td>' . catalog_h($request['status']) . '</td></tr><tr><th>Title</th><td>' . catalog_h($request['title']) . '</td></tr></table></div>';

        echo '<div class="card"><h2>Request items</h2><table><tr><th>Status</th><th>Still needed locally</th><th>Required package</th><th>Required object</th><th>Parent file</th><th>Size</th><th>Message</th></tr>';
        foreach (($status['items'] ?? []) as $item) {
            $stillNeeded = federation_dependency_request_still_needed(
                $db,
                (string)($item['required_package'] ?? ''),
                (string)($item['required_object_path'] ?? '')
            );
            echo '<tr><td>' . catalog_h($item['status'] ?? '') . '</td><td>' . ($stillNeeded ? '<span class="pill amber">yes</span>' : '<span class="muted">no</span>') . '</td><td class="mono">' . catalog_h($item['required_package'] ?? '') . '</td><td class="mono path">' . catalog_h($item['required_object_path'] ?? '') . '</td><td>' . catalog_h(($item['package_name'] ?? '') . ' / ' . ($item['original_name'] ?? '')) . '</td><td>' . catalog_h(catalog_bytes((int)($item['file_size'] ?? 0))) . '</td><td>' . catalog_h($item['status_message'] ?? '') . '</td></tr>';
        }
        echo '</table></div>';
    }

    $jobs = catalog_all(
        $db,
        'SELECT j.*, p.site_name peer_name
         FROM ue_federation_transfer_jobs j
         JOIN ue_federation_peers p ON p.id=j.peer_id
         WHERE j.direction="download_from_parent"
         ORDER BY j.created_at DESC LIMIT 100'
    );
    echo '<div class="card"><h2>Recent automatic dependency downloads</h2>';
    if (!$jobs) {
        echo '<p class="muted">No child dependency downloads have been queued.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Parent</th><th>Request item</th><th>Remote file</th><th>Status</th><th>Bytes</th><th>Message</th><th>Created</th></tr>';
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
