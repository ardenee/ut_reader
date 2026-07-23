<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationPeerSecret.php';
require_once __DIR__ . '/../lib/FederationDependencyDownloads.php';
require_once __DIR__ . '/../lib/FederationBaseGamePolicy.php';

const AD_PAGE_SIZE = 50;

function ad_page(mixed $value): int
{
    return max(1, (int)$value);
}

function ad_parent(PDO $db, int $peerId): array
{
    $parent = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1', [$peerId]);
    if (!$parent) {
        throw new RuntimeException('Active parent connection not found.');
    }
    federation_peer_stored_signing_secret($db, $parent);
    return $parent;
}

/** @return array<string,mixed> */
function ad_poll_status(PDO $db, array $parent): array
{
    $result = fed_http_post_signed(
        rtrim((string)$parent['site_url'], '/') . '/api/federation/request-status.php',
        (string)fed_setting($db, 'site_id', ''),
        federation_peer_stored_signing_secret($db, $parent),
        ['latest' => true]
    );
    if (is_array($result['policy'] ?? null)) {
        federation_cache_parent_base_game_policy($db, (int)$parent['id'], $result['policy']);
    }
    return $result;
}

function ad_url(int $peerId, int $itemPage, int $jobPage): string
{
    return 'approved-downloads.php?' . http_build_query([
        'peer_id' => $peerId,
        'item_page' => $itemPage,
        'job_page' => $jobPage,
    ]);
}

