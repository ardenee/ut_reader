<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $siteRole = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        if ($siteRole !== 'parent') {
            throw new RuntimeException('Incoming child join requests are available only while this server is in Parent mode.');
        }
        catalog_check_csrf('fed_join_requests');
        $id = (int)($_POST['id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        $req = catalog_one($db, 'SELECT * FROM ue_federation_join_requests WHERE id=?', [$id]);
        if (!$req) {
            throw new RuntimeException('Join request not found.');
        }

        if ($action === 'deny') {
            $notes = trim((string)($_POST['admin_notes'] ?? 'Denied by parent admin.'));
            $db->prepare(
                'UPDATE ue_federation_join_requests
                 SET status="denied", admin_notes=?, claim_token_hash=NULL, claim_expires_at=NULL
                 WHERE id=?'
            )->execute([$notes, $id]);
            fed_log($db, null, null, 'INFO', 'JOIN_REQUEST_DENIED', 'Incoming child join request #' . $id . ' denied.');
            $_SESSION['fed_join_review_flash'] = 'Child join request #' . $id . ' denied.';
        } elseif ($action === 'approve') {
            if ((string)$req['status'] !== 'pending') {
                throw new RuntimeException('Only pending join requests can be approved.');
            }

            $existingPeer = catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE peer_site_id=? LIMIT 1', [(string)$req['site_id']]);
            if ($existingPeer) {
                throw new RuntimeException('A child connection already exists for this site ID.');
            }

            $sharedSecret = fed_random_secret();
            $secretFields = fed_prepare_peer_secret($sharedSecret);
            $ttl = max(600, (int)(fed_setting($db, 'join_claim_token_ttl_seconds', '86400') ?: 86400));
            $permissions = [
                'parent_is_master' => true,
                'parent_inventory_read_without_child_approval' => true,
                'parent_pull_without_child_approval' => true,
                'child_download_requires_parent_approval' => true,
                'child_download_scope' => 'missing_dependencies_only',
                'created_by_join_request' => true,
            ];

            $db->beginTransaction();
            try {
                $stmt = $db->prepare(
                    'INSERT INTO ue_federation_peers(
                        peer_role, site_name, site_url, peer_site_id, peer_fingerprint,
                        shared_secret_hash, shared_secret_plain, permissions_json, is_active
                     ) VALUES("child",?,?,?,?,?,?,?,1)'
                );
                $stmt->execute([
                    (string)$req['site_name'],
                    (string)$req['site_url'],
                    (string)$req['site_id'],
                    (string)$req['site_fingerprint'],
                    $secretFields['hash'],
                    $secretFields['stored'],
                    json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
                $peerId = (int)$db->lastInsertId();

                $adminNotes = trim((string)($_POST['admin_notes'] ?? 'Approved by parent admin.'));
                $db->prepare(
                    'UPDATE ue_federation_join_requests
                     SET status="approved", admin_notes=?,
                         claim_token_hash=request_token_hash,
                         claim_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND),
                         approved_at=NOW(), approved_by=?, created_peer_id=?
                     WHERE id=?'
                )->execute([
                    $adminNotes,
                    $ttl,
                    $_SESSION['user']['id'] ?? null,
                    $peerId,
                    $id,
                ]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            fed_log($db, $peerId, null, 'INFO', 'JOIN_REQUEST_APPROVED', 'Incoming child join request #' . $id . ' approved for automatic pairing.');
            $_SESSION['fed_join_review_flash'] = 'Child join request #' . $id . ' approved. The child will complete pairing automatically.';
        } else {
            throw new RuntimeException('Unknown join request action.');
        }

        header('Location: join-requests.php?id=' . $id);
        exit;
    }

    if (!catalog_require_admin_page('Incoming Child Join Requests')) {
        exit;
    }

    catalog_head('Incoming Child Join Requests');
    catalog_flash($_SESSION['fed_join_review_flash'] ?? null);
    unset($_SESSION['fed_join_review_flash']);

    catalog_page_header(
        'Incoming Child Join Requests',
        'Parent-side page for reviewing sites that want to join this server as children. Outgoing requests to join this server’s parent are shown on Join a Parent instead.',
        catalog_federation_links() + ['Children' => 'peers.php?role=child', 'Join a Parent' => 'join-main-parent.php', 'Logs' => 'logs.php']
    );

    echo '<div class="card"><h2>Server mode</h2><p>This server is running in <strong>' . catalog_h(ucfirst($siteRole)) . '</strong> mode.</p></div>';
    if ($siteRole !== 'parent') {
        echo '<div class="card"><h2>Incoming child joins disabled</h2>';
        if ($siteRole === 'child') {
            echo '<p>A Child server cannot have child sites. The request sent to your current parent is an outgoing join request and is tracked on the Join a Parent page.</p>';
            echo '<p><a class="button" href="join-main-parent.php">Open Join a Parent</a> <a class="button" href="peers.php?role=parent">View Parent</a></p>';
        } else {
            echo '<p>Change this server to Parent mode before accepting child join requests.</p><p><a class="button" href="settings.php">Federation Settings</a></p>';
        }
        echo '</div>';
        catalog_foot();
        exit;
    }

    $requests = catalog_all(
        $db,
        'SELECT r.*, p.id peer_id
         FROM ue_federation_join_requests r
         LEFT JOIN ue_federation_peers p ON p.id=r.created_peer_id
         ORDER BY FIELD(r.status,"pending","approved","claimed","denied","expired"), r.created_at DESC, r.id DESC
         LIMIT 200'
    );

    echo '<div class="card"><h2>Incoming requests</h2>';
    if (!$requests) {
        echo '<p class="muted">No child sites have requested to join this parent.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Status</th><th>Child site</th><th>URL</th><th>Fingerprint</th><th>Contact</th><th>Received</th><th>Open</th></tr>';
        foreach ($requests as $row) {
            echo '<tr><td class="mono">' . (int)$row['id'] . '</td><td>' . catalog_h($row['status']) . '</td><td>' . catalog_h($row['site_name']) . '</td><td class="mono path">' . catalog_h($row['site_url']) . '</td><td class="mono small">' . catalog_h($row['site_fingerprint']) . '</td><td>' . catalog_h(trim(($row['contact_name'] ?? '') . ' ' . ($row['contact_email'] ?? ''))) . '</td><td>' . catalog_h($row['created_at']) . '</td><td><a href="join-requests.php?id=' . (int)$row['id'] . '">open</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    $openId = (int)($_GET['id'] ?? 0);
    if ($openId > 0) {
        $req = catalog_one($db, 'SELECT * FROM ue_federation_join_requests WHERE id=?', [$openId]);
        if (!$req) {
            throw new RuntimeException('Join request not found.');
        }

        echo '<div class="card"><h2>Incoming request #' . (int)$req['id'] . '</h2><table>';
        foreach (['status','site_name','site_url','site_id','site_fingerprint','contact_name','contact_email','notes','admin_notes','claim_expires_at','claimed_at','approved_at','created_peer_id','created_at'] as $key) {
            echo '<tr><th>' . catalog_h($key) . '</th><td class="mono path">' . catalog_h($req[$key] ?? '') . '</td></tr>';
        }
        echo '</table></div>';

        if ((string)$req['status'] === 'pending') {
            echo '<div class="card"><h2>Review child request</h2><form method="post">';
            echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_join_requests')) . '">';
            echo '<input type="hidden" name="id" value="' . (int)$req['id'] . '">';
            echo '<p><label>Admin notes<br><textarea name="admin_notes" rows="4" style="width:100%"></textarea></label></p>';
            echo '<p><button name="action" value="approve">Approve child and pair automatically</button> <button class="danger" name="action" value="deny">Deny</button></p>';
            echo '</form></div>';
        } elseif ((string)$req['status'] === 'approved') {
            echo '<div class="card"><h2>Approved</h2><p class="muted">The child will complete pairing automatically when it polls this decision.</p></div>';
        } elseif ((string)$req['status'] === 'claimed') {
            echo '<div class="card"><h2>Connected</h2><p class="muted">The child completed automatic pairing and now appears under Children.</p></div>';
        }
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Join requests error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
