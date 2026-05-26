<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($token === '') {
        fed_json_response(['ok' => false, 'error' => 'Missing claim token'], 400);
    }

    $hash = hash('sha256', $token);
    $req = catalog_one($db, 'SELECT * FROM ue_federation_join_requests WHERE claim_token_hash=? LIMIT 1', [$hash]);
    if (!$req) {
        fed_json_response(['ok' => false, 'error' => 'Invalid claim token'], 404);
    }
    if ((string)$req['status'] !== 'approved') {
        fed_json_response(['ok' => false, 'error' => 'Join request is not claimable from status: ' . (string)$req['status']], 409);
    }
    if (!empty($req['claim_expires_at']) && strtotime((string)$req['claim_expires_at']) < time()) {
        $db->prepare('UPDATE ue_federation_join_requests SET status="expired" WHERE id=?')->execute([(int)$req['id']]);
        fed_json_response(['ok' => false, 'error' => 'Claim token expired'], 410);
    }

    $adminNotes = (string)($req['admin_notes'] ?? '');
    if (!preg_match('/PAIRING_SECRET:([A-Za-z0-9+\/=_-]+)/', $adminNotes, $m)) {
        fed_json_response(['ok' => false, 'error' => 'Pairing payload missing. Parent admin must recreate this request.'], 500);
    }
    $payload = json_decode(base64_decode($m[1], true) ?: '', true);
    if (!is_array($payload) || empty($payload['shared_secret'])) {
        fed_json_response(['ok' => false, 'error' => 'Pairing payload invalid.'], 500);
    }

    $identity = fed_ensure_identity($db);
    $db->prepare('UPDATE ue_federation_join_requests SET status="claimed", claimed_at=NOW(), claim_token_hash=NULL WHERE id=?')->execute([(int)$req['id']]);
    fed_log($db, (int)($req['created_peer_id'] ?? 0), null, 'INFO', 'JOIN_CLAIMED', 'Join request #' . (int)$req['id'] . ' claimed by child.');

    fed_json_response([
        'ok' => true,
        'parent' => [
            'site_name' => (string)$identity['site_name'],
            'site_url' => (string)$identity['site_url'],
            'site_id' => (string)$identity['site_id'],
            'site_fingerprint' => (string)$identity['site_fingerprint'],
            'peer_role_for_child' => 'parent',
            'shared_secret' => (string)$payload['shared_secret'],
        ],
        'request' => [
            'id' => (int)$req['id'],
            'status' => 'claimed',
        ],
    ]);
} catch (Throwable $e) {
    fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
