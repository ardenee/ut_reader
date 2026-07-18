<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        header('Allow: POST');
        fed_json_response(['ok' => false, 'error' => 'Join claims require POST.'], 405);
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $payload = fed_decode_json_object(fed_read_request_body(16384));
    } else {
        $payload = $_POST;
    }
    $token = trim((string)($payload['token'] ?? ''));
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
        $db->prepare('UPDATE ue_federation_join_requests SET status="expired", claim_token_hash=NULL WHERE id=? AND claim_token_hash=?')->execute([(int)$req['id'], $hash]);
        fed_json_response(['ok' => false, 'error' => 'Claim token expired'], 410);
    }

    $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND is_active=1 LIMIT 1', [(int)($req['created_peer_id'] ?? 0)]);
    if (!$peer) {
        fed_json_response(['ok' => false, 'error' => 'Pairing peer is unavailable. Parent admin must recreate this request.'], 500);
    }
    $sharedSecret = fed_peer_secret($db, $peer);
    if ($sharedSecret === '') {
        fed_json_response(['ok' => false, 'error' => 'Pairing secret is unavailable. Parent admin must recreate this request.'], 500);
    }

    $claim = $db->prepare('UPDATE ue_federation_join_requests SET status="claimed", claimed_at=NOW(), claim_token_hash=NULL WHERE id=? AND status="approved" AND claim_token_hash=?');
    $claim->execute([(int)$req['id'], $hash]);
    if ($claim->rowCount() !== 1) {
        fed_json_response(['ok' => false, 'error' => 'Join request was already claimed.'], 409);
    }

    $identity = fed_ensure_identity($db);
    fed_log($db, (int)$peer['id'], null, 'INFO', 'JOIN_CLAIMED', 'Join request #' . (int)$req['id'] . ' claimed by child.');

    fed_json_response([
        'ok' => true,
        'parent' => [
            'site_name' => (string)$identity['site_name'],
            'site_url' => (string)$identity['site_url'],
            'site_id' => (string)$identity['site_id'],
            'site_fingerprint' => (string)$identity['site_fingerprint'],
            'peer_role_for_child' => 'parent',
            'shared_secret' => $sharedSecret,
        ],
        'request' => [
            'id' => (int)$req['id'],
            'status' => 'claimed',
        ],
    ]);
} catch (Throwable $e) {
    error_log('[UnrealDB federation join claim] ' . get_class($e) . ': ' . $e->getMessage());
    fed_json_response(['ok' => false, 'error' => 'Join claim failed.'], 500);
}
