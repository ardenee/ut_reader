<?php
declare(strict_types=1);

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
    $payload = str_contains($contentType, 'application/json')
        ? fed_decode_json_object(fed_read_request_body(16384))
        : $_POST;

    $requestId = (int)($payload['request_id'] ?? 0);
    $token = trim((string)($payload['token'] ?? ''));
    if ($token === '') {
        fed_json_response(['ok' => false, 'error' => 'Missing automatic pairing token.'], 400);
    }

    $hash = hash('sha256', $token);
    if ($requestId > 0) {
        $req = catalog_one(
            $db,
            'SELECT * FROM ue_federation_join_requests
             WHERE id=? AND (claim_token_hash=? OR request_token_hash=?)
             LIMIT 1',
            [$requestId, $hash, $hash]
        );
    } else {
        $req = catalog_one(
            $db,
            'SELECT * FROM ue_federation_join_requests
             WHERE claim_token_hash=? OR request_token_hash=?
             ORDER BY id DESC LIMIT 1',
            [$hash, $hash]
        );
    }

    if (!$req) {
        fed_json_response(['ok' => false, 'error' => 'Invalid automatic pairing token.'], 404);
    }

    $status = (string)$req['status'];
    if (!in_array($status, ['approved', 'claimed'], true)) {
        fed_json_response(['ok' => false, 'error' => 'Join request is not pairable from status: ' . $status], 409);
    }

    if ($status === 'approved' && !empty($req['claim_expires_at']) && strtotime((string)$req['claim_expires_at']) < time()) {
        $db->prepare('UPDATE ue_federation_join_requests SET status="expired", claim_token_hash=NULL WHERE id=?')->execute([(int)$req['id']]);
        fed_json_response(['ok' => false, 'error' => 'Automatic pairing approval expired.'], 410);
    }

    $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND is_active=1 LIMIT 1', [(int)($req['created_peer_id'] ?? 0)]);
    if (!$peer) {
        fed_json_response(['ok' => false, 'error' => 'Approved child peer is unavailable. Parent admin must approve a new request.'], 500);
    }

    $sharedSecret = fed_peer_secret($db, $peer);
    if ($sharedSecret === '') {
        fed_json_response(['ok' => false, 'error' => 'Pairing secret is unavailable. Parent admin must approve a new request.'], 500);
    }

    if ($status === 'approved') {
        $claim = $db->prepare(
            'UPDATE ue_federation_join_requests
             SET status="claimed", claimed_at=NOW()
             WHERE id=? AND status="approved"'
        );
        $claim->execute([(int)$req['id']]);
        if ($claim->rowCount() !== 1) {
            fed_json_response(['ok' => false, 'error' => 'Automatic pairing state changed; retry the status check.'], 409);
        }
        $status = 'claimed';
    }

    $identity = fed_ensure_identity($db);
    fed_log($db, (int)$peer['id'], null, 'INFO', 'JOIN_PAIRED_AUTOMATICALLY', 'Join request #' . (int)$req['id'] . ' paired automatically by child.');

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
            'status' => $status,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[UnrealDB federation automatic join] ' . get_class($e) . ': ' . $e->getMessage());
    fed_json_response(['ok' => false, 'error' => 'Automatic parent pairing failed.'], 500);
}
