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

    if ((string)fed_setting($db, 'join_requests_enabled', '1') !== '1') {
        fed_json_response(['ok' => false, 'error' => 'Join requests are disabled on this parent.'], 403);
    }

    $body = file_get_contents('php://input') ?: '';
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $siteName = trim((string)($payload['site_name'] ?? ''));
    $siteUrl = rtrim(trim((string)($payload['site_url'] ?? '')), '/');
    $siteId = trim((string)($payload['site_id'] ?? ''));
    $fingerprint = strtoupper(trim((string)($payload['site_fingerprint'] ?? '')));
    $requestToken = trim((string)($payload['request_token'] ?? ''));
    $contactName = trim((string)($payload['contact_name'] ?? ''));
    $contactEmail = trim((string)($payload['contact_email'] ?? ''));
    $notes = trim((string)($payload['notes'] ?? ''));

    if ($siteName === '' || $siteUrl === '' || $siteId === '' || $fingerprint === '' || $requestToken === '') {
        fed_json_response(['ok' => false, 'error' => 'site_name, site_url, site_id, site_fingerprint, and request_token are required.'], 400);
    }
    if (!preg_match('/^https?:\/\//i', $siteUrl)) {
        fed_json_response(['ok' => false, 'error' => 'site_url must start with http:// or https://'], 400);
    }
    if ((string)fed_setting($db, 'require_https_for_remote_sites', '1') === '1' && !str_starts_with(strtolower($siteUrl), 'https://')) {
        fed_json_response(['ok' => false, 'error' => 'This parent requires HTTPS federation site URLs.'], 400);
    }

    $expected = fed_site_fingerprint($siteUrl, $siteId);
    if (!hash_equals($expected, $fingerprint)) {
        fed_json_response(['ok' => false, 'error' => 'Fingerprint does not match site_url and site_id.', 'expected_fingerprint' => $expected], 400);
    }

    $existingPeer = catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE peer_site_id=? LIMIT 1', [$siteId]);
    if ($existingPeer) {
        fed_json_response(['ok' => true, 'status' => 'already_paired', 'message' => 'This site ID is already known to the parent.']);
    }

    $existing = catalog_one($db, 'SELECT * FROM ue_federation_join_requests WHERE site_id=? AND status IN ("pending","approved") ORDER BY id DESC LIMIT 1', [$siteId]);
    if ($existing) {
        fed_json_response(['ok' => true, 'request_id' => (int)$existing['id'], 'status' => (string)$existing['status'], 'message' => 'Existing active join request found.']);
    }

    $stmt = $db->prepare('INSERT INTO ue_federation_join_requests(status, requested_role, site_name, site_url, site_id, site_fingerprint, contact_name, contact_email, notes, request_token_hash) VALUES("pending", "child", ?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$siteName, $siteUrl, $siteId, $fingerprint, $contactName ?: null, $contactEmail ?: null, $notes ?: null, hash('sha256', $requestToken)]);
    $id = (int)$db->lastInsertId();

    fed_log($db, null, null, 'INFO', 'JOIN_REQUEST_API_SUBMITTED', 'Auto join request #' . $id . ' from ' . $siteName . ' / ' . $siteUrl);
    fed_json_response(['ok' => true, 'request_id' => $id, 'status' => 'pending', 'message' => 'Join request submitted. Waiting for parent admin approval.']);
} catch (Throwable $e) {
    fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
