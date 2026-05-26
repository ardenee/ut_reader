<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';

function cp_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function cp_csrf(): string
{
    $_SESSION['fed_claim_parent_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['fed_claim_parent_csrf'];
}

function cp_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['fed_claim_parent_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function cp_fetch_json(string $url): array
{
    $context = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 60, 'ignore_errors' => true],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('Could not fetch claim URL.');
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Claim URL did not return JSON: ' . substr($body, 0, 300));
    }
    return $json;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!cp_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        cp_check_csrf();
        $claimUrl = trim((string)($_POST['claim_url'] ?? ''));
        if ($claimUrl === '') {
            throw new RuntimeException('Claim URL is required.');
        }
        if ((string)fed_setting($db, 'require_https_for_remote_sites', '1') === '1' && !str_starts_with(strtolower($claimUrl), 'https://')) {
            throw new RuntimeException('HTTPS is required by local federation settings.');
        }

        $result = cp_fetch_json($claimUrl);
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

        $existing = catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE peer_site_id=? LIMIT 1', [$siteId]);
        if ($existing) {
            $db->prepare('UPDATE ue_federation_peers SET peer_role="parent", site_name=?, site_url=?, peer_fingerprint=?, shared_secret_hash=?, shared_secret_plain=?, is_active=1 WHERE peer_site_id=?')->execute([$siteName, $siteUrl, $fingerprint, password_hash($secret, PASSWORD_DEFAULT), $secret, $siteId]);
            $peerId = (int)$existing['id'];
        } else {
            $permissions = [
                'allow_parent_pull_from_child' => true,
                'allow_child_request_from_parent' => true,
                'created_by_join_claim' => true,
            ];
            $stmt = $db->prepare('INSERT INTO ue_federation_peers(peer_role, site_name, site_url, peer_site_id, peer_fingerprint, shared_secret_hash, shared_secret_plain, permissions_json, is_active) VALUES("parent",?,?,?,?,?,?,?,1)');
            $stmt->execute([$siteName, $siteUrl, $siteId, $fingerprint, password_hash($secret, PASSWORD_DEFAULT), $secret, json_encode($permissions, JSON_UNESCAPED_SLASHES)]);
            $peerId = (int)$db->lastInsertId();
        }

        fed_set_setting($db, 'site_role', 'child');
        fed_set_setting($db, 'child_enabled', '1');
        fed_log($db, $peerId, null, 'INFO', 'PARENT_CLAIMED', 'Parent pairing claimed from approved join request.');
        $_SESSION['fed_claim_parent_flash'] = 'Parent claimed and paired successfully: ' . $siteName;
        header('Location: claim-parent.php');
        exit;
    }

    catalog_head('Claim Parent Pairing');

    if (!cp_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="../index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    if (isset($_SESSION['fed_claim_parent_flash'])) {
        echo '<div class="card"><strong>' . catalog_h($_SESSION['fed_claim_parent_flash']) . '</strong></div>';
        unset($_SESSION['fed_claim_parent_flash']);
    }

    echo '<div class="card"><h1>Claim Parent Pairing</h1><p class="muted">Use the one-time claim URL provided by the master/parent admin after approving your join request.</p><p><a class="button" href="admin.php">Federation admin</a> <a class="button" href="peers.php">Peers</a> <a class="button" href="settings.php">Settings</a></p></div>';
    echo '<div class="card"><h2>Claim approved parent</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(cp_csrf()) . '"><p><label>One-time claim URL<br><input name="claim_url" required style="min-width:760px" placeholder="https://parent.example.com/catalog/api/federation/join-claim.php?token=..."></label></p><p><button>Claim parent and create pairing</button></p></form></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Claim parent error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
