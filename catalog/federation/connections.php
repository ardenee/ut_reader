<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationPairing.php';
require_once __DIR__ . '/../lib/FederationPeerSecret.php';
require_once __DIR__ . '/../lib/FederationInventory.php';
require_once __DIR__ . '/../lib/FederationInventoryRefresh.php';
require_once __DIR__ . '/../lib/FederationState.php';

const FED_OFFICIAL_PARENT_URL = 'https://unrealdb.com';

function connections_parent_url(string $url): string
{
    $url = rtrim(trim($url), '/');
    $parts = parse_url($url);
    if (!is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || trim((string)($parts['host'] ?? '')) === ''
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        throw new RuntimeException('Parent URL must be a plain HTTPS URL without credentials, query parameters, or a fragment.');
    }
    return $url;
}

/** @return array<string,mixed> */
function connections_post_json(PDO $db, string $url, array $payload): array
{
    TrustedHttpSourceClient::configureFederationTesting(
        (string)fed_setting($db, 'allow_self_signed_federation_certificates', '0') === '1'
    );
    return TrustedHttpSourceClient::postJson(
        $url,
        [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: UnrealFileCatalogFederation/2.0',
        ],
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        1048576,
        60
    );
}

function connections_store_join_result(PDO $db, array $result): void
{
    $status = strtolower(trim((string)($result['status'] ?? 'unknown')));
    fed_set_setting($db, 'main_parent_join_status', $status !== '' ? $status : 'unknown');
    fed_set_setting($db, 'main_parent_join_status_message', trim((string)($result['message'] ?? '')));
    fed_set_setting($db, 'main_parent_join_admin_notes', trim((string)($result['admin_notes'] ?? '')));
}

/** @return array<string,mixed> */
function connections_poll_parent(PDO $db): array
{
    $identity = fed_ensure_identity($db);
    $parentUrl = connections_parent_url((string)fed_setting($db, 'main_parent_url', ''));
    $requestId = (int)fed_setting($db, 'main_parent_join_request_id', '0');
    $requestToken = trim((string)fed_setting($db, 'main_parent_join_request_token', ''));
    if ($requestId <= 0 || $requestToken === '') {
        throw new RuntimeException('No complete pending parent join request is stored.');
    }

    $result = connections_post_json($db, $parentUrl . '/api/federation/join-request-status.php', [
        'request_id' => $requestId,
        'site_id' => (string)$identity['site_id'],
        'request_token' => $requestToken,
    ]);
    if (empty($result['ok'])) {
        throw new RuntimeException('Parent status check failed: ' . (string)($result['error'] ?? 'unknown error'));
    }

    $status = strtolower(trim((string)($result['status'] ?? 'unknown')));
    if (in_array($status, ['approved', 'claimed'], true) && !empty($result['claim_ready'])) {
        $result = federation_auto_claim_parent($db, $parentUrl, $requestId, $requestToken);
    } else {
        connections_store_join_result($db, $result);
    }
    return $result;
}

function connections_approve_child(PDO $db, array $request): int
{
    if (!federation_can_accept_children($db)) {
        throw new RuntimeException('This server cannot accept a child while connected to, or waiting to join, a parent.');
    }
    if ((string)$request['status'] !== 'pending') {
        throw new RuntimeException('Only pending child join requests can be approved.');
    }
    if (catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE peer_site_id=? LIMIT 1', [(string)$request['site_id']])) {
        throw new RuntimeException('A federation connection already exists for this site ID.');
    }

    $sharedSecret = fed_random_secret();
    $secretFields = fed_prepare_peer_secret($sharedSecret);
    $ttl = max(600, (int)(fed_setting($db, 'join_claim_token_ttl_seconds', '86400') ?: 86400));
    $permissions = json_encode([
        'parent_is_master' => true,
        'parent_inventory_read_without_child_approval' => true,
        'parent_pull_without_child_approval' => true,
        'child_download_requires_parent_approval' => true,
        'child_download_scope' => 'missing_dependencies_only',
        'created_by_join_request' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO ue_federation_peers(
                peer_role,site_name,site_url,peer_site_id,peer_fingerprint,
                shared_secret_hash,shared_secret_plain,permissions_json,is_active
             ) VALUES("child",?,?,?,?,?,?,?,1)'
        );
        $stmt->execute([
            (string)$request['site_name'],
            (string)$request['site_url'],
            (string)$request['site_id'],
            (string)$request['site_fingerprint'],
            $secretFields['hash'],
            $secretFields['stored'],
            $permissions,
        ]);
        $peerId = (int)$db->lastInsertId();
        $db->prepare(
            'UPDATE ue_federation_join_requests
             SET status="approved", admin_notes=?, claim_token_hash=request_token_hash,
                 claim_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND), approved_at=NOW(),
                 approved_by=?, created_peer_id=?
             WHERE id=?'
        )->execute([
            trim((string)($_POST['admin_notes'] ?? 'Approved by parent administrator.')),
            $ttl,
            $_SESSION['user']['id'] ?? null,
            $peerId,
            (int)$request['id'],
        ]);
        federation_set_site_role($db, 'parent');
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    fed_log($db, $peerId, null, 'INFO', 'JOIN_REQUEST_APPROVED', 'Child join request #' . (int)$request['id'] . ' approved; server role set to Parent.');
    return $peerId;
}

