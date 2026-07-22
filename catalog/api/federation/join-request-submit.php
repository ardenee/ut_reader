<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/CatalogPublicRateLimit.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        fed_json_response(['ok' => false, 'error' => 'Only POST is supported.'], 405);
    }
    $config = catalog_config();
    $db = catalog_db($config);

    if ((string)fed_setting($db, 'join_requests_enabled', '1') !== '1') {
        fed_json_response(['ok' => false, 'error' => 'Join requests are disabled on this parent.'], 403);
    }

    $body = fed_read_request_body(min(fed_request_body_limit_bytes(65536), 262144));
    $payload = fed_decode_json_object($body);
    $siteName = trim((string)($payload['site_name'] ?? ''));
    $siteUrl = rtrim(trim((string)($payload['site_url'] ?? '')), '/');
    $siteId = strtolower(trim((string)($payload['site_id'] ?? '')));
    $fingerprint = strtoupper(trim((string)($payload['site_fingerprint'] ?? '')));
    $requestToken = trim((string)($payload['request_token'] ?? ''));
    $contactName = trim((string)($payload['contact_name'] ?? ''));
    $contactEmail = trim((string)($payload['contact_email'] ?? ''));
    $notes = trim((string)($payload['notes'] ?? ''));

    catalog_public_join_rate_limit($siteId);

    if ($siteName === '' || strlen($siteName) > 160 || $siteUrl === '' || strlen($siteUrl) > 1000 || $siteId === '' || $fingerprint === '' || $requestToken === '') {
        fed_json_response(['ok' => false, 'error' => 'Valid site_name, site_url, site_id, site_fingerprint, and request_token values are required.'], 400);
    }
    if (preg_match('/^[a-f0-9-]{36}$/', $siteId) !== 1 || preg_match('/^[A-F0-9]{32}$/', $fingerprint) !== 1 || strlen($requestToken) < 32 || strlen($requestToken) > 256) {
        fed_json_response(['ok' => false, 'error' => 'Federation identity fields are invalid.'], 400);
    }
    $url = parse_url($siteUrl);
    if (!is_array($url) || strtolower((string)($url['scheme'] ?? '')) !== 'https' || trim((string)($url['host'] ?? '')) === '' || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) {
        fed_json_response(['ok' => false, 'error' => 'site_url must be a plain HTTPS URL.'], 400);
    }
    if ($contactEmail !== '' && (strlen($contactEmail) > 255 || filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false)) {
        fed_json_response(['ok' => false, 'error' => 'contact_email is invalid.'], 400);
    }
    if (strlen($contactName) > 160 || strlen($notes) > 4000) {
        fed_json_response(['ok' => false, 'error' => 'Contact name or notes exceed the allowed length.'], 400);
    }

    $expected = fed_site_fingerprint($siteUrl, $siteId);
    if (!hash_equals($expected, $fingerprint)) {
        fed_json_response(['ok' => false, 'error' => 'Fingerprint does not match site_url and site_id.'], 400);
    }

    if (catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE peer_site_id=? LIMIT 1', [$siteId])) {
        fed_json_response(['ok' => true, 'status' => 'already_paired', 'message' => 'This site ID is already known to the parent.']);
    }
    $existing = catalog_one($db, 'SELECT id,status FROM ue_federation_join_requests WHERE site_id=? AND status IN ("pending","approved") ORDER BY id DESC LIMIT 1', [$siteId]);
    if ($existing) {
        fed_json_response(['ok' => true, 'request_id' => (int)$existing['id'], 'status' => (string)$existing['status'], 'message' => 'Existing active join request found.']);
    }

    $stmt = $db->prepare('INSERT INTO ue_federation_join_requests(status,requested_role,site_name,site_url,site_id,site_fingerprint,contact_name,contact_email,notes,request_token_hash) VALUES("pending","child",?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$siteName, $siteUrl, $siteId, $fingerprint, $contactName ?: null, $contactEmail ?: null, $notes ?: null, hash('sha256', $requestToken)]);
    $id = (int)$db->lastInsertId();
    fed_log($db, null, null, 'INFO', 'JOIN_REQUEST_API_SUBMITTED', 'Auto join request #' . $id . ' from ' . $siteName . ' / ' . $siteUrl);
    fed_json_response(['ok' => true, 'request_id' => $id, 'status' => 'pending', 'message' => 'Join request submitted. Waiting for parent admin approval.']);
} catch (Throwable $error) {
    $requestId = catalog_request_id();
    error_log('[UnrealDB federation join][' . $requestId . '] ' . $error->getMessage());
    fed_json_response([
        'ok' => false,
        'error' => 'Join request could not be processed.',
        'reference' => $requestId,
    ], 503);
}
