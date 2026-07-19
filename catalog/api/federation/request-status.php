<?php
declare(strict_types=1);


require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $body = file_get_contents('php://input') ?: '';
    $peer = fed_require_signed_peer($db, $body);

    if ((string)$peer['peer_role'] !== 'child') {
        fed_json_response(['ok' => false, 'error' => 'Only a paired child may poll request status.'], 403);
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $requestId = (int)($payload['request_id'] ?? 0);
    if ($requestId > 0) {
        $request = catalog_one($db, 'SELECT * FROM ue_federation_requests WHERE id=? AND peer_id=? AND direction="child_to_parent"', [$requestId, (int)$peer['id']]);
    } else {
        $request = catalog_one($db, 'SELECT * FROM ue_federation_requests WHERE peer_id=? AND direction="child_to_parent" ORDER BY created_at DESC LIMIT 1', [(int)$peer['id']]);
    }

    if (!$request) {
        fed_json_response(['ok' => true, 'request' => null, 'items' => []]);
    }

    $items = catalog_all($db, 'SELECT i.*, f.package_name local_package, f.original_name local_file, f.file_size, f.md5, f.sha1, f.package_guid FROM ue_federation_request_items i LEFT JOIN ue_files f ON f.id=i.local_file_id WHERE i.request_id=? ORDER BY i.id', [(int)$request['id']]);

    fed_json_response([
        'ok' => true,
        'request' => [
            'id' => (int)$request['id'],
            'status' => (string)$request['status'],
            'request_hash' => (string)$request['request_hash'],
            'title' => (string)$request['title'],
            'submitted_at' => (string)$request['submitted_at'],
            'approved_at' => (string)$request['approved_at'],
        ],
        'items' => array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'status' => (string)$row['status'],
                'required_package' => (string)$row['required_package'],
                'required_object_path' => (string)$row['required_object_path'],
                'local_file_id' => $row['local_file_id'] !== null ? (int)$row['local_file_id'] : null,
                'package_name' => (string)($row['local_package'] ?? ''),
                'original_name' => (string)($row['local_file'] ?? ''),
                'file_size' => (int)($row['file_size'] ?? 0),
                'md5' => (string)($row['md5'] ?? ''),
                'sha1' => (string)($row['sha1'] ?? ''),
                'package_guid' => (string)($row['package_guid'] ?? ''),
                'status_message' => (string)($row['status_message'] ?? ''),
            ];
        }, $items),
    ]);
} catch (Throwable $e) {
    fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
