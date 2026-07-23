<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationPeerSecret.php';
require_once __DIR__ . '/../lib/FederationBaseGamePolicy.php';

function crs_parent(PDO $db, int $peerId): array
{
    $parent = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1', [$peerId]);
    if (!$parent) {
        throw new RuntimeException('Active parent connection not found.');
    }
    return $parent;
}

function crs_poll(PDO $db, array $parent, int $requestId = 0): array
{
    $url = rtrim((string)$parent['site_url'], '/') . '/api/federation/request-status.php';
    $payload = $requestId > 0 ? ['request_id' => $requestId] : ['latest' => true];
    $result = fed_http_post_signed(
        $url,
        (string)fed_setting($db, 'site_id', ''),
        federation_peer_stored_signing_secret($db, $parent),
        $payload
    );
    if (is_array($result['policy'] ?? null)) {
        federation_cache_parent_base_game_policy($db, (int)$parent['id'], $result['policy']);
    }
    return $result;
}

function crs_cancel(PDO $db, array $parent, int $requestId): array
{
    $url = rtrim((string)$parent['site_url'], '/') . '/api/federation/request-cancel.php';
    return fed_http_post_signed(
        $url,
        (string)fed_setting($db, 'site_id', ''),
        federation_peer_stored_signing_secret($db, $parent),
        [
            'request_id' => $requestId,
            'reason' => 'Cancelled by the child administrator from Outgoing Requests.',
        ]
    );
}

function crs_url(int $peerId, int $requestId): string
{
    return 'request-status.php?' . http_build_query(['peer_id' => $peerId, 'request_id' => $requestId]);
}

function crs_group_key(array $item): string
{
    return hash(
        'sha256',
        strtolower(trim((string)($item['required_package'] ?? '')))
        . "\0" . strtolower(trim((string)($item['wanted_guid'] ?? '')))
        . "\0" . strtolower(trim((string)($item['wanted_md5'] ?? '')))
    );
}

function crs_clarify_message(string $message): string
{
    $message = trim($message);
    return match ($message) {
        'Parent does not currently have a matching file.',
        'Parent does not have matching file.',
        'Not found in this parent catalog; this package cannot be approved.',
        'Not found in this parent\'s catalog. This package cannot be approved until the parent imports a matching file.'
            => 'Not available on the selected parent yet. An approved request remains active until the parent imports a matching file.',
        'Approved by parent admin.' => 'Approved for this child by the parent administrator.',
        'Denied by parent admin.' => 'Denied by the parent administrator.',
        default => $message,
    };
}

/**
 * @param array<int,mixed> $items
 * @return list<array<string,mixed>>
 */
