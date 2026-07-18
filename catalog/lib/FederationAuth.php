<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

function fed_random_id(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function fed_random_secret(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function fed_setting(PDO $db, string $name, ?string $default = null): ?string
{
    $row = catalog_one($db, 'SELECT setting_value FROM ue_federation_settings WHERE setting_name=?', [$name]);
    return $row ? (string)$row['setting_value'] : $default;
}

function fed_set_setting(PDO $db, string $name, string $value): void
{
    $stmt = $db->prepare('INSERT INTO ue_federation_settings(setting_name, setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $stmt->execute([$name, $value]);
}

function fed_all_settings(PDO $db): array
{
    $rows = catalog_all($db, 'SELECT setting_name, setting_value FROM ue_federation_settings ORDER BY setting_name');
    $out = [];
    foreach ($rows as $row) {
        $out[(string)$row['setting_name']] = (string)($row['setting_value'] ?? '');
    }
    return $out;
}

function fed_site_fingerprint(string $siteUrl, string $siteId): string
{
    return strtoupper(substr(hash('sha256', rtrim(strtolower(trim($siteUrl)), '/') . '|' . strtolower(trim($siteId))), 0, 32));
}

function fed_ensure_identity(PDO $db, string $siteUrl = '', string $siteName = ''): array
{
    $siteId = fed_setting($db, 'site_id', '') ?: '';
    if ($siteId === '') {
        $siteId = fed_random_id();
        fed_set_setting($db, 'site_id', $siteId);
    }

    if ($siteUrl !== '') {
        fed_set_setting($db, 'site_url', $siteUrl);
    } else {
        $siteUrl = fed_setting($db, 'site_url', '') ?: '';
    }

    if ($siteName !== '') {
        fed_set_setting($db, 'site_name', $siteName);
    } else {
        $siteName = fed_setting($db, 'site_name', '') ?: '';
    }

    $fingerprint = $siteUrl !== '' ? fed_site_fingerprint($siteUrl, $siteId) : '';
    if ($fingerprint !== '') {
        fed_set_setting($db, 'site_fingerprint', $fingerprint);
    }

    return [
        'site_id' => $siteId,
        'site_url' => $siteUrl,
        'site_name' => $siteName,
        'site_fingerprint' => $fingerprint,
    ];
}

function fed_log(PDO $db, ?int $peerId, ?int $jobId, string $level, string $event, string $details = ''): void
{
    $stmt = $db->prepare('INSERT INTO ue_federation_transfer_logs(peer_id, transfer_job_id, level, event, details) VALUES(?,?,?,?,?)');
    $stmt->execute([$peerId, $jobId, $level, $event, $details]);
}

function fed_request_body_limit_bytes(int $default = 1048576): int
{
    $configured = (int)(getenv('UNREALDB_FEDERATION_MAX_JSON_BYTES') ?: 0);
    return max(1024, min($configured > 0 ? $configured : $default, 64 * 1024 * 1024));
}

function fed_read_request_body(?int $maxBytes = null): string
{
    $limit = $maxBytes ?? fed_request_body_limit_bytes();
    $limit = max(1024, min($limit, 64 * 1024 * 1024));
    $declaredLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
    if ($declaredLength !== false && $declaredLength !== null && (int)$declaredLength > $limit) {
        fed_json_response(['ok' => false, 'error' => 'Request body exceeds the allowed size.'], 413);
    }

    $stream = fopen('php://input', 'rb');
    if (!is_resource($stream)) {
        fed_json_response(['ok' => false, 'error' => 'Request body could not be read.'], 400);
    }

    try {
        $body = stream_get_contents($stream, $limit + 1);
    } finally {
        fclose($stream);
    }

    if (!is_string($body)) {
        fed_json_response(['ok' => false, 'error' => 'Request body could not be read.'], 400);
    }
    if (strlen($body) > $limit) {
        fed_json_response(['ok' => false, 'error' => 'Request body exceeds the allowed size.'], 413);
    }

    return $body;
}

/** @return array<string,mixed> */
function fed_decode_json_object(string $body): array
{
    try {
        $payload = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload.'], 400);
    }

    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'JSON payload must be an object.'], 400);
    }

    return $payload;
}

function fed_body_hash(string $body): string
{
    return hash('sha256', $body);
}

function fed_signature_payload(string $method, string $path, string $timestamp, string $nonce, string $bodyHash): string
{
    return strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $bodyHash;
}

