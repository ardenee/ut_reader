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
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
