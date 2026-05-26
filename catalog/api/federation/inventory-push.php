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

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $files = $payload['files'] ?? [];
    if (!is_array($files)) {
        fed_json_response(['ok' => false, 'error' => 'Missing files array'], 400);
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO ue_federation_peer_files(peer_id,game_id,remote_game_name,remote_engine_key,remote_file_id,package_name,original_name,extension,file_size,md5,sha1,package_guid,is_compressed,compression_flags,import_count,export_count,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE game_id=VALUES(game_id), remote_game_name=VALUES(remote_game_name), remote_engine_key=VALUES(remote_engine_key), remote_file_id=VALUES(remote_file_id), package_name=VALUES(package_name), original_name=VALUES(original_name), extension=VALUES(extension), file_size=VALUES(file_size), sha1=VALUES(sha1), is_compressed=VALUES(is_compressed), compression_flags=VALUES(compression_flags), import_count=VALUES(import_count), export_count=VALUES(export_count), last_seen_at=NOW()');
        $count = 0;
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $packageName = trim((string)($file['package_name'] ?? ''));
            $originalName = trim((string)($file['original_name'] ?? ''));
            $guid = trim((string)($file['package_guid'] ?? ''));
            $md5 = strtolower(trim((string)($file['md5'] ?? '')));
            if ($packageName === '' || $originalName === '' || ($guid === '' && $md5 === '')) {
                continue;
            }
            $stmt->execute([
                (int)$peer['id'],
                isset($file['game_id']) ? (int)$file['game_id'] : null,
                trim((string)($file['game_name'] ?? '')),
                trim((string)($file['engine_key'] ?? '')),
                isset($file['file_id']) ? (int)$file['file_id'] : null,
                $packageName,
                $originalName,
                strtolower(trim((string)($file['extension'] ?? pathinfo($originalName, PATHINFO_EXTENSION)))),
                (int)($file['file_size'] ?? 0),
                $md5 !== '' ? $md5 : null,
                strtolower(trim((string)($file['sha1'] ?? ''))) ?: null,
                $guid !== '' ? $guid : null,
                !empty($file['is_compressed']) ? 1 : 0,
                (int)($file['compression_flags'] ?? 0),
                (int)($file['import_count'] ?? 0),
                (int)($file['export_count'] ?? 0),
            ]);
            $count++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    fed_log($db, (int)$peer['id'], null, 'INFO', 'INVENTORY_PUSH', 'Received ' . $count . ' inventory row(s).');
    fed_json_response(['ok' => true, 'received' => $count]);
} catch (Throwable $e) {
    fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
