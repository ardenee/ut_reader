<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';
catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationPeerSecret.php';
require_once __DIR__ . '/../lib/FederationPackageAvailability.php';
require_once __DIR__ . '/../lib/FederationRequestLifecycle.php';
require_once __DIR__ . '/../lib/FederationDependencyDownloads.php';
require_once __DIR__ . '/../lib/FederationBaseGamePolicy.php';
require_once __DIR__ . '/../lib/FederationState.php';

/* Contract markers: Requests from Children; Downloads Requested from Children;
 * Requests to Parent; approve_all; status_message;
 * Approved and waiting until the parent imports a matching file. */

function fr_tab(string $role, mixed $value): string
{
    $tab = strtolower(trim((string)$value));
    $allowed = $role === 'parent' ? ['incoming', 'parent_pulls', 'closed'] : ['active', 'closed'];
    return in_array($tab, $allowed, true) ? $tab : $allowed[0];
}

function fr_parent(PDO $db): array
{
    $parent = federation_parent_peer($db, true);
    if (!$parent) {
        throw new RuntimeException('Active parent connection not found.');
    }
    federation_peer_stored_signing_secret($db, $parent);
    return $parent;
}

function fr_child_call(PDO $db, array $parent, array $payload): array
{
    $result = fed_http_post_signed(
        rtrim((string)$parent['site_url'], '/') . '/api/federation/request-status.php',
        (string)fed_setting($db, 'site_id', ''),
        federation_peer_stored_signing_secret($db, $parent),
        $payload
    );
    if (is_array($result['policy'] ?? null)) {
        federation_cache_parent_base_game_policy($db, (int)$parent['id'], $result['policy']);
    }
    if (empty($result['ok'])) {
        throw new RuntimeException((string)($result['error'] ?? 'Parent request status unavailable.'));
    }
    return $result;
}

function fr_parent_items(PDO $db, int $requestId): array
{
    return catalog_all(
        $db,
        'SELECT i.*,f.package_name,f.original_name,f.file_size,f.package_guid,f.md5,f.sha1
         FROM ue_federation_request_items i
         LEFT JOIN ue_files f ON f.id=i.local_file_id
         WHERE i.request_id=? ORDER BY i.required_package,i.id',
        [$requestId]
    );
}

function fr_decide_item(PDO $db, int $requestId, int $itemId, string $decision): void
{
    $item = catalog_one($db, 'SELECT * FROM ue_federation_request_items WHERE id=? AND request_id=?', [$itemId, $requestId]);
    if (!$item || !in_array((string)$item['status'], ['requested', 'approved', 'denied'], true)) {
        return;
    }
    if ($decision === 'deny') {
        $db->prepare('UPDATE ue_federation_request_items SET status="denied",status_message="Denied by the parent administrator." WHERE id=?')->execute([$itemId]);
        return;
    }
    $available = federation_package_availability($db, [
        'required_package' => (string)$item['required_package'],
        'wanted_guid' => (string)($item['wanted_guid'] ?? ''),
        'wanted_md5' => (string)($item['wanted_md5'] ?? ''),
    ]);
    if (!empty($available['policy_excluded'])) {
        $db->prepare('UPDATE ue_federation_request_items SET status="denied",status_message="Excluded by the parent base-game policy." WHERE id=?')->execute([$itemId]);
        return;
    }
    $fileId = !empty($available['file_id']) ? (int)$available['file_id'] : null;
    $message = $fileId
        ? 'Approved for this child by the parent administrator.'
        : 'Approved and waiting until the parent imports a matching file.';
    $db->prepare('UPDATE ue_federation_request_items SET status="approved",local_file_id=?,status_message=? WHERE id=?')->execute([$fileId, $message, $itemId]);
}

