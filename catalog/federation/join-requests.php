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
            fed_log($db, null, null, 'INFO', 'JOIN_REQUEST_DENIED', 'Join request #' . $id . ' denied.');
            $_SESSION['fed_join_review_flash'] = 'Join request #' . $id . ' denied.';
        } elseif ($action === 'approve') {
            if ((string)$req['status'] !== 'pending') {
                throw new RuntimeException('Only pending join requests can be approved.');
            }

            $existingPeer = catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE peer_site_id=? LIMIT 1', [(string)$req['site_id']]);
            if ($existingPeer) {
                throw new RuntimeException('A peer already exists for this site ID.');
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

            fed_log($db, $peerId, null, 'INFO', 'JOIN_REQUEST_APPROVED', 'Join request #' . $id . ' approved for automatic pairing.');
            $_SESSION['fed_join_review_flash'] = 'Join request #' . $id . ' approved. The child will complete pairing automatically on its next status check.';
        } else {
            throw new RuntimeException('Unknown join request action.');
        }

        header('Location: join-requests.php?id=' . $id);
        exit;
    }

    if (!catalog_require_admin_page('Federation Join Requests')) {
        exit;
    }

    catalog_head('Federation Join Requests');
    catalog_flash($_SESSION['fed_join_review_flash'] ?? null);
    unset($_SESSION['fed_join_review_flash']);

    catalog_page_header(
        'Federation Join Requests',
        'Approve child sites. Approval immediately authorizes automatic pairing; the child does not copy a token or complete a separate claim step.',
        catalog_federation_links() + ['Peers' => 'peers.php', 'Public Join Page' => 'join.php', 'Logs' => 'logs.php']
    );

    $requests = catalog_all(
        $db,
        'SELECT r.*, p.id peer_id
         FROM ue_federation_join_requests r
         LEFT JOIN ue_federation_peers p ON p.id=r.created_peer_id
         ORDER BY FIELD(r.status,"pending","approved","claimed","denied","expired"), r.created_at DESC
         LIMIT 200'
    );

    echo '<div class="card"><h2>Requests</h2>';
    if (!$requests) {
        echo '<p class="muted">No join requests yet.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Status</th><th>Site</th><th>URL</th><th>Fingerprint</th><th>Contact</th><th>Created</th><th>Open</th></tr>';
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

        echo '<div class="card"><h2>Request #' . (int)$req['id'] . '</h2><table>';
        foreach (['status','site_name','site_url','site_id','site_fingerprint','contact_name','contact_email','notes','admin_notes','claim_expires_at','claimed_at','approved_at','created_peer_id','created_at'] as $key) {
            echo '<tr><th>' . catalog_h($key) . '</th><td class="mono path">' . catalog_h($req[$key] ?? '') . '</td></tr>';
        }
        echo '</table></div>';

        if ((string)$req['status'] === 'pending') {
            echo '<div class="card"><h2>Review</h2><form method="post">';
            echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_join_requests')) . '">';
            echo '<input type="hidden" name="id" value="' . (int)$req['id'] . '">';
            echo '<p><label>Admin notes<br><textarea name="admin_notes" rows="4" style="width:100%"></textarea></label></p>';
            echo '<p><button name="action" value="approve">Approve child and pair automatically</button> <button class="danger" name="action" value="deny">Deny</button></p>';
            echo '</form></div>';
        } elseif ((string)$req['status'] === 'approved') {
            echo '<div class="card"><h2>Approved</h2><p class="muted">The child will complete pairing automatically when it polls this decision. No claim token needs to be copied.</p></div>';
        } elseif ((string)$req['status'] === 'claimed') {
            echo '<div class="card"><h2>Connected</h2><p class="muted">The child completed automatic pairing.</p></div>';
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
