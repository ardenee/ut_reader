<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for federation auth.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Security\FederationSecretStore;

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/TrustedHttpSourceClient.php';

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

function fed_base64url_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function fed_base64url_decode(string $value): string
{
    $value = trim($value);
    if ($value === '' || preg_match('/^[A-Za-z0-9_+\/-]+={0,2}$/', $value) !== 1) {
        throw new InvalidArgumentException('Invalid federation key encoding.');
    }
    $standard = strtr($value, '-_', '+/');
    $standard .= str_repeat('=', (4 - strlen($standard) % 4) % 4);
    $decoded = base64_decode($standard, true);
    if ($decoded === false) {
        throw new InvalidArgumentException('Invalid federation key encoding.');
    }
    return $decoded;
}

function fed_ed25519_secret_key(): string
{
    static $loaded = false;
    static $secret = '';
    if ($loaded) {
        return $secret;
    }
    $loaded = true;
    $configured = trim((string)(getenv('UNREALDB_FEDERATION_ED25519_PRIVATE_KEY') ?: ''));
    if ($configured === '') {
        return '';
    }
    if (!function_exists('sodium_crypto_sign_detached')) {
        throw new RuntimeException('Ed25519 federation signing requires the PHP sodium extension.');
    }
    $decoded = fed_base64url_decode($configured);
    if (strlen($decoded) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
        $pair = sodium_crypto_sign_seed_keypair($decoded);
        $secret = sodium_crypto_sign_secretkey($pair);
    } elseif (strlen($decoded) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        $secret = $decoded;
    } else {
        throw new RuntimeException('UNREALDB_FEDERATION_ED25519_PRIVATE_KEY must encode a 32-byte seed or 64-byte secret key.');
    }
    return $secret;
}

function fed_ed25519_public_key(): string
{
    $secret = fed_ed25519_secret_key();
    return $secret === '' ? '' : sodium_crypto_sign_publickey_from_secretkey($secret);
}

function fed_ed25519_key_id(string $publicKey): string
{
    return $publicKey === '' ? '' : strtoupper(substr(hash('sha256', $publicKey), 0, 24));
}

function fed_secret_store(): FederationSecretStore
{
    static $store = null;
    if (!$store instanceof FederationSecretStore) {
        $store = FederationSecretStore::fromEnvironment();
    }
    return $store;
}

function fed_require_encrypted_secrets(): bool
{
    return in_array(strtolower(trim((string)(getenv('UNREALDB_REQUIRE_ENCRYPTED_FEDERATION_SECRETS') ?: '0'))), ['1', 'true', 'yes', 'on'], true);
}

/** @return array{hash:string,stored:string} */
function fed_prepare_peer_secret(string $secret): array
{
    if ($secret === '' || strlen($secret) > 64) {
        throw new InvalidArgumentException('Federation shared secrets must contain between 1 and 64 bytes.');
    }

    $store = fed_secret_store();
    if ($store->hasMasterKey()) {
        $stored = $store->encrypt($secret);
    } elseif (fed_require_encrypted_secrets()) {
        throw new RuntimeException('Federation secret encryption is required, but UNREALDB_FEDERATION_MASTER_KEY is not configured.');
    } else {
        static $warned = false;
        if (!$warned) {
            error_log('[UnrealDB federation] Peer secrets are using plaintext compatibility mode. Configure UNREALDB_FEDERATION_MASTER_KEY and run encrypt-federation-secrets.php.');
            $warned = true;
        }
        $stored = $secret;
    }

    return ['hash' => password_hash($secret, PASSWORD_DEFAULT), 'stored' => $stored];
}

function fed_secret_for_crypto(string $stored): string
{
    if ($stored === '') {
        return '';
    }
    $store = fed_secret_store();
    if ($store->isEncrypted($stored)) {
        return $store->decrypt($stored);
    }
    if (fed_require_encrypted_secrets()) {
        throw new RuntimeException('A plaintext federation peer secret remains. Run catalog/bin/encrypt-federation-secrets.php before enabling strict secret policy.');
    }
    return $stored;
}

