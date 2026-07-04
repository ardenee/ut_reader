<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';

function federation_build_inventory_payload(PDO $db): array
{
    $identity = fed_ensure_identity($db);
    $files = catalog_all($db, 'SELECT f.*, g.name game_name, p.engine_key profile_engine FROM ue_files f JOIN ue_games g ON g.id=f.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE f.scan_status="verified" ORDER BY f.id');
    $out = [];
    foreach ($files as $file) {
        $out[] = [
            'file_id' => (int)$file['id'],
            'game_id' => (int)$file['game_id'],
            'game_name' => (string)$file['game_name'],
            'engine_key' => (string)($file['profile_engine'] ?? ''),
            'package_name' => (string)$file['package_name'],
            'original_name' => (string)$file['original_name'],
            'extension' => (string)$file['extension'],
            'file_size' => (int)$file['file_size'],
            'md5' => (string)$file['md5'],
            'sha1' => (string)$file['sha1'],
            'package_guid' => (string)$file['package_guid'],
            'is_compressed' => (int)($file['is_compressed'] ?? 0),
            'compression_flags' => (int)($file['compression_flags'] ?? 0),
            'import_count' => (int)$file['import_count'],
            'export_count' => (int)$file['export_count'],
        ];
    }

    return [
        'site' => $identity,
        'generated_at' => date('c'),
        'file_count' => count($out),
        'files' => $out,
    ];
}

function federation_push_inventory_to_parent(PDO $db, int $peerId): array
{
    $parent = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1', [$peerId]);
    if (!$parent) {
        throw new RuntimeException('Active parent peer not found.');
    }
    $secret = (string)($parent['shared_secret_plain'] ?? '');
    if ($secret === '') {
        throw new RuntimeException('Selected parent peer has no stored API secret.');
    }

    $url = rtrim((string)$parent['site_url'], '/') . '/api/federation/inventory-push.php';
    $payload = federation_build_inventory_payload($db);
    $result = fed_http_post_signed($url, (string)fed_setting($db, 'site_id', ''), $secret, $payload);
    fed_log($db, (int)$parent['id'], null, !empty($result['ok']) ? 'INFO' : 'ERROR', 'INVENTORY_PUSH_SEND', json_encode($result, JSON_UNESCAPED_SLASHES));
    return $result;
}

function federation_auto_push_inventory_to_parent(PDO $db): array
{
    if ((string)fed_setting($db, 'site_role', 'standalone') !== 'child') {
        return ['ok' => true, 'skipped' => true, 'reason' => 'site is not child'];
    }

    $parent = catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY id LIMIT 1');
    if (!$parent) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'no active parent peer'];
    }

    return federation_push_inventory_to_parent($db, (int)$parent['id']);
}
