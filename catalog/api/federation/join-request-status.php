<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        header('Allow: POST');
        fed_json_response(['ok' => false, 'error' => 'Join request status requires POST.'], 405);
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $payload = fed_decode_json_object(fed_read_request_body(32768));

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
        $db->prepare('UPDATE ue_federation_join_requests SET status="expired", claim_token_hash=NULL WHERE id=?')->execute([(int)$req['id']]);
        fed_json_response(['ok' => true, 'request_id' => (int)$req['id'], 'status' => 'expired', 'message' => 'Join approval expired. Submit a new request.']);
    }

    $response['message'] = 'Approved. Obtain the one-time POST claim endpoint and token from the parent administrator through a trusted channel.';
    $response['claim_required'] = true;
    fed_json_response($response);
} catch (Throwable $e) {
    error_log('[UnrealDB federation join status] ' . get_class($e) . ': ' . $e->getMessage());
    fed_json_response(['ok' => false, 'error' => 'Join request status failed.'], 500);
}
