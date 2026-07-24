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

    try {
        catalog_public_join_rate_limit($siteId);
    } catch (RuntimeException $rateLimitError) {
        if (str_starts_with($rateLimitError->getMessage(), 'Too many requests.')) {
            fed_json_response(['ok' => false, 'error' => $rateLimitError->getMessage()], 429);
        }
        throw $rateLimitError;
    }

    if (catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE peer_site_id=? AND peer_role="child" AND is_active=1 LIMIT 1', [$siteId])) {
        fed_json_response(['ok' => false, 'error' => 'This site is already paired as a child. Remove the existing child connection before pairing it again.'], 409);
    }

    $tokenHash = hash('sha256', $requestToken);
    $existing = catalog_one(
        $db,
        'SELECT id,status FROM ue_federation_join_requests WHERE site_id=? AND status IN ("pending","approved") ORDER BY id DESC LIMIT 1',
        [$siteId]
    );
    if ($existing) {
        $status = (string)$existing['status'];
        $db->prepare(
            'UPDATE ue_federation_join_requests
             SET site_name=?,site_url=?,site_fingerprint=?,contact_name=?,contact_email=?,notes=?,
                 request_token_hash=?,claim_token_hash=CASE WHEN status="approved" THEN ? ELSE NULL END,
                 updated_at=NOW()
             WHERE id=?'
        )->execute([
            $siteName,
            $siteUrl,
            $fingerprint,
            $contactName ?: null,
            $contactEmail ?: null,
            $notes ?: null,
            $tokenHash,
            $tokenHash,
            (int)$existing['id'],
        ]);
        fed_log($db, null, null, 'INFO', 'JOIN_REQUEST_API_REFRESHED', 'Refreshed active join request #' . (int)$existing['id'] . ' for ' . $siteName . '.');
        fed_json_response([
            'ok' => true,
            'request_id' => (int)$existing['id'],
            'status' => $status,
            'message' => $status === 'approved'
                ? 'Existing approved join request refreshed. Check status to complete pairing.'
                : 'Existing pending join request refreshed.',
        ]);
    }

    $stmt = $db->prepare('INSERT INTO ue_federation_join_requests(status,requested_role,site_name,site_url,site_id,site_fingerprint,contact_name,contact_email,notes,request_token_hash) VALUES("pending","child",?,?,?,?,?,?,?,?)');
    $stmt->execute([$siteName, $siteUrl, $siteId, $fingerprint, $contactName ?: null, $contactEmail ?: null, $notes ?: null, $tokenHash]);
    $id = (int)$db->lastInsertId();
    fed_log($db, null, null, 'INFO', 'JOIN_REQUEST_API_SUBMITTED', 'Auto join request #' . $id . ' from ' . $siteName . ' / ' . $siteUrl);
    fed_json_response(['ok' => true, 'request_id' => $id, 'status' => 'pending', 'message' => 'Join request submitted. Waiting for parent administrator approval.']);
} catch (Throwable $error) {
    $requestId = catalog_request_id();
    error_log('[UnrealDB federation join][' . $requestId . '] ' . $error->getMessage());
    $safeDetails = [
        'Request rate-limit storage is unavailable.',
        'Could not create request rate-limit storage.',
        'Could not lock request rate-limit state.',
        'Could not persist request rate-limit state.',
    ];
    $publicError = in_array($error->getMessage(), $safeDetails, true)
        ? $error->getMessage()
        : 'Join request could not be processed.';
    fed_json_response([
        'ok' => false,
        'error' => $publicError,
        'reference' => $requestId,
    ], 503);
}
