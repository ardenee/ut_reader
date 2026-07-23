<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

function peers_filter(mixed $value): string
{
    $value = strtolower(trim((string)$value));
    return in_array($value, ['parent', 'child'], true) ? $value : 'all';
}

function peers_url(string $filter): string
{
    return 'peers.php' . ($filter !== 'all' ? '?role=' . rawurlencode($filter) : '');
}

function peers_role_allowed(string $siteRole, string $peerRole): bool
{
    return ($siteRole === 'child' && $peerRole === 'parent')
        || ($siteRole === 'parent' && $peerRole === 'child');
}

function peers_clear_parent_state(PDO $db): void
{
    foreach ([
        'main_parent_url',
        'main_parent_join_request_id',
        'main_parent_join_request_token',
        'main_parent_join_status_message',
        'main_parent_join_admin_notes',
    ] as $key) {
        fed_set_setting($db, $key, '');
    }
    fed_set_setting($db, 'main_parent_join_status', 'none');
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $siteRole = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    $filter = peers_filter($_REQUEST['role'] ?? 'all');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_peers');
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $redirectFilter = peers_filter($_POST['role_filter'] ?? $filter);
        if (!in_array($action, ['toggle', 'update', 'remove'], true)) {
            throw new RuntimeException('Unsupported connection action. Federation connections must be created through Join a Parent or an incoming Child Join Request.');
        }

        $id = (int)($_POST['id'] ?? 0);
        $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=?', [$id]);
        if (!$peer) {
            throw new RuntimeException('Connection not found.');
        }
        $peerRole = (string)$peer['peer_role'];
        if (!peers_role_allowed($siteRole, $peerRole)) {
            throw new RuntimeException('This connection type is disabled for the current site role.');
        }

        if ($action === 'toggle') {
            $newState = (int)$peer['is_active'] === 1 ? 0 : 1;
            $db->prepare('UPDATE ue_federation_peers SET is_active=? WHERE id=?')->execute([$newState, $id]);
            fed_log($db, $id, null, 'INFO', 'PEER_TOGGLE', 'Connection active state set to ' . $newState . '.');
            $_SESSION['fed_peers_flash'] = 'Connection ' . ($newState === 1 ? 'enabled.' : 'disabled.');
        } elseif ($action === 'update') {
            if ($siteRole === 'child' && $peerRole === 'parent') {
                throw new RuntimeException('A child cannot edit its parent identity or URL. Disconnect and join the correct parent instead.');
            }
            if (!($siteRole === 'parent' && $peerRole === 'child')) {
                throw new RuntimeException('Connection editing is unavailable for this role.');
            }
            $siteName = trim((string)($_POST['site_name'] ?? ''));
            $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
            if ($siteName === '' || $siteUrl === '') {
                throw new RuntimeException('Child name and URL are required.');
            }
            $db->prepare('UPDATE ue_federation_peers SET site_name=?, site_url=? WHERE id=?')->execute([$siteName, $siteUrl, $id]);
            fed_log($db, $id, null, 'INFO', 'PEER_UPDATE', 'Child connection details updated by parent administrator.');
            $_SESSION['fed_peers_flash'] = 'Child connection details updated.';
        } else {
            $peerName = (string)$peer['site_name'];
            $db->prepare('DELETE FROM ue_federation_peers WHERE id=?')->execute([$id]);
            if ($siteRole === 'child' && $peerRole === 'parent') {
                peers_clear_parent_state($db);
                $_SESSION['fed_peers_flash'] = 'Disconnected from parent: ' . $peerName . '. You may now join another parent.';
                fed_log($db, null, null, 'INFO', 'PARENT_DISCONNECT', 'Child disconnected from parent: ' . $peerName . '.');
            } else {
                $_SESSION['fed_peers_flash'] = 'Child connection removed: ' . $peerName . '.';
                fed_log($db, null, null, 'INFO', 'CHILD_REMOVE', 'Parent removed child connection: ' . $peerName . '.');
            }
        }

        header('Location: ' . peers_url($redirectFilter));
        exit;
    }

    if (!catalog_require_admin_page('Federation Connections')) {
        exit;
    }

    $title = $filter === 'parent' ? 'Parents' : ($filter === 'child' ? 'Children' : 'Federation Connections');
    $description = $filter === 'parent'
        ? 'The parent selected through the join process. Parent identity and URL are read-only on a child; disconnect before joining a different parent.'
        : ($filter === 'child'
            ? 'Child sites that joined this parent. Child join requests arrive on the Incoming Child Join Requests page.'
            : 'Role-aware federation connections. Connections are created only through the pairing workflow.');

    catalog_head($title);
    catalog_flash($_SESSION['fed_peers_flash'] ?? null);
    unset($_SESSION['fed_peers_flash']);
    catalog_page_header(
        $title,
        $description,
        catalog_federation_links() + ['Parents' => 'peers.php?role=parent', 'Children' => 'peers.php?role=child', 'Join a Parent' => 'join-main-parent.php', 'Child Join Requests' => 'join-requests.php']
    );

    echo '<div class="card"><h2>Server mode</h2><p>This server is running in <strong>' . catalog_h(ucfirst($siteRole)) . '</strong> mode.</p><p class="page-links">';
    echo '<a class="button" href="peers.php">All connections</a> ';
    echo '<a class="button" href="peers.php?role=parent">Parents</a> ';
    echo '<a class="button" href="peers.php?role=child">Children</a></p></div>';

    if ($filter === 'child' && $siteRole !== 'parent') {
        echo '<div class="card"><h2>Children unavailable</h2><p>A ' . catalog_h(ucfirst($siteRole)) . ' server cannot manage child sites. Only Parent mode accepts incoming child join requests.</p>';
        if ($siteRole === 'child') {
            echo '<p><a class="button" href="peers.php?role=parent">View Parent</a></p>';
        } else {
            echo '<p><a class="button" href="settings.php">Federation Settings</a></p>';
        }
        echo '</div>';
        catalog_foot();
        exit;
    }
    if ($filter === 'parent' && $siteRole !== 'child') {
        echo '<div class="card"><h2>Parents unavailable</h2><p>Only a server running in Child mode connects to a parent.</p><p><a class="button" href="settings.php">Federation Settings</a></p></div>';
        catalog_foot();
        exit;
    }

    $where = $filter === 'all' ? '' : ' WHERE peer_role=' . $db->quote($filter);
    $peers = catalog_all($db, 'SELECT * FROM ue_federation_peers' . $where . ' ORDER BY peer_role, site_name, id');
    echo '<div class="card"><h2>Configured ' . catalog_h(strtolower($title)) . '</h2>';
    if (!$peers) {
        echo '<p class="muted">No matching connections are configured.</p>';
        if ($filter === 'parent' && $siteRole === 'child') {
            echo '<p><a class="button" href="join-main-parent.php">Join a Parent</a></p>';
        } elseif ($filter === 'child' && $siteRole === 'parent') {
            echo '<p>Children appear here after their incoming join requests are approved.</p><p><a class="button" href="join-requests.php">Incoming Child Join Requests</a></p>';
        }
    } else {
        echo '<table><tr><th>Role</th><th>Name</th><th>URL</th><th>Site identity</th><th>Active</th><th>Actions</th><th>Manage</th></tr>';
        foreach ($peers as $peer) {
            $peerRole = (string)$peer['peer_role'];
            $roleAllowed = peers_role_allowed($siteRole, $peerRole);
            $actions = $peerRole === 'child'
                ? '<a href="peer-inventory.php?peer_id=' . (int)$peer['id'] . '">Inventory</a> · <a href="requests.php">Incoming file requests</a> · <a href="parent-pull.php?peer_id=' . (int)$peer['id'] . '">Parent pull</a>'
                : '<a href="missing-files.php?peer_id=' . (int)$peer['id'] . '">Missing files</a> · <a href="request-status.php?peer_id=' . (int)$peer['id'] . '">Outgoing requests</a> · <a href="approved-downloads.php?peer_id=' . (int)$peer['id'] . '">Approved downloads</a>';

            echo '<tr><td>' . catalog_h($peerRole) . '</td><td><strong>' . catalog_h($peer['site_name']) . '</strong></td><td class="mono path">' . catalog_h($peer['site_url']) . '</td>';
            echo '<td><div class="mono small nowrap"><strong>ID:</strong> ' . catalog_h($peer['peer_site_id']) . '</div><div class="mono small nowrap"><strong>FP:</strong> ' . catalog_h($peer['peer_fingerprint']) . '</div></td>';
            echo '<td>' . ((int)$peer['is_active'] ? 'yes' : 'no') . '</td><td>' . $actions . '</td><td>';
            if (!$roleAllowed) {
                echo '<span class="muted">Disabled by server mode</span>';
            } else {
                echo '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peers')) . '"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . (int)$peer['id'] . '"><input type="hidden" name="role_filter" value="' . catalog_h($filter) . '"><button>' . ((int)$peer['is_active'] ? 'Disable' : 'Enable') . '</button></form> ';
                if ($siteRole === 'parent' && $peerRole === 'child') {
                    echo '<details><summary>Edit child</summary><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peers')) . '"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="' . (int)$peer['id'] . '"><input type="hidden" name="role_filter" value="' . catalog_h($filter) . '"><p><label>Name<br><input name="site_name" value="' . catalog_h($peer['site_name']) . '" required></label></p><p><label>URL<br><input name="site_url" value="' . catalog_h($peer['site_url']) . '" required style="min-width:420px"></label></p><button>Save child</button></form></details>';
                }
                $removeLabel = $peerRole === 'parent' ? 'Disconnect' : 'Remove child';
                $confirm = $peerRole === 'parent'
                    ? 'Disconnect from this parent? Cached requests, inventory, and transfer data for this connection will be removed.'
                    : 'Remove this child connection and its cached federation data?';
                echo '<form method="post" style="margin-top:8px" onsubmit="return confirm(' . catalog_h(json_encode($confirm)) . ')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peers')) . '"><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="' . (int)$peer['id'] . '"><input type="hidden" name="role_filter" value="' . catalog_h($filter) . '"><button class="danger">' . catalog_h($removeLabel) . '</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    echo '<div class="card"><h2>How connections are created</h2>';
    if ($siteRole === 'child') {
        echo '<p>A child creates its parent connection through <a href="join-main-parent.php">Join a Parent</a>. It must disconnect the existing parent before another parent can be joined.</p>';
    } elseif ($siteRole === 'parent') {
        echo '<p>Child connections are created from incoming requests on <a href="join-requests.php">Incoming Child Join Requests</a>.</p>';
    } else {
        echo '<p>Select Parent or Child mode in Federation Settings before creating connections.</p>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation connections error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
