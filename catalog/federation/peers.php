<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_peers');
        $action = (string)($_POST['action'] ?? 'add');

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=?', [$id]);
            if (!$peer) {
                throw new RuntimeException('Peer not found.');
            }
            $newState = (int)$peer['is_active'] === 1 ? 0 : 1;
            $db->prepare('UPDATE ue_federation_peers SET is_active=? WHERE id=?')->execute([$newState, $id]);
            fed_log($db, $id, null, 'INFO', 'PEER_TOGGLE', 'Peer active state set to ' . $newState);
            header('Location: peers.php');
            exit;
        }

        $peerRole = strtolower(trim((string)($_POST['peer_role'] ?? 'child')));
        $siteName = trim((string)($_POST['site_name'] ?? ''));
        $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
        $peerSiteId = strtolower(trim((string)($_POST['peer_site_id'] ?? '')));
        $peerFingerprint = strtoupper(trim((string)($_POST['peer_fingerprint'] ?? '')));
        $sharedSecret = trim((string)($_POST['shared_secret'] ?? ''));

        if (!in_array($peerRole, ['parent', 'child'], true)) {
            throw new RuntimeException('Invalid peer role.');
        }
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
        fed_log($db, $peerId, null, 'INFO', 'PEER_ADD', 'Peer added: ' . $siteName . ' as ' . $peerRole);

        $_SESSION['fed_peer_secret_once'] = $sharedSecret;
        $_SESSION['fed_peer_secret_peer'] = $siteName;
        header('Location: peers.php');
        exit;
    }

    if (!catalog_require_admin_page('Federation Peers')) {
        exit;
    }

    catalog_head('Federation Peers');
    catalog_page_header(
        'Federation Peers',
        'Approved joins create peers automatically. The parent is the master: it reads and pulls from children directly; child downloads require parent approval and are restricted to missing dependencies.',
        catalog_federation_links()
    );

    if (isset($_SESSION['fed_peer_secret_once'])) {
        echo '<div class="card"><h2>Manual shared secret for ' . catalog_h($_SESSION['fed_peer_secret_peer'] ?? 'peer') . '</h2><p class="muted">Manual peer creation is an administrator recovery option. Copy this secret to the matching peer only when automatic join approval cannot be used.</p><pre class="mono">' . catalog_h($_SESSION['fed_peer_secret_once']) . '</pre></div>';
        unset($_SESSION['fed_peer_secret_once'], $_SESSION['fed_peer_secret_peer']);
    }

    $peers = catalog_all($db, 'SELECT * FROM ue_federation_peers ORDER BY peer_role, site_name');
    echo '<div class="card"><h2>Configured peers</h2>';
    if (!$peers) {
        echo '<p class="muted">No peers configured yet.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Role</th><th>Name</th><th>URL</th><th>Site ID</th><th>Fingerprint</th><th>Secret</th><th>Policy</th><th>Active</th><th>Last seen</th><th>Action</th></tr>';
        foreach ($peers as $peer) {
            $storedSecret = (string)($peer['shared_secret_plain'] ?? '');
            $hasSecret = $storedSecret === '' ? 'missing' : (fed_secret_store()->isEncrypted($storedSecret) ? 'encrypted' : 'legacy plaintext');
            $policy = (string)$peer['peer_role'] === 'child'
                ? 'Parent may inventory/pull directly'
                : 'Dependency requests require approval';
            echo '<tr><td class="mono">' . (int)$peer['id'] . '</td><td>' . catalog_h($peer['peer_role']) . '</td><td>' . catalog_h($peer['site_name']) . '</td><td class="mono path">' . catalog_h($peer['site_url']) . '</td><td class="mono small">' . catalog_h($peer['peer_site_id']) . '</td><td class="mono small">' . catalog_h($peer['peer_fingerprint']) . '</td><td>' . catalog_h($hasSecret) . '</td><td>' . catalog_h($policy) . '</td><td>' . ((int)$peer['is_active'] ? 'yes' : 'no') . '</td><td>' . catalog_h($peer['last_seen_at']) . '</td><td><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peers')) . '"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . (int)$peer['id'] . '"><button>' . ((int)$peer['is_active'] ? 'Disable' : 'Enable') . '</button></form></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    echo '<div class="card"><h2>Manual peer recovery</h2><p class="muted">Normal child pairing is created automatically when the parent approves a join request.</p><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_peers')) . '"><input type="hidden" name="action" value="add">';
    echo '<p><label>Peer role<br><select name="peer_role"><option value="parent">parent</option><option value="child" selected>child</option></select></label></p>';
    echo '<p><label>Site name<br><input name="site_name" required style="min-width:420px"></label></p>';
    echo '<p><label>Site URL<br><input name="site_url" required style="min-width:640px" placeholder="https://example.com/catalog"></label></p>';
    echo '<p><label>Peer site ID<br><input name="peer_site_id" required style="min-width:420px"></label></p>';
    echo '<p><label>Peer fingerprint<br><input name="peer_fingerprint" required style="min-width:420px"></label></p>';
    echo '<p><label>Shared secret<br><input name="shared_secret" style="min-width:420px" placeholder="leave blank to generate one"></label></p>';
    echo '<p><button>Add recovery peer</button></p></form></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation peers error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