function ad_page_links(int $page, int $pages, callable $urlForPage): void
{
    if ($pages <= 1) {
        return;
    }
    echo '<p class="page-links">';
    if ($page > 1) {
        echo '<a class="button" href="' . catalog_h($urlForPage($page - 1)) . '">Previous</a> ';
    }
    echo '<span>Page ' . $page . ' of ' . $pages . '</span> ';
    if ($page < $pages) {
        echo '<a class="button" href="' . catalog_h($urlForPage($page + 1)) . '">Next</a>';
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
        if ($siteRole !== 'child') {
            throw new RuntimeException('Approved parent downloads are available only while this server is in Child mode.');
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
    $itemPage = ad_page($_GET['item_page'] ?? 1);
    $jobPage = ad_page($_GET['job_page'] ?? 1);

    catalog_head('Approved Downloads');
    catalog_page_header(
        'Approved Downloads',
        'Child-side page showing dependency files the parent approved for this child. Base-game files appear only when they complete a missing dependency.',
        catalog_federation_links() + ['Missing Files' => 'missing-files.php', 'Outgoing Requests' => 'request-status.php', 'Worker' => 'worker-run.php', 'Transfer Queue' => 'queue.php']
    );

    if ($siteRole !== 'child') {
        echo '<div class="card"><h2>Approved Downloads disabled</h2><p>This page applies only to a Child server receiving parent-approved dependency files.</p></div>';
        catalog_foot();
        exit;
    }

    if (isset($_SESSION['fed_approved_result'])) {
        echo '<div class="card"><h2>Last approval check</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_approved_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></div>';
        unset($_SESSION['fed_approved_result']);
    }

    echo '<div class="card"><h2>Parent approval source</h2>';
    if (!$parents) {
        echo '<p class="muted">No active parent connection is configured.</p></div>';
        catalog_foot();
        exit;
    }
    echo '<form method="get"><input type="hidden" name="item_page" value="1"><input type="hidden" name="job_page" value="1"><label>Parent<br><select name="peer_id" onchange="this.form.submit()">';
    foreach ($parents as $parent) {
        $selected = (int)$parent['id'] === $peerId ? ' selected' : '';
        echo '<option value="' . (int)$parent['id'] . '"' . $selected . '>' . catalog_h($parent['site_name'] . ' - ' . $parent['site_url']) . '</option>';
    }
    echo '</select></label></form>';
    echo '<form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_approved_downloads')) . '"><input type="hidden" name="peer_id" value="' . $peerId . '"><button>Check approvals and queue still-needed downloads now</button></form>';
    echo '<p class="muted">The scheduled federation worker performs the same approval check automatically.</p></div>';

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
        echo '<p class="muted">No dependency request exists on this parent.</p></div>';
    } else {
        $request = $status['request'];
        echo '<table><tr><th>Request ID</th><td>' . (int)$request['id'] . '</td></tr><tr><th>Status</th><td>' . catalog_h($request['status']) . '</td></tr><tr><th>Title</th><td>' . catalog_h($request['title']) . '</td></tr><tr><th>Submitted</th><td>' . catalog_h($request['submitted_at'] ?? '') . '</td></tr><tr><th>Last updated</th><td>' . catalog_h($request['updated_at'] ?? '') . '</td></tr><tr><th>Base-game policy</th><td>' . catalog_h(federation_base_game_policy_label($db, $parent)) . '</td></tr></table></div>';

        $allItems = array_values(array_filter(
            is_array($status['items'] ?? null) ? $status['items'] : [],
            static fn(mixed $item): bool => is_array($item)
        ));
        usort($allItems, static function (array $a, array $b): int {
            $dateCompare = strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
            return $dateCompare !== 0 ? $dateCompare : ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
        });
        $itemTotal = count($allItems);
        $baseGameCount = count(array_filter($allItems, static fn(array $item): bool => !empty($item['is_base_game'])));
        $itemPages = max(1, (int)ceil($itemTotal / AD_PAGE_SIZE));
        $itemPage = min($itemPage, $itemPages);
        $pageItems = array_slice($allItems, ($itemPage - 1) * AD_PAGE_SIZE, AD_PAGE_SIZE);

        echo '<div class="card"><h2>Request items</h2>';
        echo '<p>Showing <strong>' . count($pageItems) . '</strong> of <strong>' . $itemTotal . '</strong> dependency items. Base-game dependency exceptions: <strong>' . $baseGameCount . '</strong>.</p>';
        if (!$pageItems) {
            echo '<p class="muted">No request items were found.</p>';
        } else {
            echo '<table><tr><th>Status</th><th>Still needed locally</th><th>Required package</th><th>Required object</th><th>Parent file</th><th>Size</th><th>Updated</th><th>Message</th></tr>';
            foreach ($pageItems as $item) {
                $stillNeeded = federation_dependency_request_still_needed(
                    $db,
                    (string)($item['required_package'] ?? ''),
                    (string)($item['required_object_path'] ?? '')
                );
                $baseBadge = !empty($item['is_base_game']) ? ' <span class="pill amber">base-game dependency</span>' : '';
                $parentFile = trim((string)($item['package_name'] ?? '') . ' / ' . (string)($item['original_name'] ?? ''), ' /');
                echo '<tr><td>' . catalog_h($item['status'] ?? '') . '</td><td>' . ($stillNeeded ? '<span class="pill amber">yes</span>' : '<span class="muted">no</span>') . '</td><td class="mono">' . catalog_h($item['required_package'] ?? '') . $baseBadge . '</td><td class="mono path">' . catalog_h($item['required_object_path'] ?? '') . '</td><td>' . catalog_h($parentFile !== '' ? $parentFile : 'not available') . '</td><td class="nowrap">' . catalog_h(catalog_bytes((int)($item['file_size'] ?? 0))) . '</td><td class="nowrap">' . catalog_h($item['updated_at'] ?? '') . '</td><td class="path">' . catalog_h($item['status_message'] ?? '') . '</td></tr>';
            }
            echo '</table>';
            ad_page_links(
                $itemPage,
                $itemPages,
                fn(int $page): string => ad_url($peerId, $page, $jobPage)
            );
        }
        echo '</div>';
    }

    $jobTotal = (int)(catalog_one(
        $db,
        'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE direction="download_from_parent" AND peer_id=?',
        [$peerId]
    )['c'] ?? 0);
    $jobPages = max(1, (int)ceil($jobTotal / AD_PAGE_SIZE));
    $jobPage = min($jobPage, $jobPages);
    $jobOffset = ($jobPage - 1) * AD_PAGE_SIZE;
    $jobs = catalog_all(
        $db,
        'SELECT j.*, p.site_name peer_name
         FROM ue_federation_transfer_jobs j
         JOIN ue_federation_peers p ON p.id=j.peer_id
         WHERE j.direction="download_from_parent" AND j.peer_id=?
         ORDER BY j.created_at DESC, j.id DESC
         LIMIT ' . AD_PAGE_SIZE . ' OFFSET ' . $jobOffset,
        [$peerId]
    );
    echo '<div class="card"><h2>Download and import history</h2><p>Most recent jobs are shown first.</p>';
    if (!$jobs) {
        echo '<p class="muted">No approved dependency downloads have been queued from this parent.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Parent</th><th>Request item</th><th>Remote file</th><th>Status</th><th>Bytes</th><th>Message</th><th>Created</th></tr>';
        foreach ($jobs as $job) {
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($job['peer_name']) . '</td><td class="mono">' . catalog_h($job['remote_request_item_id']) . '</td><td class="mono">' . catalog_h($job['remote_file_id']) . '</td><td>' . catalog_h($job['status']) . '</td><td class="nowrap">' . catalog_h((int)$job['bytes_done'] . ' / ' . (int)$job['bytes_total']) . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td class="nowrap">' . catalog_h($job['created_at']) . '</td></tr>';
        }
        echo '</table>';
        ad_page_links(
            $jobPage,
            $jobPages,
            fn(int $page): string => ad_url($peerId, $itemPage, $page)
        );
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
