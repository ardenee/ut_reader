<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

function cp_allow_self_signed_tls(PDO $db): bool
{
    return (string)fed_setting($db, 'allow_self_signed_federation_certificates', '0') === '1';
}

function cp_post_claim(PDO $db, string $endpoint, string $token): array
{
    $parts = parse_url($endpoint);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['https', 'http'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('Claim endpoint must be a clean HTTP or HTTPS URL without credentials, query parameters, or a fragment.');
    }

    $body = json_encode(['token' => $token], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $allowSelfSigned = cp_allow_self_signed_tls($db);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: UnrealFileCatalogFederation/1.0\r\n",
            'content' => $body,
            'timeout' => 60,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => !$allowSelfSigned,
            'verify_peer_name' => !$allowSelfSigned,
            'allow_self_signed' => $allowSelfSigned,
        ],
    ]);
    $response = @file_get_contents($endpoint, false, $context);
    if ($response === false) {
        $lastError = error_get_last();
        $detail = is_array($lastError) ? trim((string)($lastError['message'] ?? '')) : '';
        throw new RuntimeException('Could not submit the claim request' . ($detail !== '' ? ': ' . $detail : '.'));
    }
    $json = json_decode($response, true);
    if (!is_array($json)) {
        throw new RuntimeException('Claim endpoint did not return valid JSON.');
    }
    return $json;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_claim_parent');
        $claimEndpoint = rtrim(trim((string)($_POST['claim_endpoint'] ?? '')), '/');
        $claimToken = trim((string)($_POST['claim_token'] ?? ''));
        if ($claimEndpoint === '' || $claimToken === '') {
            throw new RuntimeException('Claim endpoint and token are required.');
        }
        if ((string)fed_setting($db, 'require_https_for_remote_sites', '1') === '1' && !str_starts_with(strtolower($claimEndpoint), 'https://')) {
            throw new RuntimeException('HTTPS is required by local federation settings.');
        }

        $result = cp_post_claim($db, $claimEndpoint, $claimToken);
        if (empty($result['ok']) || empty($result['parent']) || !is_array($result['parent'])) {
            throw new RuntimeException('Parent rejected claim: ' . ($result['error'] ?? 'unknown error'));
        }

        $parent = $result['parent'];
        $siteName = trim((string)($parent['site_name'] ?? ''));
        $siteUrl = rtrim(trim((string)($parent['site_url'] ?? '')), '/');
        $siteId = trim((string)($parent['site_id'] ?? ''));
        $fingerprint = strtoupper(trim((string)($parent['site_fingerprint'] ?? '')));
        $secret = trim((string)($parent['shared_secret'] ?? ''));

        if ($siteName === '' || $siteUrl === '' || $siteId === '' || $fingerprint === '' || $secret === '') {
            throw new RuntimeException('Claim result missing parent pairing values.');
        }
        $expected = fed_site_fingerprint($siteUrl, $siteId);
        if (!hash_equals($expected, $fingerprint)) {
            throw new RuntimeException('Parent fingerprint does not match parent URL/site ID.');
        }
        $secretFields = fed_prepare_peer_secret($secret);

        $existing = catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE peer_site_id=? LIMIT 1', [$siteId]);
        if ($existing) {
            $db->prepare('UPDATE ue_federation_peers SET peer_role="parent", site_name=?, site_url=?, peer_fingerprint=?, shared_secret_hash=?, shared_secret_plain=?, is_active=1 WHERE peer_site_id=?')->execute([$siteName, $siteUrl, $fingerprint, $secretFields['hash'], $secretFields['stored'], $siteId]);
            $peerId = (int)$existing['id'];
        } else {
            $permissions = [
                'allow_parent_pull_from_child' => true,
                'allow_child_request_from_parent' => true,
                'created_by_join_claim' => true,
            ];
            $stmt = $db->prepare('INSERT INTO ue_federation_peers(peer_role, site_name, site_url, peer_site_id, peer_fingerprint, shared_secret_hash, shared_secret_plain, permissions_json, is_active) VALUES("parent",?,?,?,?,?,?,?,1)');
            $stmt->execute([$siteName, $siteUrl, $siteId, $fingerprint, $secretFields['hash'], $secretFields['stored'], json_encode($permissions, JSON_UNESCAPED_SLASHES)]);
            $peerId = (int)$db->lastInsertId();
        }

        fed_set_setting($db, 'main_parent_url', $siteUrl);
        fed_set_setting($db, 'site_role', 'child');
        fed_set_setting($db, 'child_enabled', '1');
        fed_set_setting($db, 'parent_enabled', '0');
        fed_set_setting($db, 'join_requests_enabled', '0');
        fed_log($db, $peerId, null, 'INFO', 'PARENT_CLAIMED', 'Parent pairing claimed from approved join request. Child role enforced.');
        $_SESSION['fed_claim_parent_flash'] = 'Parent claimed and paired successfully: ' . $siteName . '. This deployment is now configured as a child.';
        header('Location: claim-parent.php');
        exit;
    }

    if (!catalog_require_admin_page('Claim Parent Pairing')) {
        exit;
    }

    catalog_head('Claim Parent Pairing');
    catalog_flash($_SESSION['fed_claim_parent_flash'] ?? null);
    unset($_SESSION['fed_claim_parent_flash']);

    $allowSelfSigned = cp_allow_self_signed_tls($db);
    catalog_page_header('Claim Parent Pairing', 'Enter the POST endpoint and one-time token supplied by the parent administrator after approving the join request.', catalog_federation_links() + ['Peers' => 'peers.php', 'Settings' => 'settings.php']);
    if ($allowSelfSigned) {
        echo CatalogUi::alert('warning', 'Self-signed federation certificates are allowed. Certificate trust and hostname verification are disabled for this claim request.', 'Testing TLS mode enabled');
    }
    echo '<div class="card"><h2>Claim approved parent</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_claim_parent')) . '"><p><label>Claim POST endpoint<br><input name="claim_endpoint" required style="min-width:760px" placeholder="https://parent.example.com/catalog/api/federation/join-claim.php"></label></p><p><label>One-time claim token<br><input name="claim_token" required autocomplete="off" style="min-width:520px"></label></p><p><button>Claim parent and create pairing</button></p></form></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Claim parent error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
