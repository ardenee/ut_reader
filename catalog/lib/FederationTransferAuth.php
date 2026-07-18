<?php
declare(strict_types=1);

require_once __DIR__ . '/FederationAuth.php';

function fed_transfer_signature(string $secret, string $method, string $path, string $timestamp, string $nonce, string $sha256, int $bytes, int $remoteId, string $name): string
{
    $payload = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . strtolower($sha256) . "\n" . $bytes . "\n" . $remoteId . "\n" . $name;
    return hash_hmac('sha256', $payload, fed_secret_for_crypto($secret));
}

/** @return array{0:array,1:array{sha256:string,bytes:int,remote_id:int,name:string}} */
function fed_require_streaming_upload_peer(PDO $db): array
{
    $siteId = trim((string)($_SERVER['HTTP_X_SITE_ID'] ?? ''));
    $timestamp = trim((string)($_SERVER['HTTP_X_TIMESTAMP'] ?? ''));
    $nonce = trim((string)($_SERVER['HTTP_X_NONCE'] ?? ''));
    $signature = trim((string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
    $sha256 = strtolower(trim((string)($_SERVER['HTTP_X_UE_SHA256'] ?? '')));
    $bytes = (int)($_SERVER['HTTP_X_UE_FILE_SIZE'] ?? 0);
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $remoteId = max(0, (int)($_SERVER['HTTP_X_UE_REMOTE_FILE_ID'] ?? 0));
    $name = trim((string)($_SERVER['HTTP_X_UE_ORIGINAL_NAME'] ?? ''));

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'PUT' || $siteId === '' || $timestamp === '' || $nonce === '' || $signature === '' || !preg_match('/^[a-f0-9]{64}$/', $sha256) || $bytes < 1 || $bytes !== $contentLength || $name === '' || str_contains($name, "\r") || str_contains($name, "\n") || str_contains($name, "\0")) {
        fed_json_response(['ok' => false, 'error' => 'Invalid streaming upload headers'], 400);
    }

    $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE peer_site_id=? AND is_active=1', [$siteId]);
    if (!$peer) {
        fed_json_response(['ok' => false, 'error' => 'Unknown or inactive peer'], 403);
    }
    $secret = fed_peer_secret($db, $peer);
    if ($secret === '') {
        fed_json_response(['ok' => false, 'error' => 'Unknown or inactive peer'], 403);
    }
    $ts = strtotime($timestamp);
    $ttl = (int)(fed_setting($db, 'api_nonce_ttl_seconds', '300') ?: 300);
    if ($ts === false || abs(time() - $ts) > $ttl || catalog_one($db, 'SELECT id FROM ue_federation_nonces WHERE nonce=?', [$nonce])) {
        fed_json_response(['ok' => false, 'error' => 'Expired or reused transfer credentials'], 401);
    }

    $expected = fed_transfer_signature($secret, 'PUT', fed_request_path(), $timestamp, $nonce, $sha256, $bytes, $remoteId, $name);
    if (!hash_equals($expected, $signature)) {
        fed_log($db, (int)$peer['id'], null, 'WARN', 'TRANSFER_SIGNATURE_FAIL', fed_request_path());
        fed_json_response(['ok' => false, 'error' => 'Invalid transfer signature'], 401);
    }
    try {
        $db->prepare('INSERT INTO ue_federation_nonces(peer_id, nonce) VALUES(?,?)')->execute([(int)$peer['id'], $nonce]);
    } catch (PDOException) {
        fed_json_response(['ok' => false, 'error' => 'Nonce already used'], 401);
    }
    $db->prepare('UPDATE ue_federation_peers SET last_seen_at=NOW() WHERE id=?')->execute([(int)$peer['id']]);
    return [$peer, ['sha256' => $sha256, 'bytes' => $bytes, 'remote_id' => $remoteId, 'name' => $name]];
}
