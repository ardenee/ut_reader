<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the federation HTTP endpoint for request item status update.
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

function update_parent_request_status(PDO $db, int $requestId): void
{
    $counts = catalog_one($db, 'SELECT COUNT(*) total, SUM(status IN ("imported","skipped_already_have","denied","failed")) finished, SUM(status="imported") imported, SUM(status="failed") failed FROM ue_federation_request_items WHERE request_id=?', [$requestId]);
    if (!$counts || (int)$counts['total'] <= 0) {
        return;
    }
    if ((int)$counts['finished'] >= (int)$counts['total']) {
        $newStatus = (int)$counts['failed'] > 0 ? 'failed' : 'completed';
        $db->prepare('UPDATE ue_federation_requests SET status=? WHERE id=? AND status NOT IN ("cancelled","denied","updated")')->execute([$newStatus, $requestId]);
    } elseif ((int)$counts['imported'] > 0) {
        $db->prepare('UPDATE ue_federation_requests SET status="downloading" WHERE id=? AND status IN ("approved","part_approved","submitted")')->execute([$requestId]);
    }
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $body = file_get_contents('php://input') ?: '';
    $peer = fed_require_signed_peer($db, $body);

    if ((string)$peer['peer_role'] !== 'child') {
        fed_json_response(['ok' => false, 'error' => 'Only a paired child may update request item status.'], 403);
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $itemId = (int)($payload['request_item_id'] ?? 0);
    $status = (string)($payload['status'] ?? '');
    $message = trim((string)($payload['message'] ?? ''));
    $childLocalFileId = isset($payload['child_local_file_id']) ? (int)$payload['child_local_file_id'] : null;
    $childMd5 = strtolower(trim((string)($payload['md5'] ?? '')));
    $childSha1 = strtolower(trim((string)($payload['sha1'] ?? '')));

    $allowed = ['queued','downloading','downloaded','imported','failed','skipped_already_have'];
    if ($itemId <= 0 || !in_array($status, $allowed, true)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid request item status update.'], 400);
    }

    $item = catalog_one($db, 'SELECT i.*, r.peer_id FROM ue_federation_request_items i JOIN ue_federation_requests r ON r.id=i.request_id WHERE i.id=? AND r.peer_id=?', [$itemId, (int)$peer['id']]);
    if (!$item) {
        fed_json_response(['ok' => false, 'error' => 'Request item not found for this peer.'], 404);
    }

    $detail = $message;
    if ($childLocalFileId !== null) {
        $detail .= ($detail !== '' ? "\n" : '') . 'Child local file ID: ' . $childLocalFileId;
    }
    if ($childMd5 !== '') {
        $detail .= ($detail !== '' ? "\n" : '') . 'Child MD5: ' . $childMd5;
    }
    if ($childSha1 !== '') {
        $detail .= ($detail !== '' ? "\n" : '') . 'Child SHA1: ' . $childSha1;
    }

    $db->prepare('UPDATE ue_federation_request_items SET status=?, status_message=? WHERE id=?')->execute([$status, $detail, $itemId]);
    update_parent_request_status($db, (int)$item['request_id']);
    fed_log($db, (int)$peer['id'], null, $status === 'failed' ? 'ERROR' : 'INFO', 'REQUEST_ITEM_STATUS_UPDATE', 'Item ' . $itemId . ' -> ' . $status);

    fed_json_response(['ok' => true, 'item_id' => $itemId, 'status' => $status]);
} catch (Throwable $e) {
    fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
