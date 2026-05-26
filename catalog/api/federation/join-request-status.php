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
    $body = file_get_contents('php://input') ?: '';
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $requestId = (int)($payload['request_id'] ?? 0);
    $siteId = trim((string)($payload['site_id'] ?? ''));
    $requestToken = trim((string)($payload['request_token'] ?? ''));
    if ($requestId <= 0 || $siteId === '' || $requestToken === '') {
        fed_json_response(['ok' => false, 'error' => 'request_id, site_id, and request_token are required.'], 400);
    }

    $req = catalog_one($db, 'SELECT * FROM ue_federation_join_requests WHERE id=? AND site_id=? LIMIT 1', [$requestId, $siteId]);
    if (!$req) {
        fed_json_response(['ok' => false, 'error' => 'Join request not found.'], 404);
    }
    if (empty($req['request_token_hash']) || !hash_equals((string)$req['request_token_hash'], hash('sha256', $requestToken))) {
        fed_json_response(['ok' => false, 'error' => 'Bad request token.'], 403);
    }

    $response = [
        'ok' => true,
        'request_id' => (int)$req['id'],
        'status' => (string)$req['status'],
        'message' => 'Waiting for parent admin approval.',
    ];

    if ((string)$req['status'] === 'denied') {
        $response['message'] = 'Join request denied by parent admin.';
        $response['admin_notes'] = (string)($req['admin_notes'] ?? '');
        fed_json_response($response);
    }

    if ((string)$req['status'] === 'claimed') {
        $response['message'] = 'Join request has already been claimed.';
        fed_json_response($response);
    }

    if ((string)$req['status'] !== 'approved') {
        fed_json_response($response);
    }

    if (!empty($req['claim_expires_at']) && strtotime((string)$req['claim_expires_at']) < time()) {
        $db->prepare('UPDATE ue_federation_join_requests SET status="expired" WHERE id=?')->execute([(int)$req['id']]);
        fed_json_response(['ok' => true, 'request_id' => (int)$req['id'], 'status' => 'expired', 'message' => 'Join approval expired. Submit a new request.']);
    }

    $adminNotes = (string)($req['admin_notes'] ?? '');
    if (!preg_match('/PAIRING_SECRET:([A-Za-z0-9+\/=_-]+)/', $adminNotes, $m)) {
        fed_json_response(['ok' => false, 'error' => 'Pairing payload missing on parent.'], 500);
    }
    $pairPayload = json_decode(base64_decode($m[1], true) ?: '', true);
    if (!is_array($pairPayload) || empty($pairPayload['shared_secret'])) {
        fed_json_response(['ok' => false, 'error' => 'Pairing payload invalid on parent.'], 500);
    }

    $identity = fed_ensure_identity($db);
    $db->prepare('UPDATE ue_federation_join_requests SET status="claimed", claimed_at=NOW(), claim_token_hash=NULL WHERE id=?')->execute([(int)$req['id']]);
    fed_log($db, (int)($req['created_peer_id'] ?? 0), null, 'INFO', 'JOIN_AUTO_CLAIMED', 'Join request #' . (int)$req['id'] . ' auto-claimed by child polling.');

    fed_json_response([
        'ok' => true,
        'request_id' => (int)$req['id'],
        'status' => 'approved_claimed',
        'message' => 'Approved and claimed. Configure parent peer now.',
        'parent' => [
            'site_name' => (string)$identity['site_name'],
            'site_url' => (string)$identity['site_url'],
            'site_id' => (string)$identity['site_id'],
            'site_fingerprint' => (string)$identity['site_fingerprint'],
            'peer_role_for_child' => 'parent',
            'shared_secret' => (string)$pairPayload['shared_secret'],
        ],
    ]);
} catch (Throwable $e) {
    fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