function fed_peer_secret(PDO $db, array $peer, bool $migratePlaintext = true): string
{
    $stored = (string)($peer['shared_secret_plain'] ?? '');
    if ($stored === '') {
        return '';
    }
    $store = fed_secret_store();
    if ($store->isEncrypted($stored)) {
        return $store->decrypt($stored);
    }
    if ($store->hasMasterKey() && $migratePlaintext && (int)($peer['id'] ?? 0) > 0) {
        $encrypted = $store->encrypt($stored);
        $stmt = $db->prepare('UPDATE ue_federation_peers SET shared_secret_plain=? WHERE id=? AND shared_secret_plain=?');
        $stmt->execute([$encrypted, (int)$peer['id'], $stored]);
        fed_log($db, (int)$peer['id'], null, 'INFO', 'PEER_SECRET_ENCRYPTED', 'Legacy plaintext peer secret encrypted at first authenticated use.');
        return $stored;
    }
    if (fed_require_encrypted_secrets()) {
        throw new RuntimeException('A plaintext federation peer secret remains. Run catalog/bin/encrypt-federation-secrets.php before enabling strict secret policy.');
    }
    return $stored;
}

/** @return array{migrated:int,encrypted:int,missing:int} */
function fed_migrate_peer_secrets(PDO $db): array
{
    $store = fed_secret_store();
    if (!$store->hasMasterKey()) {
        throw new RuntimeException('UNREALDB_FEDERATION_MASTER_KEY must be configured before migrating peer secrets.');
    }
    $counts = ['migrated' => 0, 'encrypted' => 0, 'missing' => 0];
    $rows = catalog_all($db, 'SELECT id, shared_secret_plain FROM ue_federation_peers ORDER BY id');
    $update = $db->prepare('UPDATE ue_federation_peers SET shared_secret_plain=? WHERE id=? AND shared_secret_plain=?');
    foreach ($rows as $row) {
        $stored = (string)($row['shared_secret_plain'] ?? '');
        if ($stored === '') {
            $counts['missing']++;
            continue;
        }
        if ($store->isEncrypted($stored)) {
            $store->decrypt($stored);
            $counts['encrypted']++;
            continue;
        }
        $encrypted = $store->encrypt($stored);
        $update->execute([$encrypted, (int)$row['id'], $stored]);
        if ($update->rowCount() === 1) {
            $counts['migrated']++;
        }
    }
    return $counts;
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
    $publicKey = fed_ed25519_public_key();
    return [
        'site_id' => $siteId,
        'site_url' => $siteUrl,
        'site_name' => $siteName,
        'site_fingerprint' => $fingerprint,
        'ed25519_public_key' => $publicKey !== '' ? fed_base64url_encode($publicKey) : '',
        'ed25519_key_id' => fed_ed25519_key_id($publicKey),
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
    return hash_hmac('sha256', fed_signature_payload($method, $path, $timestamp, $nonce, fed_body_hash($body)), fed_secret_for_crypto($secret));
}

function fed_verify_signature(string $secret, string $method, string $path, string $timestamp, string $nonce, string $body, string $signature): bool
{
    $expected = fed_sign_request($secret, $method, $path, $timestamp, $nonce, $body);
    return hash_equals($expected, $signature);
}

function fed_sign_request_ed25519(string $method, string $path, string $timestamp, string $nonce, string $body): string
{
    $secret = fed_ed25519_secret_key();
    if ($secret === '') {
        throw new RuntimeException('Ed25519 federation signing is not configured.');
    }
    $payload = fed_signature_payload($method, $path, $timestamp, $nonce, fed_body_hash($body));
    return fed_base64url_encode(sodium_crypto_sign_detached($payload, $secret));
}

function fed_verify_signature_ed25519(string $publicKey, string $method, string $path, string $timestamp, string $nonce, string $body, string $signature): bool
{
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        return false;
    }
    try {
        $keyBytes = fed_base64url_decode($publicKey);
        $signatureBytes = fed_base64url_decode($signature);
    } catch (Throwable) {
        return false;
    }
    if (strlen($keyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return false;
    }
    $payload = fed_signature_payload($method, $path, $timestamp, $nonce, fed_body_hash($body));
    return sodium_crypto_sign_verify_detached($signatureBytes, $payload, $keyBytes);
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
    $algorithm = strtolower(trim((string)($_SERVER['HTTP_X_SIGNATURE_ALGORITHM'] ?? 'hmac-sha256')));
    $keyId = trim((string)($_SERVER['HTTP_X_KEY_ID'] ?? ''));

    if ($siteId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
        fed_json_response(['ok' => false, 'error' => 'Missing federation auth headers'], 401);
    }
    $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE peer_site_id=? AND is_active=1', [$siteId]);
    if (!$peer) {
        fed_json_response(['ok' => false, 'error' => 'Unknown or inactive peer'], 403);
    }
    $nonceTtl = (int)(fed_setting($db, 'api_nonce_ttl_seconds', '300') ?: 300);
    $ts = strtotime($timestamp);
    if ($ts === false || abs(time() - $ts) > $nonceTtl) {
        fed_json_response(['ok' => false, 'error' => 'Timestamp outside allowed window'], 401);
    }
    if (catalog_one($db, 'SELECT id FROM ue_federation_nonces WHERE nonce=?', [$nonce])) {
        fed_json_response(['ok' => false, 'error' => 'Nonce already used'], 401);
    }

    $verified = false;
    if ($algorithm === 'ed25519') {
        $publicKey = trim((string)($peer['signing_public_key'] ?? ''));
        $configuredKeyId = trim((string)($peer['signing_key_id'] ?? ''));
        $revoked = !empty($peer['signing_revoked_at']);
        if ($publicKey === '' || $revoked || ($keyId !== '' && $configuredKeyId !== '' && !hash_equals($configuredKeyId, $keyId))) {
            fed_log($db, (int)$peer['id'], null, 'WARN', 'SIGNING_KEY_REJECTED', fed_request_path());
            fed_json_response(['ok' => false, 'error' => 'Peer signing key is unavailable or revoked'], 401);
        }
        $verified = fed_verify_signature_ed25519($publicKey, (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), fed_request_path(), $timestamp, $nonce, $body, $signature);
    } elseif ($algorithm === 'hmac-sha256' || $algorithm === 'hmac') {
        $secret = fed_peer_secret($db, $peer);
        if ($secret === '') {
            fed_json_response(['ok' => false, 'error' => 'Peer has no API secret stored.'], 501);
        }
        $verified = fed_verify_signature($secret, (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), fed_request_path(), $timestamp, $nonce, $body, $signature);
        $algorithm = 'hmac-sha256';
    } else {
        fed_json_response(['ok' => false, 'error' => 'Unsupported signature algorithm'], 401);
    }

    if (!$verified) {
        fed_log($db, (int)$peer['id'], null, 'WARN', 'SIGNATURE_FAIL', $algorithm . ' ' . fed_request_path());
        fed_json_response(['ok' => false, 'error' => 'Invalid signature'], 401);
    }
    $stmt = $db->prepare('INSERT INTO ue_federation_nonces(peer_id, nonce) VALUES(?,?)');
    $stmt->execute([(int)$peer['id'], $nonce]);
    $stmt = $db->prepare('UPDATE ue_federation_peers SET last_seen_at=NOW() WHERE id=?');
    $stmt->execute([(int)$peer['id']]);
    return $peer;
}

function fed_outgoing_signature_algorithm(): string
{
    $configured = strtolower(trim((string)(getenv('UNREALDB_FEDERATION_SIGNATURE_ALGORITHM') ?: 'hmac-sha256')));
    return $configured === 'ed25519' ? 'ed25519' : 'hmac-sha256';
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
    $algorithm = fed_outgoing_signature_algorithm();
    $headers = [
        'Content-Type: application/json',
        'User-Agent: UnrealFileCatalogFederation/2.0',
        'X-Site-Id: ' . $siteId,
        'X-Timestamp: ' . $timestamp,
        'X-Nonce: ' . $nonce,
        'X-Signature-Algorithm: ' . $algorithm,
    ];
    if ($algorithm === 'ed25519') {
        $publicKey = fed_ed25519_public_key();
        if ($publicKey === '') {
            throw new RuntimeException('Ed25519 outgoing federation signing is selected but no private key is configured.');
        }
        $signature = fed_sign_request_ed25519('POST', $path, $timestamp, $nonce, $body);
        $headers[] = 'X-Key-Id: ' . fed_ed25519_key_id($publicKey);
    } else {
        $signature = fed_sign_request($secret, 'POST', $path, $timestamp, $nonce, $body);
    }
    $headers[] = 'X-Signature: ' . $signature;
    return TrustedHttpSourceClient::postJson($url, $headers, $body, fed_request_body_limit_bytes(8388608), 60);
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
        'signature_algorithms' => ['hmac-sha256', 'ed25519'],
        'ed25519_public_key' => $identity['ed25519_public_key'],
        'ed25519_key_id' => $identity['ed25519_key_id'],
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
