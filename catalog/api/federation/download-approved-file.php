<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';
require_once __DIR__ . '/../../lib/BaseGameProtection.php';
require_once __DIR__ . '/../../lib/FederationBaseGamePolicy.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);
    $body = file_get_contents('php://input') ?: '';
    $peer = fed_require_signed_peer($db, $body);

    if ((string)$peer['peer_role'] !== 'child') {
        fed_json_response(['ok' => false, 'error' => 'Only a paired child may download approved files.'], 403);
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $itemId = (int)($payload['request_item_id'] ?? 0);
    if ($itemId <= 0) {
        fed_json_response(['ok' => false, 'error' => 'request_item_id is required'], 400);
    }

    $item = catalog_one(
        $db,
        'SELECT i.*, r.peer_id, f.*
         FROM ue_federation_request_items i
         JOIN ue_federation_requests r ON r.id=i.request_id
         JOIN ue_files f ON f.id=i.local_file_id
         WHERE i.id=? AND r.peer_id=? AND r.direction="child_to_parent"
           AND i.status IN ("approved","queued","downloading")',
        [$itemId, (int)$peer['id']]
    );
    if (!$item) {
        fed_json_response(['ok' => false, 'error' => 'Approved dependency request item not found'], 404);
    }

    $isBaseGame = base_game_file_is_protected($db, $item);
    if ($isBaseGame && federation_ignore_base_game_files($db)) {
        fed_json_response([
            'ok' => false,
            'error' => 'The parent policy excludes base-game files from all federation requests and transfers.',
        ], 403);
    }

    $root = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    $path = realpath(__DIR__ . '/../../' . (string)$item['relative_path']);
    if (!$root || !$path || !str_starts_with(str_replace('\\', '/', $path) . '/', rtrim(str_replace('\\', '/', $root), '/') . '/') || !is_file($path) || is_link($path)) {
        fed_json_response(['ok' => false, 'error' => 'Stored file missing'], 404);
    }

    $message = $isBaseGame
        ? 'Child started approved base-game download while base-game federation participation is enabled.'
        : 'Child started approved dependency download.';
    $db->prepare('UPDATE ue_federation_request_items SET status="downloading", status_message=? WHERE id=?')->execute([$message, $itemId]);
    fed_log(
        $db,
        (int)$peer['id'],
        null,
        'INFO',
        $isBaseGame ? 'CHILD_APPROVED_BASE_GAME_DOWNLOAD' : 'CHILD_APPROVED_DOWNLOAD',
        'Serving approved request item ' . $itemId . '.'
    );

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . addslashes((string)$item['original_name']) . '"');
    header('X-UE-Request-Item-Id: ' . $itemId);
    header('X-UE-File-Id: ' . (int)$item['local_file_id']);
    header('X-UE-Package-Guid: ' . (string)$item['package_guid']);
    header('X-UE-MD5: ' . (string)$item['md5']);
    header('X-UE-SHA1: ' . (string)$item['sha1']);
    header('X-UE-Base-Game: ' . ($isBaseGame ? '1' : '0'));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
} catch (Throwable $e) {
    if (!headers_sent()) {
        fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}
