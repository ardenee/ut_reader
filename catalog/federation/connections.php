<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders the Federation Connections administration interface.
 * Why: Request/session/rendering concerns remain here while pairing protocol, role transitions and persistence are delegated.
 * Role: Federation UI entry point backed by shared federation services.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationPairing.php';
require_once __DIR__ . '/../lib/FederationPeerSecret.php';
require_once __DIR__ . '/../lib/FederationInventory.php';
require_once __DIR__ . '/../lib/FederationInventoryRefresh.php';
require_once __DIR__ . '/../lib/FederationState.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationConnectionActions;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationConnectionQuery;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    federation_reconcile_site_role($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required.');
        }
        catalog_check_csrf('fed_connections');
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        $_SESSION['fed_connections_flash'] = (new CatalogFederationConnectionActions($db))
            ->handle($_POST, $userId);
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
    $incoming = (new CatalogFederationConnectionQuery($db))->incomingJoinRequests(200);

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