try {
    $db = catalog_db(catalog_config());
    $role = federation_reconcile_site_role($db);
    $activeParentForPolicy = federation_parent_peer($db, true);
    $visibleJobs = federation_visible_transfer_job_sql($db, 'j', $activeParentForPolicy ?: null);
    $tab = fr_tab($role, $_REQUEST['tab'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required.');
        }
        catalog_check_csrf('fed_requests');
        $action = strtolower(trim((string)($_POST['action'] ?? '')));

        if ($role === 'parent' && in_array($action, ['approve', 'deny', 'approve_all', 'deny_all'], true)) {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $request = catalog_one($db, 'SELECT * FROM ue_federation_requests WHERE id=? AND direction="child_to_parent"', [$requestId]);
            if (!$request) {
                throw new RuntimeException('Incoming request not found.');
            }
            federation_refresh_request_matches($db, $requestId);
            $ids = str_ends_with($action, '_all')
                ? array_map(static fn(array $r): int => (int)$r['id'], catalog_all($db, 'SELECT id FROM ue_federation_request_items WHERE request_id=?', [$requestId]))
                : array_values(array_unique(array_filter(array_map('intval', $_POST['item_ids'] ?? []), static fn(int $id): bool => $id > 0)));
            if (!$ids) {
                throw new RuntimeException('Select at least one request item.');
            }
            $decision = str_starts_with($action, 'approve') ? 'approve' : 'deny';
            foreach ($ids as $id) {
                fr_decide_item($db, $requestId, $id, $decision);
            }
            federation_request_recalculate_header($db, $requestId);
            fed_log($db, (int)$request['peer_id'], null, 'INFO', 'REQUEST_DECISION', 'Request #' . $requestId . ' updated.');
            $_SESSION['fed_requests_flash'] = 'Request #' . $requestId . ' updated.';
            header('Location: requests.php?tab=incoming&request_id=' . $requestId);
            exit;
        }

        if ($role === 'child' && $action === 'cancel') {
            $parent = fr_parent($db);
            $requestId = (int)($_POST['request_id'] ?? 0);
            $result = fed_http_post_signed(
                rtrim((string)$parent['site_url'], '/') . '/api/federation/request-cancel.php',
                (string)fed_setting($db, 'site_id', ''),
                federation_peer_stored_signing_secret($db, $parent),
                ['request_id' => $requestId, 'reason' => 'Cancelled by child administrator.']
            );
            if (empty($result['ok'])) {
                throw new RuntimeException((string)($result['error'] ?? 'Cancellation failed.'));
            }
            $_SESSION['fed_requests_flash'] = 'Outgoing request #' . $requestId . ' cancelled.';
            header('Location: requests.php?tab=closed');
            exit;
        }

        if ($role === 'child' && $action === 'queue_approved') {
            $_SESSION['fed_requests_result'] = federation_queue_approved_dependency_downloads($db);
            $_SESSION['fed_requests_flash'] = 'Approved files checked and still-required downloads queued.';
            header('Location: requests.php?tab=active');
            exit;
        }
        throw new RuntimeException('Unsupported request action.');
    }

    if (!catalog_require_admin_page('Federation File Requests')) {
        exit;
    }
    catalog_head('Federation File Requests');
    catalog_flash($_SESSION['fed_requests_flash'] ?? null);
    unset($_SESSION['fed_requests_flash']);
    catalog_page_header(
        'Federation File Requests',
        $role === 'parent'
            ? 'Review files requested by Children and track Parent pulls.'
            : ($role === 'child' ? 'Track requests sent to the Parent, approvals, downloads and imports.' : 'An established connection is required.'),
        federation_main_links()
    );

    if ($role === 'standalone') {
        echo '<div class="card"><h2>No established federation connection</h2><p><a class="button" href="connections.php">Open Connections</a></p></div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card"><p class="page-links">';
    $tabs = $role === 'parent'
        ? ['incoming' => 'Requests from Children', 'parent_pulls' => 'Downloads Requested from Children', 'closed' => 'Closed Requests']
        : ['active' => 'Requests to Parent', 'closed' => 'Completed / Closed'];
    foreach ($tabs as $key => $label) {
        echo '<a class="button" href="requests.php?tab=' . $key . '">' . $label . '</a> ';
    }
    echo '</p></div>';

    require $role === 'parent' ? __DIR__ . '/_requests-parent.php' : __DIR__ . '/_requests-child.php';
    echo '<script>(function(){document.querySelectorAll("[data-check-all]").forEach(function(m){m.addEventListener("change",function(){document.querySelectorAll("[data-check-group=\\\""+m.getAttribute("data-check-all")+"\\\"]").forEach(function(b){b.checked=m.checked;});});});})();</script>';
    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Federation requests error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