function crs_group_items(array $items): array
{
    $groups = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = crs_group_key($item);
        if (!isset($groups[$key])) {
            $groups[$key] = $item + [
                'statuses' => [],
                'object_paths' => [],
                'messages' => [],
                'raw_count' => 0,
                'is_base_game' => false,
            ];
        }
        $groups[$key]['raw_count']++;
        $groups[$key]['is_base_game'] = !empty($groups[$key]['is_base_game']) || !empty($item['is_base_game']);
        $status = trim((string)($item['status'] ?? ''));
        if ($status !== '') {
            $groups[$key]['statuses'][$status] = true;
        }
        $path = trim((string)($item['required_object_path'] ?? ''));
        if ($path !== '') {
            $groups[$key]['object_paths'][strtolower($path)] = $path;
        }
        $message = crs_clarify_message((string)($item['status_message'] ?? ''));
        if ($message !== '') {
            $groups[$key]['messages'][$message] = true;
        }
        if (empty($groups[$key]['local_file_id']) && !empty($item['local_file_id'])) {
            foreach (['local_file_id', 'package_name', 'original_name'] as $field) {
                $groups[$key][$field] = $item[$field] ?? null;
            }
        }
    }

    foreach ($groups as &$group) {
        $statuses = array_keys($group['statuses']);
        $group['display_status'] = count($statuses) === 1 ? $statuses[0] : 'mixed';
        $group['example_object'] = (string)(reset($group['object_paths']) ?: ($group['required_object_path'] ?? ''));
        $group['object_count'] = max(1, count($group['object_paths']));
        $group['display_message'] = implode(' ', array_keys($group['messages']));
        $group['waiting_for_file'] = $group['display_status'] === 'approved' && empty($group['local_file_id']);
    }
    unset($group);

    return array_values($groups);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $role = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        if ($role !== 'child') {
            throw new RuntimeException('Outgoing requests are available only while this site is in Child mode.');
        }
        catalog_check_csrf('fed_child_request_status');
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
        header('Location: ' . crs_url($peerId, $requestId));
        exit;
    }

    if (!catalog_require_admin_page('Outgoing Requests')) {
        exit;
    }

    catalog_head('Outgoing Requests');
    $parents = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY site_name');
    $peerId = (int)($_GET['peer_id'] ?? ($parents[0]['id'] ?? 0));
    $requestId = (int)($_GET['request_id'] ?? 0);

    catalog_page_header(
        'Outgoing Requests',
        'Requests sent by this child to its parent. Every row is a missing dependency; base-game packages remain visible because dependency completion is the exception to the ordinary ignore policy.',
        catalog_federation_links() + ['Request Centre' => 'request-center.php', 'Missing Files' => 'request-generate.php', 'Approved Downloads' => 'approved-downloads.php']
    );

    if ($role !== 'child') {
        echo '<div class="card"><h2>Outgoing dependency requests disabled</h2><p>This site is not in Child mode. A parent obtains files from children through Child Inventories and Parent Pull instead.</p></div>';
        catalog_foot();
        exit;
    }

    if (isset($_SESSION['fed_child_request_status_result'])) {
        $result = (array)$_SESSION['fed_child_request_status_result'];
        $message = !empty($result['ok'])
            ? 'The parent request state was updated successfully.'
            : 'The parent request action failed: ' . (string)($result['error'] ?? 'Unknown parent response.');
        echo CatalogUi::alert(!empty($result['ok']) ? 'success' : 'warning', $message, 'Last action');
        unset($_SESSION['fed_child_request_status_result']);
    }

    echo '<div class="card"><h2>Select outgoing request</h2>';
    if (!$parents) {
        echo '<p class="muted">No active parent connection is configured.</p></div>';
        catalog_foot();
        exit;
    }
    echo '<form method="get"><p><label>Parent<br><select name="peer_id">';
    foreach ($parents as $parentRow) {
        $sel = (int)$parentRow['id'] === $peerId ? ' selected' : '';
        echo '<option value="' . (int)$parentRow['id'] . '"' . $sel . '>' . catalog_h($parentRow['site_name'] . ' - ' . $parentRow['site_url']) . '</option>';
    }
    echo '</select></label></p><p><label>Request ID <span class="muted">(0 means latest)</span><br><input name="request_id" value="' . $requestId . '" style="width:120px"></label></p>';
    echo '<button>Refresh request status</button></form></div>';

    if ($peerId > 0) {
        $parent = crs_parent($db, $peerId);
        try {
            $status = crs_poll($db, $parent, $requestId);
        } catch (Throwable $e) {
            $status = ['ok' => false, 'error' => $e->getMessage()];
        }

        echo '<div class="card"><h2>Request state</h2>';
        if (empty($status['ok'])) {
            echo '<p class="muted">' . catalog_h($status['error'] ?? 'Status unavailable') . '</p></div>';
        } else {
            $request = $status['request'] ?? null;
            if (!$request) {
                echo '<p class="muted">No outgoing request was found on this parent.</p></div>';
            } else {
                $active = in_array((string)$request['status'], ['submitted', 'approved', 'part_approved', 'downloading'], true);
                $groups = crs_group_items(is_array($status['items'] ?? null) ? $status['items'] : []);
                $baseCount = count(array_filter($groups, static fn(array $group): bool => !empty($group['is_base_game'])));
                $waitingCount = count(array_filter($groups, static fn(array $group): bool => !empty($group['waiting_for_file'])));

                echo '<table>';
                echo '<tr><th>Direction</th><td><strong>This child</strong> &rarr; <strong>' . catalog_h($parent['site_name']) . '</strong> parent</td></tr>';
                echo '<tr><th>Request ID</th><td>' . (int)$request['id'] . '</td></tr>';
                echo '<tr><th>Status</th><td>' . catalog_h($request['status']) . '</td></tr>';
                echo '<tr><th>Dependency packages</th><td>' . count($groups) . '</td></tr>';
                echo '<tr><th>Base-game dependency exceptions</th><td>' . $baseCount . '</td></tr>';
                echo '<tr><th>Waiting for parent file</th><td>' . $waitingCount . '</td></tr>';
                echo '<tr><th>Title</th><td>' . catalog_h($request['title']) . '</td></tr>';
                echo '<tr><th>Submitted</th><td>' . catalog_h($request['submitted_at']) . '</td></tr>';
                echo '</table>';
                echo '<p class="muted">' . catalog_h(federation_base_game_policy_label($db, $parent)) . '</p>';
                if ($active) {
                    echo '<form method="post" onsubmit="return confirm(\'Cancel this outgoing request on the parent?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_child_request_status')) . '"><input type="hidden" name="action" value="cancel"><input type="hidden" name="peer_id" value="' . $peerId . '"><input type="hidden" name="request_id" value="' . (int)$request['id'] . '"><button>Cancel outgoing request</button></form>';
                }
                echo '</div>';

                echo '<div class="card"><h2>Packages requested from the parent</h2>';
                echo '<p><strong>Approved + waiting</strong> means the parent accepted the request but has not found the file yet. The row remains active and is linked automatically when the file is later imported.</p>';
                if (!$groups) {
                    echo '<p class="muted">No requested packages were found.</p></div>';
                } else {
                    echo '<table><tr><th>Status</th><th>Package</th><th>Example missing object</th><th>Missing objects</th><th>Parent file</th><th>Decision / current state</th></tr>';
                    foreach ($groups as $group) {
                        $parentFile = trim((string)($group['package_name'] ?? ''));
                        $originalName = trim((string)($group['original_name'] ?? ''));
                        $fileLabel = trim($parentFile . ($parentFile !== '' && $originalName !== '' ? ' / ' : '') . $originalName);
                        $displayStatus = !empty($group['waiting_for_file']) ? 'approved — waiting for file' : (string)$group['display_status'];
                        $badge = !empty($group['is_base_game']) ? ' <span class="pill amber">base-game dependency</span>' : '';
                        echo '<tr><td>' . catalog_h($displayStatus) . '</td><td class="mono">' . catalog_h($group['required_package'] ?? '') . $badge . '</td><td class="mono path">' . catalog_h($group['example_object']) . '</td><td>' . (int)$group['object_count'] . '</td><td>' . ($fileLabel !== '' ? catalog_h($fileLabel) : '<span class="muted">Not available yet</span>') . '</td><td>' . catalog_h($group['display_message']) . '</td></tr>';
                    }
                    echo '</table></div>';
                }
            }
        }
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Outgoing request status error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
