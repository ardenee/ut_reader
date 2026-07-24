<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';
require_once __DIR__ . '/../../lib/BaseGameProtection.php';
require_once __DIR__ . '/../../lib/FederationBaseGamePolicy.php';
require_once __DIR__ . '/../../lib/FederationPackageAvailability.php';
require_once __DIR__ . '/../../lib/FederationRequestLifecycle.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);
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
        $request = catalog_one($db, 'SELECT * FROM ue_federation_requests WHERE peer_id=? AND direction="child_to_parent" ORDER BY created_at DESC, id DESC LIMIT 1', [(int)$peer['id']]);
    }

    if (!$request) {
        fed_json_response(['ok' => true, 'policy' => federation_parent_base_game_policy($db), 'request' => null, 'items' => []]);
    }

    $refresh = federation_refresh_request_matches($db, (int)$request['id']);
    $request = catalog_one($db, 'SELECT * FROM ue_federation_requests WHERE id=?', [(int)$request['id']]) ?: $request;

    $rows = catalog_all(
        $db,
        'SELECT i.*, f.package_name local_package, f.original_name local_file,
                f.file_size, f.md5, f.sha1, f.package_guid, f.game_id,
                CASE WHEN bg.id IS NOT NULL THEN 1 ELSE 0 END is_base_game
         FROM ue_federation_request_items i
         LEFT JOIN ue_files f ON f.id=i.local_file_id
         LEFT JOIN ue_base_game_files bg ON bg.game_id=f.game_id AND bg.package_guid=f.package_guid
         WHERE i.request_id=?
         ORDER BY i.updated_at DESC, i.id DESC',
        [(int)$request['id']]
    );

    $items = [];
    $ignoreBaseGame = federation_ignore_base_game_files($db);
    foreach ($rows as $row) {
        $isBaseGame = !empty($row['is_base_game']);
        if (!$isBaseGame && str_contains(strtolower((string)($row['status_message'] ?? '')), 'base-game')) {
            $isBaseGame = true;
        }
        if (!$isBaseGame && empty($row['local_file_id'])) {
            $isBaseGame = federation_base_game_package_match($db, (string)$row['required_package']) !== null;
        }
        if ($ignoreBaseGame && $isBaseGame) {
            continue;
        }

        $items[] = [
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
            'is_base_game' => $isBaseGame,
            'dependency_exception' => false,
            'status_message' => (string)($row['status_message'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    $visibleRequest = $items !== [] ? [
        'id' => (int)$request['id'],
        'status' => (string)$request['status'],
        'request_hash' => (string)$request['request_hash'],
        'title' => (string)$request['title'],
        'submitted_at' => (string)$request['submitted_at'],
        'approved_at' => (string)$request['approved_at'],
        'updated_at' => (string)$request['updated_at'],
    ] : null;

    fed_json_response([
        'ok' => true,
        'policy' => federation_parent_base_game_policy($db),
        'request' => $visibleRequest,
        'refresh' => $refresh,
        'items' => $items,
    ]);
} catch (Throwable $e) {
    fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
