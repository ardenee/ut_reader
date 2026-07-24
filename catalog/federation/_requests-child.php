<?php
declare(strict_types=1);

$parent = fr_parent($db);
$error = '';
$remote = [];
try {
    $remote = fr_child_call($db, $parent, ['list' => true]);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
$requests = is_array($remote['requests'] ?? null) ? $remote['requests'] : [];
$closed = $tab === 'closed';
$requests = array_values(array_filter($requests, static fn(array $request): bool => $closed
    ? in_array((string)$request['status'], ['completed', 'cancelled', 'denied'], true)
    : !in_array((string)$request['status'], ['completed', 'cancelled', 'denied'], true)));
$requestId = (int)($_GET['request_id'] ?? ($requests[0]['id'] ?? 0));

echo '<div class="card"><h2>' . ($closed ? 'Completed / Closed' : 'Requests to Parent') . '</h2>';
if ($error !== '') {
    echo CatalogUi::alert('warning', $error, 'Parent request status unavailable');
} elseif (!$requests) {
    echo '<p class="muted">No matching outgoing requests.</p>';
} else {
    echo '<table><tr><th>ID</th><th>Status</th><th>Title</th><th>Items</th><th>Summary</th><th>Submitted</th><th>Open</th></tr>';
    foreach ($requests as $request) {
        echo '<tr><td>' . (int)$request['id'] . '</td><td>' . catalog_h($request['status']) . '</td><td>' . catalog_h($request['title']) . '</td><td>' . (int)$request['item_count'] . '</td><td class="mono small">' . catalog_h(json_encode($request['status_counts'] ?? [])) . '</td><td>' . catalog_h($request['submitted_at']) . '</td><td><a href="requests.php?tab=' . $tab . '&request_id=' . (int)$request['id'] . '">open</a></td></tr>';
    }
    echo '</table>';
}
echo '</div>';

if (!$closed) {
    echo '<div class="card"><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_requests')) . '"><button name="action" value="queue_approved">Check approvals and queue still-needed downloads</button></form><p><a class="button" href="inventories.php">Request more required files</a> <a class="button" href="queue.php">Open Transfers</a></p></div>';
}
if (isset($_SESSION['fed_requests_result'])) {
    echo '<div class="card"><h2>Last approval check</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_requests_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></div>';
    unset($_SESSION['fed_requests_result']);
}

if ($requestId > 0) {
    try {
        $detail = fr_child_call($db, $parent, ['request_id' => $requestId]);
    } catch (Throwable $exception) {
        $detail = ['ok' => false, 'error' => $exception->getMessage()];
    }
    echo '<div class="card"><h2>Outgoing Request #' . $requestId . '</h2>';
    if (empty($detail['ok']) || empty($detail['request'])) {
        echo '<p class="muted">' . catalog_h($detail['error'] ?? 'Request unavailable.') . '</p>';
    } else {
        $request = $detail['request'];
        echo '<p>Status: <strong>' . catalog_h($request['status']) . '</strong> · Submitted ' . catalog_h($request['submitted_at']) . '</p>';
        if (!$closed) {
            echo '<form method="post" onsubmit="return confirm(\'Cancel this request?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_requests')) . '"><input type="hidden" name="request_id" value="' . $requestId . '"><button class="danger" name="action" value="cancel">Cancel request</button></form>';
        }
        $items = is_array($detail['items'] ?? null) ? $detail['items'] : [];
        if ($items) {
            echo '<table><tr><th>Status</th><th>Package</th><th>Required object</th><th>Parent file</th><th>Size</th><th>Message</th><th>Updated</th></tr>';
            foreach ($items as $item) {
                $file = trim((string)($item['package_name'] ?? '') . ' / ' . (string)($item['original_name'] ?? ''), ' /');
                echo '<tr><td>' . catalog_h($item['status']) . '</td><td class="mono">' . catalog_h($item['required_package']) . '</td><td class="mono path">' . catalog_h($item['required_object_path']) . '</td><td>' . catalog_h($file ?: 'not currently held') . '</td><td class="nowrap">' . catalog_h(catalog_bytes((int)($item['file_size'] ?? 0))) . '</td><td class="path">' . catalog_h($item['status_message']) . '</td><td class="nowrap">' . catalog_h($item['updated_at']) . '</td></tr>';
            }
            echo '</table>';
        }
    }
    echo '</div>';
}

$jobs = catalog_all(
    $db,
    'SELECT j.* FROM ue_federation_transfer_jobs j
     WHERE j.peer_id=? AND j.direction="download_from_parent" AND ' . $visibleJobs . '
     ORDER BY j.created_at DESC,j.id DESC LIMIT 300',
    [(int)$parent['id']]
);
echo '<div class="card"><h2>Download and Import Status</h2>';
if (!$jobs) {
    echo '<p class="muted">No Parent-approved downloads yet.</p>';
} else {
    echo '<table><tr><th>ID</th><th>Request item</th><th>Status</th><th>Progress</th><th>Local file</th><th>Message</th><th>Created</th></tr>';
    foreach ($jobs as $job) {
        $local = !empty($job['local_file_id']) ? '<a href="../file-info.php?id=' . (int)$job['local_file_id'] . '">file ' . (int)$job['local_file_id'] . '</a>' : '';
        echo '<tr><td>' . (int)$job['id'] . '</td><td>' . catalog_h($job['remote_request_item_id']) . '</td><td>' . catalog_h($job['status']) . '</td><td>' . catalog_h(catalog_bytes((int)$job['bytes_done']) . ' / ' . catalog_bytes((int)$job['bytes_total'])) . '</td><td>' . $local . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td>' . catalog_h($job['created_at']) . '</td></tr>';
    }
    echo '</table>';
}
echo '</div>';
