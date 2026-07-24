<?php
declare(strict_types=1);

if ($tab === 'parent_pulls') {
    $jobs = catalog_all(
        $db,
        'SELECT j.*,p.site_name peer_name,pf.package_name,pf.original_name
         FROM ue_federation_transfer_jobs j
         JOIN ue_federation_peers p ON p.id=j.peer_id
         LEFT JOIN ue_federation_peer_files pf ON pf.peer_id=j.peer_id AND pf.remote_file_id=j.remote_file_id
         WHERE j.direction="parent_pull_from_child" AND ' . $visibleJobs . '
         ORDER BY j.created_at DESC,j.id DESC LIMIT 500'
    );
    echo '<div class="card"><h2>Downloads Requested from Children</h2><p>Parent pulls do not require Child approval.</p>';
    if (!$jobs) {
        echo '<p class="muted">No Parent pulls have been requested.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Child</th><th>File</th><th>Status</th><th>Progress</th><th>Local file</th><th>Message</th><th>Created</th></tr>';
        foreach ($jobs as $job) {
            $file = trim((string)($job['package_name'] ?? '') . ' / ' . (string)($job['original_name'] ?? ''), ' /');
            $local = !empty($job['local_file_id']) ? '<a href="../file-info.php?id=' . (int)$job['local_file_id'] . '">file ' . (int)$job['local_file_id'] . '</a>' : '';
            echo '<tr><td>' . (int)$job['id'] . '</td><td>' . catalog_h($job['peer_name']) . '</td><td>' . catalog_h($file) . '</td><td>' . catalog_h($job['status']) . '</td><td>' . catalog_h(catalog_bytes((int)$job['bytes_done']) . ' / ' . catalog_bytes((int)$job['bytes_total'])) . '</td><td>' . $local . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td class="nowrap">' . catalog_h($job['created_at']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';
    return;
}

$closed = $tab === 'closed';
$requests = catalog_all(
    $db,
    'SELECT r.*,p.site_name peer_name FROM ue_federation_requests r
     JOIN ue_federation_peers p ON p.id=r.peer_id
     WHERE r.direction="child_to_parent" AND ' . ($closed ? 'r.status IN ("completed","cancelled","denied")' : 'r.status NOT IN ("completed","cancelled","denied")') . '
     ORDER BY r.created_at DESC,r.id DESC LIMIT 300'
);
$requestId = (int)($_GET['request_id'] ?? ($requests[0]['id'] ?? 0));
echo '<div class="card"><h2>' . ($closed ? 'Closed Requests' : 'Requests from Children') . '</h2>';
if (!$requests) {
    echo '<p class="muted">No matching requests.</p>';
} else {
    echo '<table><tr><th>ID</th><th>Child</th><th>Status</th><th>Title</th><th>Submitted</th><th>Open</th></tr>';
    foreach ($requests as $request) {
        echo '<tr><td>' . (int)$request['id'] . '</td><td>' . catalog_h($request['peer_name']) . '</td><td>' . catalog_h($request['status']) . '</td><td>' . catalog_h($request['title']) . '</td><td>' . catalog_h($request['submitted_at']) . '</td><td><a href="requests.php?tab=' . $tab . '&request_id=' . (int)$request['id'] . '">open</a></td></tr>';
    }
    echo '</table>';
}
echo '</div>';

if ($requestId <= 0) {
    return;
}
federation_refresh_request_matches($db, $requestId);
$request = catalog_one($db, 'SELECT r.*,p.site_name peer_name FROM ue_federation_requests r JOIN ue_federation_peers p ON p.id=r.peer_id WHERE r.id=?', [$requestId]);
$items = fr_parent_items($db, $requestId);
if (!$request) {
    return;
}
echo '<div class="card"><h2>Request #' . $requestId . ' from ' . catalog_h($request['peer_name']) . '</h2><p>Status: <strong>' . catalog_h($request['status']) . '</strong></p>';
if (!$items) {
    echo '<p class="muted">No request items.</p>';
} else {
    echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_requests')) . '"><input type="hidden" name="request_id" value="' . $requestId . '">';
    echo '<p><label><input type="checkbox" data-check-all="request-items"> Check all</label> <button name="action" value="approve">Approve selected</button> <button name="action" value="deny">Deny selected</button> <button name="action" value="approve_all">Approve all</button> <button class="danger" name="action" value="deny_all">Deny all</button></p>';
    echo '<table><tr><th>Select</th><th>Status</th><th>Package</th><th>Required object</th><th>Parent file</th><th>Identity</th><th>Message</th></tr>';
    foreach ($items as $item) {
        $file = trim((string)($item['package_name'] ?? '') . ' / ' . (string)($item['original_name'] ?? ''), ' /');
        echo '<tr><td><input type="checkbox" data-check-group="request-items" name="item_ids[]" value="' . (int)$item['id'] . '"></td><td>' . catalog_h($item['status']) . '</td><td class="mono">' . catalog_h($item['required_package']) . '</td><td class="mono path">' . catalog_h($item['required_object_path']) . '</td><td>' . catalog_h($file ?: 'not currently held') . '</td><td>' . CatalogUi::identity((string)($item['package_guid'] ?? ''), (string)($item['md5'] ?? ''), (string)($item['sha1'] ?? '')) . '</td><td class="path">' . catalog_h($item['status_message']) . '</td></tr>';
    }
    echo '</table></form>';
}
echo '</div>';
