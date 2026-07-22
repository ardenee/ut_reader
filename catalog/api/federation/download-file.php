<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';
require_once __DIR__ . '/../../lib/BaseGameProtection.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);
    $body = fed_read_request_body(32768);
    $peer = fed_require_signed_peer($db, $body);

    if ((string)$peer['peer_role'] !== 'parent') {
        fed_json_response(['ok' => false, 'error' => 'Only the paired parent may pull files from this child.'], 403);
    }

    $payload = fed_decode_json_object($body);
    $fileId = (int)($payload['remote_file_id'] ?? 0);
    if ($fileId <= 0) {
        fed_json_response(['ok' => false, 'error' => 'remote_file_id is required.'], 400);
    }

    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="verified"', [$fileId]);
    if (!$file) {
        fed_json_response(['ok' => false, 'error' => 'File not found or not verified.'], 404);
    }
    if (base_game_file_is_protected($db, $file)) {
        fed_json_response(['ok' => false, 'error' => base_game_block_message($file)], 403);
    }

    $root = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    $path = realpath(__DIR__ . '/../../' . (string)$file['relative_path']);
    if (!$root || !$path || !str_starts_with(str_replace('\\', '/', $path) . '/', rtrim(str_replace('\\', '/', $root), '/') . '/') || !is_file($path) || is_link($path)) {
        fed_json_response(['ok' => false, 'error' => 'Stored file missing.'], 404);
    }

    fed_log($db, (int)$peer['id'], null, 'INFO', 'PARENT_PULL_DOWNLOAD', 'Serving file ID ' . $fileId . ' to parent/master peer without child approval.');

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . addslashes((string)$file['original_name']) . '"');
    header('X-UE-File-Id: ' . (int)$file['id']);
    header('X-UE-Package-Guid: ' . (string)$file['package_guid']);
    header('X-UE-MD5: ' . (string)$file['md5']);
    header('X-UE-SHA1: ' . (string)$file['sha1']);
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
} catch (Throwable $e) {
    if (!headers_sent()) {
        fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}
