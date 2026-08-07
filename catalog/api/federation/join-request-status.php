<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the federation HTTP endpoint for join request status.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; reusable work should be delegated to shared application/services rather than duplicated
 *       here.
 * Audit: Active API surface unless its callers/tests prove otherwise; preserve request/response compatibility when
 *        consolidating.
 */
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
    $siteId = strtolower(trim((string)($payload['site_id'] ?? '')));
    $requestToken = trim((string)($payload['request_token'] ?? ''));
    if ($requestId <= 0 || $siteId === '' || $requestToken === '') {
        fed_json_response(['ok' => false, 'error' => 'request_id, site_id, and request_token are required.'], 400);
    }

    $req = catalog_one($db, 'SELECT * FROM ue_federation_join_requests WHERE id=? AND site_id=? LIMIT 1', [$requestId, $siteId]);
    if (!$req) {
        fed_json_response(['ok' => false, 'error' => 'Join request not found.'], 404);
    }

    $requestTokenHash = hash('sha256', $requestToken);
    if (empty($req['request_token_hash']) || !hash_equals((string)$req['request_token_hash'], $requestTokenHash)) {
        fed_json_response(['ok' => false, 'error' => 'Bad request token.'], 403);
    }

    $status = (string)$req['status'];

    // Older approved requests used a second manually copied token. Convert those
    // requests to the automatic protocol when the originating child proves it
    // still holds the original request token.
    if ($status === 'approved' && !hash_equals((string)($req['claim_token_hash'] ?? ''), $requestTokenHash)) {
        $ttl = max(600, (int)(fed_setting($db, 'join_claim_token_ttl_seconds', '86400') ?: 86400));
        $db->prepare(
            'UPDATE ue_federation_join_requests
             SET claim_token_hash=request_token_hash,
                 claim_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND)
             WHERE id=? AND status="approved"'
        )->execute([$ttl, $requestId]);
        $req['claim_token_hash'] = $requestTokenHash;
        $req['claim_expires_at'] = date('Y-m-d H:i:s', time() + $ttl);
    }

    $response = [
        'ok' => true,
        'request_id' => (int)$req['id'],
        'status' => $status,
        'message' => 'Waiting for parent admin approval.',
    ];

    if ($status === 'denied') {
        $response['message'] = 'Join request denied by parent admin.';
        $response['admin_notes'] = (string)($req['admin_notes'] ?? '');
        fed_json_response($response);
    }

    if ($status === 'approved' || $status === 'claimed') {
        if ($status === 'approved' && !empty($req['claim_expires_at']) && strtotime((string)$req['claim_expires_at']) < time()) {
            $db->prepare('UPDATE ue_federation_join_requests SET status="expired", claim_token_hash=NULL WHERE id=?')->execute([(int)$req['id']]);
            fed_json_response([
                'ok' => true,
                'request_id' => (int)$req['id'],
                'status' => 'expired',
                'message' => 'Join approval expired. Submit a new request.',
            ]);
        }

        $response['message'] = $status === 'claimed'
            ? 'Parent pairing is approved. The child may safely retry automatic pairing if needed.'
            : 'Approved. The child will complete pairing automatically.';
        $response['admin_notes'] = (string)($req['admin_notes'] ?? '');
        $response['claim_ready'] = true;
        $response['claim_endpoint'] = rtrim((string)fed_setting($db, 'site_url', ''), '/') . '/api/federation/join-claim.php';
        fed_json_response($response);
    }

    if ($status === 'expired') {
        $response['message'] = 'Join approval expired. Submit a new request.';
    }

    fed_json_response($response);
} catch (Throwable $e) {
    error_log('[UnrealDB federation join status] ' . get_class($e) . ': ' . $e->getMessage());
    fed_json_response(['ok' => false, 'error' => 'Join request status failed.'], 500);
}
