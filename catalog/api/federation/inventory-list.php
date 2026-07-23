<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';
require_once __DIR__ . '/../../lib/BaseGameProtection.php';
require_once __DIR__ . '/../../lib/FederationBaseGamePolicy.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        header('Allow: POST');
        fed_json_response(['ok' => false, 'error' => 'Inventory requests require POST.'], 405);
    }

    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);

    $body = fed_read_request_body(32768);
    $peer = fed_require_signed_peer($db, $body);
    $localRole = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    $peerRole = strtolower(trim((string)($peer['peer_role'] ?? '')));
    $allowed = ($localRole === 'parent' && $peerRole === 'child')
        || ($localRole === 'child' && $peerRole === 'parent');
    if (!$allowed) {
        fed_json_response(['ok' => false, 'error' => 'Only the paired opposite federation role may read this inventory.'], 403);
    }

    $payload = fed_decode_json_object($body);
    $afterFileId = max(0, (int)($payload['after_file_id'] ?? 0));
    $limit = max(1, min(1000, (int)($payload['limit'] ?? 500)));

    $rows = catalog_all(
        $db,
        'SELECT f.id file_id, f.game_id, g.name game_name,
                COALESCE(p.engine_key, "") engine_key,
                f.package_name, f.original_name, f.extension,
                f.file_size, f.md5, f.sha1, f.package_guid,
                CASE WHEN bg.id IS NOT NULL THEN 1 ELSE 0 END is_base_game,
                COALESCE(f.is_compressed,0) is_compressed,
                COALESCE(f.compression_flags,0) compression_flags,
                COALESCE(f.import_count,0) import_count,
                COALESCE(f.export_count,0) export_count
         FROM ue_files f
         JOIN ue_games g ON g.id=f.game_id
         LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
         LEFT JOIN ue_base_game_files bg ON bg.game_id=f.game_id AND bg.package_guid=f.package_guid
         WHERE f.scan_status="verified" AND f.id>?
         ORDER BY f.id
         LIMIT ' . $limit,
        [$afterFileId]
    );

    $files = [];
    $nextAfter = $afterFileId;
    foreach ($rows as $row) {
        $fileId = (int)$row['file_id'];
        $nextAfter = max($nextAfter, $fileId);
        $files[] = [
            'file_id' => $fileId,
            'game_id' => (int)$row['game_id'],
            'game_name' => (string)$row['game_name'],
            'engine_key' => (string)$row['engine_key'],
            'package_name' => (string)$row['package_name'],
            'original_name' => (string)$row['original_name'],
            'extension' => (string)$row['extension'],
            'file_size' => (int)$row['file_size'],
            'md5' => (string)$row['md5'],
            'sha1' => (string)$row['sha1'],
            'package_guid' => (string)$row['package_guid'],
            'is_base_game' => (int)$row['is_base_game'],
            'is_compressed' => (int)$row['is_compressed'],
            'compression_flags' => (int)$row['compression_flags'],
            'import_count' => (int)$row['import_count'],
            'export_count' => (int)$row['export_count'],
        ];
    }

    $identity = fed_ensure_identity($db);
    fed_log($db, (int)$peer['id'], null, 'INFO', 'INVENTORY_READ_BY_PEER', 'Returned ' . count($files) . ' classified inventory row(s) after file ID ' . $afterFileId . '.');
    fed_json_response([
        'ok' => true,
        'site' => [
            'site_id' => (string)$identity['site_id'],
            'site_name' => (string)$identity['site_name'],
            'site_url' => (string)$identity['site_url'],
        ],
        'policy' => $localRole === 'parent' ? federation_parent_base_game_policy($db) : null,
        'files' => $files,
        'next_after_file_id' => $nextAfter,
        'complete' => count($rows) < $limit,
        'generated_at' => date('c'),
    ]);
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] federation inventory read failed: ' . get_class($error) . ': ' . $error->getMessage());
    fed_json_response(['ok' => false, 'error' => 'Peer inventory could not be read.', 'reference' => catalog_request_id()], 500);
}
