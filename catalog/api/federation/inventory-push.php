<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';
require_once __DIR__ . '/../../lib/FederationBaseGamePolicy.php';

function inventory_push_text(mixed $value, int $maxLength): string
{
    $value = trim((string)$value);
    if (mb_strlen($value, 'UTF-8') > $maxLength) {
        fed_json_response(['ok' => false, 'error' => 'Inventory field exceeds the allowed length.'], 422);
    }
    return $value;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $maxPayloadBytes = max(1024 * 1024, min((int)(fed_setting($db, 'max_inventory_payload_bytes', (string)(8 * 1024 * 1024)) ?: 8 * 1024 * 1024), 32 * 1024 * 1024));
    $body = fed_read_request_body($maxPayloadBytes);
    $peer = fed_require_signed_peer($db, $body);
    $payload = fed_decode_json_object($body);

    $files = $payload['files'] ?? [];
    if (!is_array($files)) {
        fed_json_response(['ok' => false, 'error' => 'Missing files array.'], 400);
    }

    $maxRows = max(1, min((int)(fed_setting($db, 'max_inventory_rows_per_push', '5000') ?: 5000), 20000));
    if (count($files) > $maxRows) {
        fed_json_response(['ok' => false, 'error' => 'Inventory batch exceeds the allowed row count.'], 413);
    }

    $normalized = [];
    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }

        $packageName = inventory_push_text($file['package_name'] ?? '', 255);
        $originalName = inventory_push_text($file['original_name'] ?? '', 255);
        $guid = strtoupper(inventory_push_text($file['package_guid'] ?? '', 64));
        $md5 = strtolower(inventory_push_text($file['md5'] ?? '', 32));
        $sha1 = strtolower(inventory_push_text($file['sha1'] ?? '', 40));
        if ($packageName === '' || $originalName === '' || ($guid === '' && $md5 === '')) {
            continue;
        }
        if ($md5 !== '' && preg_match('/^[a-f0-9]{32}$/', $md5) !== 1) {
            fed_json_response(['ok' => false, 'error' => 'Inventory contains an invalid MD5 value.'], 422);
        }
        if ($sha1 !== '' && preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            fed_json_response(['ok' => false, 'error' => 'Inventory contains an invalid SHA1 value.'], 422);
        }
        if ($guid !== '' && preg_match('/^[A-F0-9-]{8,64}$/', $guid) !== 1) {
            fed_json_response(['ok' => false, 'error' => 'Inventory contains an invalid package GUID.'], 422);
        }

        $normalized[] = [
            (int)$peer['id'],
            isset($file['game_id']) ? max(0, (int)$file['game_id']) ?: null : null,
            inventory_push_text($file['game_name'] ?? '', 160),
            inventory_push_text($file['engine_key'] ?? '', 32),
            isset($file['file_id']) ? max(0, (int)$file['file_id']) ?: null : null,
            $packageName,
            $originalName,
            strtolower(inventory_push_text($file['extension'] ?? pathinfo($originalName, PATHINFO_EXTENSION), 32)),
            max(0, (int)($file['file_size'] ?? 0)),
            $md5 !== '' ? $md5 : null,
            $sha1 !== '' ? $sha1 : null,
            $guid !== '' ? $guid : null,
            !empty($file['is_base_game']) ? 1 : 0,
            !empty($file['is_compressed']) ? 1 : 0,
            max(0, (int)($file['compression_flags'] ?? 0)),
            max(0, (int)($file['import_count'] ?? 0)),
            max(0, (int)($file['export_count'] ?? 0)),
        ];
    }

    $sql = 'INSERT INTO ue_federation_peer_files(peer_id,game_id,remote_game_name,remote_engine_key,remote_file_id,package_name,original_name,extension,file_size,md5,sha1,package_guid,is_base_game,is_compressed,compression_flags,import_count,export_count,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE game_id=VALUES(game_id), remote_game_name=VALUES(remote_game_name), remote_engine_key=VALUES(remote_engine_key), remote_file_id=VALUES(remote_file_id), package_name=VALUES(package_name), original_name=VALUES(original_name), extension=VALUES(extension), file_size=VALUES(file_size), md5=VALUES(md5), sha1=VALUES(sha1), package_guid=VALUES(package_guid), is_base_game=VALUES(is_base_game), is_compressed=VALUES(is_compressed), compression_flags=VALUES(compression_flags), import_count=VALUES(import_count), export_count=VALUES(export_count), last_seen_at=NOW()';
    $count = 0;
    foreach (array_chunk($normalized, 500) as $chunk) {
        $db->beginTransaction();
        try {
            $statement = $db->prepare($sql);
            foreach ($chunk as $values) {
                $statement->execute($values);
                $count++;
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    fed_log($db, (int)$peer['id'], null, 'INFO', 'INVENTORY_PUSH', 'Received ' . $count . ' classified inventory row(s).');
    fed_json_response([
        'ok' => true,
        'received' => $count,
        'policy' => strtolower(trim((string)fed_setting($db, 'site_role', 'standalone'))) === 'parent'
            ? federation_parent_base_game_policy($db)
            : null,
    ]);
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] federation inventory push failed: ' . get_class($error) . ': ' . $error->getMessage());
    fed_json_response(['ok' => false, 'error' => 'Inventory could not be processed.', 'reference' => catalog_request_id()], 500);
}