function fed_sign_request(string $secret, string $method, string $path, string $timestamp, string $nonce, string $body): string
{
    return hash_hmac('sha256', fed_signature_payload($method, $path, $timestamp, $nonce, fed_body_hash($body)), $secret);
}

function fed_verify_signature(string $secret, string $method, string $path, string $timestamp, string $nonce, string $body, string $signature): bool
{
    $expected = fed_sign_request($secret, $method, $path, $timestamp, $nonce, $body);
    return hash_equals($expected, $signature);
}

function fed_request_path(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $pos = strpos($uri, '?');
    return $pos === false ? $uri : substr($uri, 0, $pos);
}

function fed_require_signed_peer(PDO $db, string $body): array
{
    $siteId = (string)($_SERVER['HTTP_X_SITE_ID'] ?? '');
    $timestamp = (string)($_SERVER['HTTP_X_TIMESTAMP'] ?? '');
    $nonce = (string)($_SERVER['HTTP_X_NONCE'] ?? '');
    $signature = (string)($_SERVER['HTTP_X_SIGNATURE'] ?? '');

    if ($siteId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
        fed_json_response(['ok' => false, 'error' => 'Missing federation auth headers'], 401);
    }

    $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE peer_site_id=? AND is_active=1', [$siteId]);
    if (!$peer) {
        fed_json_response(['ok' => false, 'error' => 'Unknown or inactive peer'], 403);
    }

    $secret = (string)($peer['shared_secret_plain'] ?? '');
    if ($secret === '') {
        fed_json_response(['ok' => false, 'error' => 'Peer has no API secret stored. Re-add or update the peer after installing update 005.'], 501);
    }

    $nonceTtl = (int)(fed_setting($db, 'api_nonce_ttl_seconds', '300') ?: 300);
    $ts = strtotime($timestamp);
    if ($ts === false || abs(time() - $ts) > $nonceTtl) {
        fed_json_response(['ok' => false, 'error' => 'Timestamp outside allowed window'], 401);
    }

    $existingNonce = catalog_one($db, 'SELECT id FROM ue_federation_nonces WHERE nonce=?', [$nonce]);
    if ($existingNonce) {
        fed_json_response(['ok' => false, 'error' => 'Nonce already used'], 401);
    }

    if (!fed_verify_signature($secret, (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), fed_request_path(), $timestamp, $nonce, $body, $signature)) {
        fed_log($db, (int)$peer['id'], null, 'WARN', 'SIGNATURE_FAIL', fed_request_path());
        fed_json_response(['ok' => false, 'error' => 'Invalid signature'], 401);
    }

    $stmt = $db->prepare('INSERT INTO ue_federation_nonces(peer_id, nonce) VALUES(?,?)');
    $stmt->execute([(int)$peer['id'], $nonce]);
    $stmt = $db->prepare('UPDATE ue_federation_peers SET last_seen_at=NOW() WHERE id=?');
    $stmt->execute([(int)$peer['id']]);

    return $peer;
}

function fed_http_post_signed(string $url, string $siteId, string $secret, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        throw new RuntimeException('Could not encode federation payload.');
    }

    $timestamp = date('c');
    $nonce = fed_random_secret();
    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    $signature = fed_sign_request($secret, 'POST', $path, $timestamp, $nonce, $body);

    $headers = [
        'Content-Type: application/json',
        'User-Agent: UnrealFileCatalogFederation/1.0',
        'X-Site-Id: ' . $siteId,
        'X-Timestamp: ' . $timestamp,
        'X-Nonce: ' . $nonce,
        'X-Signature: ' . $signature,
    ];

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body,
            'timeout' => 60,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        throw new RuntimeException('Federation POST failed: ' . $url);
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        throw new RuntimeException('Federation POST returned invalid JSON: ' . substr($response, 0, 300));
    }

    return $json;
}

function fed_public_status(PDO $db): array
{
    $identity = fed_ensure_identity($db);
    return [
        'ok' => true,
        'site_name' => $identity['site_name'],
        'site_url' => $identity['site_url'],
        'site_id' => $identity['site_id'],
        'site_fingerprint' => $identity['site_fingerprint'],
        'site_role' => fed_setting($db, 'site_role', 'standalone'),
        'parent_enabled' => fed_setting($db, 'parent_enabled', '0'),
        'child_enabled' => fed_setting($db, 'child_enabled', '0'),
        'server_time' => date('c'),
    ];
}

function fed_json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
