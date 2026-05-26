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
    $peer = fed_require_signed_peer($db, $body);

    if ((string)$peer['peer_role'] !== 'child') {
        fed_json_response(['ok' => false, 'error' => 'Only a paired child may cancel its request.'], 403);
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $requestId = (int)($payload['request_id'] ?? 0);
    if ($requestId <= 0) {
        $request = catalog_one($db, 'SELECT * FROM ue_federation_requests WHERE peer_id=? AND direction="child_to_parent" ORDER BY created_at DESC LIMIT 1', [(int)$peer['id']]);
    } else {
        $request = catalog_one($db, 'SELECT * FROM ue_federation_requests WHERE id=? AND peer_id=? AND direction="child_to_parent"', [$requestId, (int)$peer['id']]);
    }

    if (!$request) {
        fed_json_response(['ok' => false, 'error' => 'Request not found for this child.'], 404);
    }

    if (in_array((string)$request['status'], ['completed','denied','updated','cancelled'], true)) {
        fed_json_response(['ok' => false, 'error' => 'Request cannot be cancelled from status: ' . (string)$request['status']], 409);
    }

    $reason = trim((string)($payload['reason'] ?? 'Cancelled by child site.'));
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE ue_federation_requests SET status="cancelled", notes=CONCAT(COALESCE(notes,""), ?) WHERE id=?')->execute(["\n" . date('Y-m-d H:i:s') . ' - ' . $reason, (int)$request['id']]);
        $db->prepare('UPDATE ue_federation_request_items SET status="failed", status_message=? WHERE request_id=? AND status IN ("requested","approved","queued","downloading","downloaded")')->execute(['Request cancelled by child site.', (int)$request['id']]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    fed_log($db, (int)$peer['id'], null, 'INFO', 'REQUEST_CANCELLED', 'Request ' . (int)$request['id'] . ' cancelled by child.');
    fed_json_response(['ok' => true, 'request_id' => (int)$request['id'], 'status' => 'cancelled']);
} catch (Throwable $e) {
    fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