function connections_peer(PDO $db, int $peerId): array
{
    $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=?', [$peerId]);
    if (!$peer) {
        throw new RuntimeException('Federation connection not found.');
    }
    return $peer;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    federation_reconcile_site_role($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required.');
        }
        catalog_check_csrf('fed_connections');
        $action = strtolower(trim((string)($_POST['action'] ?? '')));

        if ($action === 'submit_parent') {
            if (!federation_can_join_parent($db)) {
                throw new RuntimeException('Disconnect/remove all federation relationships before joining a parent.');
            }
            $identity = fed_ensure_identity($db);
            $mode = strtolower(trim((string)($_POST['parent_mode'] ?? 'manual')));
            $parentUrl = connections_parent_url($mode === 'official' ? FED_OFFICIAL_PARENT_URL : (string)($_POST['parent_url'] ?? ''));
            if (rtrim(strtolower((string)$identity['site_url']), '/') === strtolower($parentUrl)) {
                throw new RuntimeException('This deployment cannot join itself.');
            }
            $requestToken = fed_random_secret();
            $result = connections_post_json($db, $parentUrl . '/api/federation/join-request-submit.php', [
                'site_name' => (string)$identity['site_name'],
                'site_url' => (string)$identity['site_url'],
                'site_id' => (string)$identity['site_id'],
                'site_fingerprint' => (string)$identity['site_fingerprint'],
                'request_token' => $requestToken,
                'contact_name' => trim((string)($_POST['contact_name'] ?? '')),
                'contact_email' => trim((string)($_POST['contact_email'] ?? '')),
                'notes' => trim((string)($_POST['notes'] ?? 'Request to join this federation parent.')),
            ]);
            if (empty($result['ok'])) {
                throw new RuntimeException('Parent rejected join request: ' . (string)($result['error'] ?? 'unknown error'));
            }
            fed_set_setting($db, 'main_parent_url', $parentUrl);
            fed_set_setting($db, 'main_parent_join_request_id', (string)($result['request_id'] ?? '0'));
            fed_set_setting($db, 'main_parent_join_request_token', $requestToken);
            connections_store_join_result($db, $result);
            federation_set_site_role($db, 'standalone');
            fed_log($db, null, null, 'INFO', 'PARENT_JOIN_SUBMITTED', 'Join request submitted to ' . $parentUrl . '; local role remains Standalone until pairing completes.');
            $_SESSION['fed_connections_flash'] = 'Parent join request submitted. This server remains Standalone until the parent approves and pairing completes.';
        } elseif ($action === 'poll_parent') {
            $result = connections_poll_parent($db);
            $_SESSION['fed_connections_flash'] = (string)($result['message'] ?? 'Parent join status refreshed.');
        } elseif ($action === 'cancel_parent_join') {
            $parentUrl = trim((string)fed_setting($db, 'main_parent_url', ''));
            $requestId = (int)fed_setting($db, 'main_parent_join_request_id', '0');
            $requestToken = trim((string)fed_setting($db, 'main_parent_join_request_token', ''));
            $identity = fed_ensure_identity($db);
            $remoteMessage = '';
            if ($parentUrl !== '' && $requestId > 0 && $requestToken !== '') {
                try {
                    $remote = connections_post_json($db, connections_parent_url($parentUrl) . '/api/federation/join-request-cancel.php', [
                        'request_id' => $requestId,
                        'site_id' => (string)$identity['site_id'],
                        'request_token' => $requestToken,
                    ]);
                    $remoteMessage = !empty($remote['ok']) ? ' The parent request was cancelled.' : ' The local request was cleared; parent cancellation returned an error.';
                } catch (Throwable $ignored) {
                    $remoteMessage = ' The local request was cleared; the parent could not be contacted.';
                }
            }
            federation_clear_parent_join_state($db);
            federation_set_site_role($db, 'standalone');
            fed_log($db, null, null, 'INFO', 'PARENT_JOIN_CANCELLED', 'Pending parent join request cancelled locally.');
            $_SESSION['fed_connections_flash'] = 'Pending parent join request removed.' . $remoteMessage;
        } elseif ($action === 'set_join_requests') {
            if (!federation_can_accept_children($db)) {
                throw new RuntimeException('A Child or a server joining a Parent cannot accept child connections.');
            }
            $enabled = (string)($_POST['enabled'] ?? '0') === '1' ? '1' : '0';
            fed_set_setting($db, 'join_requests_enabled', $enabled);
            $_SESSION['fed_connections_flash'] = $enabled === '1' ? 'Child join requests enabled.' : 'Child join requests disabled.';
        } elseif (in_array($action, ['approve_child', 'deny_child'], true)) {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $request = catalog_one($db, 'SELECT * FROM ue_federation_join_requests WHERE id=?', [$requestId]);
            if (!$request) {
                throw new RuntimeException('Child join request not found.');
            }
            if ($action === 'approve_child') {
                connections_approve_child($db, $request);
                $_SESSION['fed_connections_flash'] = 'Child approved. This server is now a Parent.';
            } else {
                $db->prepare('UPDATE ue_federation_join_requests SET status="denied", admin_notes=?, claim_token_hash=NULL, claim_expires_at=NULL WHERE id=?')
                    ->execute([trim((string)($_POST['admin_notes'] ?? 'Denied by parent administrator.')), $requestId]);
                fed_log($db, null, null, 'INFO', 'JOIN_REQUEST_DENIED', 'Child join request #' . $requestId . ' denied.');
                $_SESSION['fed_connections_flash'] = 'Child join request denied.';
            }
        } elseif (in_array($action, ['toggle_peer', 'update_child', 'remove_peer', 'test_peer', 'refresh_peer'], true)) {
            $peer = connections_peer($db, (int)($_POST['peer_id'] ?? 0));
            $role = federation_site_role($db);
            if (($role === 'child' && (string)$peer['peer_role'] !== 'parent') || ($role === 'parent' && (string)$peer['peer_role'] !== 'child')) {
                throw new RuntimeException('This connection does not belong to the current federation role.');
            }
            if ($action === 'toggle_peer') {
                $newState = (int)$peer['is_active'] === 1 ? 0 : 1;
                $db->prepare('UPDATE ue_federation_peers SET is_active=? WHERE id=?')->execute([$newState, (int)$peer['id']]);
                $_SESSION['fed_connections_flash'] = $newState ? 'Connection enabled.' : 'Connection disabled.';
            } elseif ($action === 'update_child') {
                if ($role !== 'parent' || (string)$peer['peer_role'] !== 'child') {
                    throw new RuntimeException('Only a Parent may edit an established child.');
                }
                $name = trim((string)($_POST['site_name'] ?? ''));
                $url = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
                if ($name === '' || $url === '') {
                    throw new RuntimeException('Child name and URL are required.');
                }
                $db->prepare('UPDATE ue_federation_peers SET site_name=?, site_url=? WHERE id=?')->execute([$name, $url, (int)$peer['id']]);
                $_SESSION['fed_connections_flash'] = 'Child connection updated.';
            } elseif ($action === 'remove_peer') {
                federation_remove_peer($db, $peer);
                $_SESSION['fed_connections_flash'] = (string)$peer['peer_role'] === 'parent' ? 'Disconnected from parent.' : 'Child connection removed.';
            } elseif ($action === 'test_peer') {
                $result = fed_http_post_signed(
                    rtrim((string)$peer['site_url'], '/') . '/api/federation/ping.php',
                    (string)fed_setting($db, 'site_id', ''),
                    federation_peer_stored_signing_secret($db, $peer),
                    ['tested_at' => date('c')]
                );
                if (empty($result['ok'])) {
                    throw new RuntimeException('Connection test failed: ' . (string)($result['error'] ?? 'unknown error'));
                }
                $_SESSION['fed_connections_flash'] = 'Connection test succeeded: ' . (string)($result['message'] ?? 'pong');
            } else {
                $local = federation_pull_inventory_from_peer($db, (int)$peer['id']);
                if ((string)$peer['peer_role'] === 'child') {
                    $remote = federation_request_child_refresh_parent_inventory($db, (int)$peer['id']);
                    $_SESSION['fed_connections_flash'] = 'Inventories refreshed: received ' . (int)($local['received'] ?? 0) . ' child rows; child received ' . (int)($remote['received'] ?? 0) . ' parent rows.';
                } else {
                    $push = federation_push_inventory_to_parent($db, (int)$peer['id']);
                    $_SESSION['fed_connections_flash'] = 'Parent inventory refreshed; local inventory push result: ' . (!empty($push['ok']) ? 'success' : 'failed') . '.';
                }
            }
        } elseif ($action === 'stop_parent') {
            if (federation_site_role($db) !== 'parent') {
                throw new RuntimeException('This server is not a Parent.');
            }
            if (federation_child_peers($db, false) !== []) {
                throw new RuntimeException('Remove all established children before leaving Parent mode.');
            }
            $pending = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_join_requests WHERE status IN ("pending","approved")')['c'] ?? 0);
            if ($pending > 0) {
                throw new RuntimeException('Deny or expire all pending/approved child requests before leaving Parent mode.');
            }
            fed_set_setting($db, 'join_requests_enabled', '0');
            federation_set_site_role($db, 'standalone');
            $_SESSION['fed_connections_flash'] = 'Parent mode disabled. This server is now Standalone.';
        } else {
            throw new RuntimeException('Unknown federation connection action.');
        }

        header('Location: connections.php');
        exit;
    }

    if (!catalog_require_admin_page('Federation Connections')) {
        exit;
    }

    $identity = fed_ensure_identity($db);
    $role = federation_site_role($db);
    $displayRole = federation_display_role($db);
    $parent = federation_parent_peer($db, false);
    $children = federation_child_peers($db, false);
    $joinStatus = federation_parent_join_status($db);
    $joinRequestsEnabled = (string)fed_setting($db, 'join_requests_enabled', '0') === '1';
    $incoming = catalog_all(
        $db,
        'SELECT * FROM ue_federation_join_requests ORDER BY FIELD(status,"pending","approved","claimed","denied","expired"), created_at DESC, id DESC LIMIT 200'
    );

    catalog_head('Federation Connections');
    catalog_flash($_SESSION['fed_connections_flash'] ?? null);
    unset($_SESSION['fed_connections_flash']);
    catalog_page_header(
        'Federation Connections',
        'Connect this server to one parent, or accept and manage children. The role is assigned by completed federation relationships, not by a manual role selector.',
        federation_main_links()
    );

    echo '<div class="grid">';
    catalog_stat_card('Current role', $displayRole);
    catalog_stat_card('Established parent', $parent ? 1 : 0);
    catalog_stat_card('Established children', count($children));
    catalog_stat_card('Pending child requests', count(array_filter($incoming, static fn(array $row): bool => (string)$row['status'] === 'pending')));
    echo '</div>';

    echo '<div class="card"><h2>Local federation identity</h2><table>';
    echo '<tr><th>Site</th><td>' . catalog_h($identity['site_name']) . '</td></tr>';
    echo '<tr><th>URL</th><td class="mono path">' . catalog_h($identity['site_url']) . '</td></tr>';
    echo '<tr><th>Site ID</th><td class="mono">' . catalog_h($identity['site_id']) . '</td></tr>';
    echo '<tr><th>Fingerprint</th><td class="mono">' . catalog_h($identity['site_fingerprint']) . '</td></tr>';
    echo '</table></div>';

    if ($role === 'standalone') {
        echo '<div class="card"><h2>Connect to a Parent</h2>';
        if (federation_has_pending_parent_join($db)) {
            echo '<table><tr><th>Parent</th><td class="mono path">' . catalog_h((string)fed_setting($db, 'main_parent_url', '')) . '</td></tr>';
            echo '<tr><th>Request</th><td>#' . (int)fed_setting($db, 'main_parent_join_request_id', '0') . '</td></tr>';
            echo '<tr><th>Status</th><td><strong>' . catalog_h($joinStatus) . '</strong></td></tr>';
            echo '<tr><th>Message</th><td>' . catalog_h((string)fed_setting($db, 'main_parent_join_status_message', 'Waiting for parent administrator approval.')) . '</td></tr></table>';
            echo '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_connections')) . '"><button name="action" value="poll_parent">Check status now</button></form> ';
            echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Cancel and remove this pending parent join request?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_connections')) . '"><button class="danger" name="action" value="cancel_parent_join">Cancel request</button></form>';
        } elseif (federation_can_join_parent($db)) {
            echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_connections')) . '">';
            echo '<p><label>Parent selection<br><select name="parent_mode"><option value="manual">Custom Parent URL</option><option value="official">Official UnrealDB parent</option></select></label></p>';
            echo '<p><label>Parent URL<br><input name="parent_url" style="min-width:640px" placeholder="https://parent.example.com/catalog"></label></p>';
            echo '<p><label>Contact name<br><input name="contact_name" style="min-width:360px"></label></p>';
            echo '<p><label>Contact email<br><input type="email" name="contact_email" style="min-width:360px"></label></p>';
            echo '<p><label>Notes<br><textarea name="notes" rows="4" style="width:100%">Request to join this federation parent.</textarea></label></p>';
            echo '<p><button name="action" value="submit_parent">Submit parent join request</button></p></form>';
        } else {
            echo '<p class="muted">This server has child relationships or pending child requests and cannot join a parent.</p>';
        }
        echo '</div>';
    }

    if ($role === 'child' && $parent) {
        echo '<div class="card"><h2>Connected Parent</h2><table>';
        echo '<tr><th>Name</th><td><strong>' . catalog_h($parent['site_name']) . '</strong></td></tr>';
        echo '<tr><th>URL</th><td class="mono path">' . catalog_h($parent['site_url']) . '</td></tr>';
        echo '<tr><th>Active</th><td>' . ((int)$parent['is_active'] ? 'yes' : 'no') . '</td></tr>';
        echo '<tr><th>Last contact</th><td>' . catalog_h((string)($parent['last_seen_at'] ?? 'never')) . '</td></tr>';
        echo '</table><p>';
        foreach ([
            'test_peer' => 'Test connection',
            'refresh_peer' => 'Refresh inventories',
            'toggle_peer' => (int)$parent['is_active'] ? 'Disable' : 'Enable',
        ] as $action => $label) {
            echo '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_connections')) . '"><input type="hidden" name="peer_id" value="' . (int)$parent['id'] . '"><button name="action" value="' . $action . '">' . catalog_h($label) . '</button></form> ';
        }
        echo '</p><form method="post" onsubmit="return confirm(\'Disconnect from this parent and cancel active federation transfers?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_connections')) . '"><input type="hidden" name="peer_id" value="' . (int)$parent['id'] . '"><button class="danger" name="action" value="remove_peer">Disconnect from Parent</button></form></div>';
    }

    if ($role !== 'child') {
        echo '<div class="card"><h2>Accept Child Connections</h2>';
        if (!federation_can_accept_children($db)) {
            echo '<p class="muted">Child connections cannot be accepted while this server is joining or connected to a parent.</p>';
        } else {
            echo '<p>Incoming child join requests are currently <strong>' . ($joinRequestsEnabled ? 'enabled' : 'disabled') . '</strong>.</p>';
            echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_connections')) . '"><input type="hidden" name="enabled" value="' . ($joinRequestsEnabled ? '0' : '1') . '"><button name="action" value="set_join_requests">' . ($joinRequestsEnabled ? 'Stop accepting child requests' : 'Accept child requests') . '</button></form>';
        }
        echo '</div>';

        echo '<div class="card"><h2>Incoming Child Join Requests</h2>';
        if (!$incoming) {
            echo '<p class="muted">No child join requests have been received.</p>';
        } else {
            echo '<table><tr><th>Status</th><th>Child</th><th>URL</th><th>Contact</th><th>Received</th><th>Review</th></tr>';
            foreach ($incoming as $request) {
                echo '<tr><td>' . catalog_h($request['status']) . '</td><td>' . catalog_h($request['site_name']) . '</td><td class="mono path">' . catalog_h($request['site_url']) . '</td><td>' . catalog_h(trim((string)($request['contact_name'] ?? '') . ' ' . (string)($request['contact_email'] ?? ''))) . '</td><td class="nowrap">' . catalog_h($request['created_at']) . '</td><td>';
                if ((string)$request['status'] === 'pending') {
                    echo '<details><summary>Review</summary><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_connections')) . '"><input type="hidden" name="request_id" value="' . (int)$request['id'] . '"><p><label>Admin notes<br><textarea name="admin_notes" rows="3" style="width:100%"></textarea></label></p><button name="action" value="approve_child">Approve</button> <button class="danger" name="action" value="deny_child">Deny</button></form></details>';
                } else {
                    echo catalog_h((string)($request['admin_notes'] ?? ''));
                }
                echo '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }

    if ($role === 'parent') {
        echo '<div class="card"><h2>Established Children</h2>';
        if (!$children) {
            echo '<p class="muted">No established children.</p>';
        } else {
            echo '<table><tr><th>Child</th><th>URL</th><th>Identity</th><th>Active</th><th>Actions</th><th>Edit / Remove</th></tr>';
            foreach ($children as $child) {
                echo '<tr><td><strong>' . catalog_h($child['site_name']) . '</strong></td><td class="mono path">' . catalog_h($child['site_url']) . '</td><td class="mono small">ID: ' . catalog_h($child['peer_site_id']) . '<br>FP: ' . catalog_h($child['peer_fingerprint']) . '</td><td>' . ((int)$child['is_active'] ? 'yes' : 'no') . '</td><td>';
                foreach ([
                    'test_peer' => 'Test',
                    'refresh_peer' => 'Refresh inventories',
                    'toggle_peer' => (int)$child['is_active'] ? 'Disable' : 'Enable',
                ] as $action => $label) {
                    echo '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_connections')) . '"><input type="hidden" name="peer_id" value="' . (int)$child['id'] . '"><button name="action" value="' . $action . '">' . catalog_h($label) . '</button></form> ';
                }
                echo '<a class="button" href="inventories.php?peer_id=' . (int)$child['id'] . '">Inventory</a> <a class="button" href="requests.php?peer_id=' . (int)$child['id'] . '">Requests</a></td><td>';
                echo '<details><summary>Edit</summary><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_connections')) . '"><input type="hidden" name="peer_id" value="' . (int)$child['id'] . '"><p><label>Name<br><input name="site_name" value="' . catalog_h($child['site_name']) . '" required></label></p><p><label>URL<br><input name="site_url" value="' . catalog_h($child['site_url']) . '" required style="min-width:420px"></label></p><button name="action" value="update_child">Save</button></form></details>';
                echo '<form method="post" onsubmit="return confirm(\'Remove this child and cancel its active federation transfers?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_connections')) . '"><input type="hidden" name="peer_id" value="' . (int)$child['id'] . '"><button class="danger" name="action" value="remove_peer">Remove child</button></form></td></tr>';
            }
            echo '</table>';
        }
        if (!$children && !$incoming) {
            echo '<form method="post" onsubmit="return confirm(\'Stop acting as a federation Parent and return to Standalone mode?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_connections')) . '"><button class="danger" name="action" value="stop_parent">Stop being a Parent</button></form>';
        }
        echo '</div>';
    }

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Federation connections error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
