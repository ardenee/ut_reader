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
        $action = (string)($_POST['action'] ?? 'add');
        $redirectFilter = peers_filter($_POST['role_filter'] ?? $filter);

        if (in_array($action, ['toggle', 'update', 'remove'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=?', [$id]);
            if (!$peer) {
                throw new RuntimeException('Connection not found.');
            }
            if (!peers_role_allowed($siteRole, (string)$peer['peer_role'])) {
                throw new RuntimeException('This connection type is disabled for the current site role.');
            }

            if ($action === 'toggle') {
                $newState = (int)$peer['is_active'] === 1 ? 0 : 1;
                $db->prepare('UPDATE ue_federation_peers SET is_active=? WHERE id=?')->execute([$newState, $id]);
                fed_log($db, $id, null, 'INFO', 'PEER_TOGGLE', 'Connection active state set to ' . $newState . '.');
                $_SESSION['fed_peers_flash'] = 'Connection ' . ((int)$newState === 1 ? 'enabled.' : 'disabled.');
            } elseif ($action === 'update') {
                $siteName = trim((string)($_POST['site_name'] ?? ''));
                $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
                if ($siteName === '' || $siteUrl === '') {
                    throw new RuntimeException('Connection name and URL are required.');
                }
                $db->prepare('UPDATE ue_federation_peers SET site_name=?, site_url=? WHERE id=?')->execute([$siteName, $siteUrl, $id]);
                fed_log($db, $id, null, 'INFO', 'PEER_UPDATE', 'Connection details updated.');
                $_SESSION['fed_peers_flash'] = 'Connection details updated.';
            } else {
                $peerName = (string)$peer['site_name'];
                $db->prepare('DELETE FROM ue_federation_peers WHERE id=?')->execute([$id]);
                fed_log($db, null, null, 'INFO', 'PEER_REMOVE', 'Removed ' . (string)$peer['peer_role'] . ' connection: ' . $peerName . '. Related cached inventory, requests, jobs, and nonces were removed by database cascade rules.');
                $_SESSION['fed_peers_flash'] = 'Connection removed: ' . $peerName . '.';
            }

            header('Location: ' . peers_url($redirectFilter));
            exit;
        }

        $peerRole = strtolower(trim((string)($_POST['peer_role'] ?? '')));
        if (!in_array($peerRole, ['parent', 'child'], true)) {
            throw new RuntimeException('Invalid connection role.');
        }
        if (!peers_role_allowed($siteRole, $peerRole)) {
            throw new RuntimeException('A ' . $siteRole . ' site cannot add a ' . $peerRole . ' connection. Change the site role first.');
        }

        $siteName = trim((string)($_POST['site_name'] ?? ''));
        $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
        $peerSiteId = strtolower(trim((string)($_POST['peer_site_id'] ?? '')));
        $peerFingerprint = strtoupper(trim((string)($_POST['peer_fingerprint'] ?? '')));
        $sharedSecret = trim((string)($_POST['shared_secret'] ?? ''));

        if ($siteName === '' || $siteUrl === '' || $peerSiteId === '' || $peerFingerprint === '') {
            throw new RuntimeException('Site name, URL, site ID, and fingerprint are required.');
        }
        if ($sharedSecret === '') {
            $sharedSecret = fed_random_secret();
        }
        $secretFields = fed_prepare_peer_secret($sharedSecret);
        $permissions = [
            'parent_is_master' => true,
            'parent_inventory_read_without_child_approval' => true,
            'parent_pull_without_child_approval' => true,
            'child_download_requires_parent_approval' => true,
            'child_download_scope' => 'missing_dependencies_only',
            'created_by' => 'manual_peer_add',
        ];

        $stmt = $db->prepare(
            'INSERT INTO ue_federation_peers(
                peer_role, site_name, site_url, peer_site_id, peer_fingerprint,
                shared_secret_hash, shared_secret_plain, permissions_json, is_active
             ) VALUES(?,?,?,?,?,?,?,?,1)'
        );
        $stmt->execute([
            $peerRole,
            $siteName,
            $siteUrl,
            $peerSiteId,
            $peerFingerprint,
            $secretFields['hash'],
            $secretFields['stored'],
            json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $peerId = (int)$db->lastInsertId();
        fed_log($db, $peerId, null, 'INFO', 'PEER_ADD', 'Recovery connection added: ' . $siteName . ' as ' . $peerRole . '.');

        $_SESSION['fed_peer_secret_once'] = $sharedSecret;
        $_SESSION['fed_peer_secret_peer'] = $siteName;
        $_SESSION['fed_peers_flash'] = 'Recovery connection added.';
        header('Location: ' . peers_url($filter !== 'all' ? $filter : $peerRole));
        exit;
    }

    if (!catalog_require_admin_page('Federation Connections')) {
        exit;
    }

    $title = $filter === 'parent' ? 'Parents' : ($filter === 'child' ? 'Children' : 'Federation Connections');
    $description = $filter === 'parent'
        ? 'Parent connections used by this child to request and download missing dependency files.'
        : ($filter === 'child'
            ? 'Child connections managed by this parent. Open inventories, review incoming requests, edit, disable, or remove a child.'
            : 'All configured federation connections. Use Parents or Children for role-specific management.');

    catalog_head($title);
    catalog_flash($_SESSION['fed_peers_flash'] ?? null);
    unset($_SESSION['fed_peers_flash']);
    catalog_page_header(
        $title,
        $description,
        catalog_federation_links() + ['Parents' => 'peers.php?role=parent', 'Children' => 'peers.php?role=child', 'Requests' => 'request-center.php']
    );

    echo '<div class="card"><h2>Connection type</h2><p class="page-links">';
    echo '<a class="button" href="peers.php">All connections</a> ';
    echo '<a class="button" href="peers.php?role=parent">Parents</a> ';
    echo '<a class="button" href="peers.php?role=child">Children</a>';
    echo '</p><p>Current site role: <strong>' . catalog_h(ucfirst($siteRole)) . '</strong>.</p></div>';

    if ($filter === 'child' && $siteRole !== 'parent') {
        echo '<div class="card"><h2>Children disabled</h2><p>Child management is disabled while this site is in ' . catalog_h(ucfirst($siteRole)) . ' mode. Only a Parent site may accept joins or manage child connections.</p><p><a class="button" href="settings.php">Federation Settings</a></p></div>';
        catalog_foot();
        exit;
    }
    if ($filter === 'parent' && $siteRole !== 'child') {
        echo '<div class="card"><h2>Parents disabled</h2><p>Parent connection management is disabled while this site is in ' . catalog_h(ucfirst($siteRole)) . ' mode. Only a Child site connects to parents.</p><p><a class="button" href="settings.php">Federation Settings</a></p></div>';
        catalog_foot();
        exit;
    }

    if (isset($_SESSION['fed_peer_secret_once'])) {
        echo '<div class="card"><h2>Manual shared secret for ' . catalog_h($_SESSION['fed_peer_secret_peer'] ?? 'connection') . '</h2><p class="muted">Copy this secret to the matching site now. It is shown only once.</p><pre class="mono">' . catalog_h($_SESSION['fed_peer_secret_once']) . '</pre></div>';
        unset($_SESSION['fed_peer_secret_once'], $_SESSION['fed_peer_secret_peer']);
    }

    $where = $filter === 'all' ? '' : ' WHERE peer_role=' . $db->quote($filter);
    $peers = catalog_all($db, 'SELECT * FROM ue_federation_peers' . $where . ' ORDER BY peer_role, site_name');
    echo '<div class="card"><h2>Configured ' . catalog_h(strtolower($title)) . '</h2>';
    if (!$peers) {
        echo '<p class="muted">No matching connections are configured.</p>';
    } else {
        echo '<table><tr><th>Role</th><th>Name</th><th>URL</th><th>Site ID / fingerprint</th><th>Secret</th><th>Active</th><th>Shortcuts</th><th>Manage</th></tr>';
        foreach ($peers as $peer) {
            $storedSecret = (string)($peer['shared_secret_plain'] ?? '');
            $hasSecret = $storedSecret === '' ? 'missing' : (fed_secret_store()->isEncrypted($storedSecret) ? 'encrypted' : 'legacy plaintext');
            $roleAllowed = peers_role_allowed($siteRole, (string)$peer['peer_role']);
            $shortcuts = '';
            if ((string)$peer['peer_role'] === 'child') {
                $shortcuts = '<a href="peer-inventory.php?peer_id=' . (int)$peer['id'] . '">Inventory</a> · <a href="requests.php">Requests</a> · <a href="parent-pull.php?peer_id=' . (int)$peer['id'] . '">Parent pull</a> · <a href="join-requests.php">Join requests</a>';
            } else {
                $shortcuts = '<a href="request-generate.php?peer_id=' . (int)$peer['id'] . '">Missing files</a> · <a href="request-status.php?peer_id=' . (int)$peer['id'] . '">Outgoing requests</a> · <a href="approved-downloads.php?peer_id=' . (int)$peer['id'] . '">Downloads</a>';
            }

            echo '<tr><td>' . catalog_h($peer['peer_role']) . '</td><td><strong>' . catalog_h($peer['site_name']) . '</strong></td><td class="mono path">' . catalog_h($peer['site_url']) . '</td>';
            echo '<td><div class="mono small nowrap"><strong>ID:</strong> ' . catalog_h($peer['peer_site_id']) . '</div><div class="mono small nowrap"><strong>FP:</strong> ' . catalog_h($peer['peer_fingerprint']) . '</div></td>';
            echo '<td>' . catalog_h($hasSecret) . '</td><td>' . ((int)$peer['is_active'] ? 'yes' : 'no') . '</td><td>' . $shortcuts . '</td><td>';
            if (!$roleAllowed) {
                echo '<span class="muted">Disabled by site role</span>';
            } else {
                echo '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peers')) . '"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . (int)$peer['id'] . '"><input type="hidden" name="role_filter" value="' . catalog_h($filter) . '"><button>' . ((int)$peer['is_active'] ? 'Disable' : 'Enable') . '</button></form> ';
                echo '<details><summary>Edit</summary><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peers')) . '"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="' . (int)$peer['id'] . '"><input type="hidden" name="role_filter" value="' . catalog_h($filter) . '"><p><label>Name<br><input name="site_name" value="' . catalog_h($peer['site_name']) . '" required></label></p><p><label>URL<br><input name="site_url" value="' . catalog_h($peer['site_url']) . '" required style="min-width:420px"></label></p><button>Save connection</button></form></details>';
                echo '<form method="post" style="margin-top:8px" onsubmit="return confirm(\'Remove this connection and its cached federation data?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peers')) . '"><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="' . (int)$peer['id'] . '"><input type="hidden" name="role_filter" value="' . catalog_h($filter) . '"><button>Remove</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    $addRole = $filter !== 'all' ? $filter : ($siteRole === 'parent' ? 'child' : 'parent');
    $canAdd = peers_role_allowed($siteRole, $addRole);
    echo '<div class="card"><h2>Add recovery connection</h2>';
    echo '<p class="muted">Normal pairing should use Join a Parent or Child Join Requests. Manual creation is a recovery option.</p>';
    if (!$canAdd) {
        echo '<p>Manual connection creation is disabled for the current role and selected connection type.</p></div>';
    } else {
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peers')) . '"><input type="hidden" name="action" value="add"><input type="hidden" name="role_filter" value="' . catalog_h($filter) . '">';
        if ($filter === 'all') {
            echo '<p><label>Connection role<br><select name="peer_role"><option value="' . catalog_h($addRole) . '">' . catalog_h($addRole) . '</option></select></label></p>';
        } else {
            echo '<input type="hidden" name="peer_role" value="' . catalog_h($addRole) . '"><p>Adding a <strong>' . catalog_h($addRole) . '</strong> connection.</p>';
        }
        echo '<p><label>Site name<br><input name="site_name" required style="min-width:420px"></label></p>';
        echo '<p><label>Site URL<br><input name="site_url" required style="min-width:640px" placeholder="https://example.com/catalog"></label></p>';
        echo '<p><label>Peer site ID<br><input name="peer_site_id" required style="min-width:420px"></label></p>';
        echo '<p><label>Peer fingerprint<br><input name="peer_fingerprint" required style="min-width:420px"></label></p>';
        echo '<p><label>Shared secret<br><input name="shared_secret" style="min-width:420px" placeholder="leave blank to generate one"></label></p>';
        echo '<p><button>Add recovery connection</button></p></form></div>';
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation connections error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
