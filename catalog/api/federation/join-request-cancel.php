<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the federation HTTP endpoint for join request cancel.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; reusable work should be delegated to shared application/services rather than duplicated
 *       here.
 * Audit: Active API surface unless its callers/tests prove otherwise; preserve request/response compatibility when
 *        consolidating.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        fed_json_response(['ok' => false, 'error' => 'Only POST is supported.'], 405);
    }

    $db = catalog_db(catalog_config());
    $payload = fed_decode_json_object(fed_read_request_body(262144));
    $requestId = (int)($payload['request_id'] ?? 0);
    $siteId = strtolower(trim((string)($payload['site_id'] ?? '')));
    $token = trim((string)($payload['request_token'] ?? ''));
    if ($requestId <= 0 || $siteId === '' || $token === '') {
        fed_json_response(['ok' => false, 'error' => 'request_id, site_id, and request_token are required.'], 400);
    }

    $request = catalog_one(
        $db,
        'SELECT * FROM ue_federation_join_requests WHERE id=? AND site_id=? AND status IN ("pending","approved")',
        [$requestId, $siteId]
    );
    if (!$request || empty($request['request_token_hash']) || !hash_equals((string)$request['request_token_hash'], hash('sha256', $token))) {
        fed_json_response(['ok' => false, 'error' => 'Active join request not found or token invalid.'], 404);
    }

    $db->beginTransaction();
    try {
        $peerId = (int)($request['created_peer_id'] ?? 0);
        if ($peerId > 0 && empty($request['claimed_at'])) {
            $db->prepare('DELETE FROM ue_federation_peer_files WHERE peer_id=?')->execute([$peerId]);
            $db->prepare('DELETE FROM ue_federation_peers WHERE id=?')->execute([$peerId]);
        }
        $db->prepare(
            'UPDATE ue_federation_join_requests
             SET status="expired", admin_notes="Cancelled by the requesting child before pairing completed.",
                 claim_token_hash=NULL, claim_expires_at=NULL, created_peer_id=NULL
             WHERE id=?'
        )->execute([$requestId]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    fed_log($db, null, null, 'INFO', 'JOIN_REQUEST_CANCELLED_BY_CHILD', 'Join request #' . $requestId . ' cancelled before pairing completed.');
    fed_json_response(['ok' => true, 'status' => 'expired', 'message' => 'Join request cancelled.']);
} catch (Throwable $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], 500);
}
