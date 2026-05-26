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
    $siteId = $_SERVER['HTTP_X_SITE_ID'] ?? '';
    $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
    $nonce = $_SERVER['HTTP_X_NONCE'] ?? '';
    $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

    if ($siteId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
        fed_json_response(['ok' => false, 'error' => 'Missing federation auth headers'], 401);
    }

    $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE peer_site_id=? AND is_active=1', [$siteId]);
    if (!$peer) {
        fed_json_response(['ok' => false, 'error' => 'Unknown or inactive peer'], 403);
    }

    $nonceTtl = (int)(fed_setting($db, 'api_nonce_ttl_seconds', '300') ?: 300);
    if (abs(time() - strtotime($timestamp)) > $nonceTtl) {
        fed_json_response(['ok' => false, 'error' => 'Timestamp outside allowed window'], 401);
    }

    $existingNonce = catalog_one($db, 'SELECT id FROM ue_federation_nonces WHERE nonce=?', [$nonce]);
    if ($existingNonce) {
        fed_json_response(['ok' => false, 'error' => 'Nonce already used'], 401);
    }

    if (!password_verify('', (string)$peer['shared_secret_hash'])) {
        // shared_secret_hash stores password_hash(secret), so we cannot HMAC-verify without a plaintext secret.
        // This endpoint is a placeholder until per-peer encrypted/plain API secret storage is added.
        fed_log($db, (int)$peer['id'], null, 'WARN', 'PING_PLACEHOLDER', 'Signed ping received but plaintext secret storage is not yet enabled.');
        fed_json_response(['ok' => false, 'error' => 'Signed ping verification is not enabled yet; peer registration foundation is installed.'], 501);
    }

    fed_json_response(['ok' => false, 'error' => 'Unreachable verification state'], 500);
} catch (Throwable $e) {
    fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
