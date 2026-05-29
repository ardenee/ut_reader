<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';

function jmp_parent_url(PDO $db): string
{
    $url = rtrim((string)fed_setting($db, 'main_parent_url', ''), '/');
    if ($url === '') {
        throw new RuntimeException('main_parent_url is not set. Set it in federation settings or install the update SQL.');
    }
    return $url;
}

function jmp_post_json(string $url, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        throw new RuntimeException('Could not encode JSON.');
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nUser-Agent: UnrealFileCatalogFederation/1.0\r\n",
            'content' => $body,
            'timeout' => 60,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        throw new RuntimeException('POST failed: ' . $url);
    }
    $json = json_decode($response, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON response: ' . substr($response, 0, 300));
    }
    return $json;
}

function jmp_configure_parent(PDO $db, array $parent): int
{
    $siteName = trim((string)($parent['site_name'] ?? ''));
    $siteUrl = rtrim(trim((string)($parent['site_url'] ?? '')), '/');
    $siteId = trim((string)($parent['site_id'] ?? ''));
    $fingerprint = strtoupper(trim((string)($parent['site_fingerprint'] ?? '')));
    $secret = trim((string)($parent['shared_secret'] ?? ''));

    if ($siteName === '' || $siteUrl === '' || $siteId === '' || $fingerprint === '' || $secret === '') {
        throw new RuntimeException('Approved parent response is missing pairing values.');
    }
    $expected = fed_site_fingerprint($siteUrl, $siteId);
    if (!hash_equals($expected, $fingerprint)) {
        throw new RuntimeException('Parent fingerprint does not match parent URL/site ID.');
    }

    $existing = catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE peer_site_id=? LIMIT 1', [$siteId]);
    if ($existing) {
        $db->prepare('UPDATE ue_federation_peers SET peer_role="parent", site_name=?, site_url=?, peer_fingerprint=?, shared_secret_hash=?, shared_secret_plain=?, is_active=1 WHERE peer_site_id=?')->execute([$siteName, $siteUrl, $fingerprint, password_hash($secret, PASSWORD_DEFAULT), $secret, $siteId]);
        return (int)$existing['id'];
    }

    $permissions = ['allow_parent_pull_from_child' => true, 'allow_child_request_from_parent' => true, 'created_by_main_parent_auto_join' => true];
    $stmt = $db->prepare('INSERT INTO ue_federation_peers(peer_role, site_name, site_url, peer_site_id, peer_fingerprint, shared_secret_hash, shared_secret_plain, permissions_json, is_active) VALUES("parent",?,?,?,?,?,?,?,1)');
    $stmt->execute([$siteName, $siteUrl, $siteId, $fingerprint, password_hash($secret, PASSWORD_DEFAULT), $secret, json_encode($permissions, JSON_UNESCAPED_SLASHES)]);
    return (int)$db->lastInsertId();
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_join_main_parent');
        $action = (string)($_POST['action'] ?? 'submit');
        $parentUrl = jmp_parent_url($db);
        $identity = fed_ensure_identity($db);

        if ($action === 'submit') {
            $requestToken = fed_random_secret();
            $payload = [
                'site_name' => (string)$identity['site_name'],
                'site_url' => (string)$identity['site_url'],
                'site_id' => (string)$identity['site_id'],
                'site_fingerprint' => (string)$identity['site_fingerprint'],
                'request_token' => $requestToken,
                'contact_name' => trim((string)($_POST['contact_name'] ?? '')),
                'contact_email' => trim((string)($_POST['contact_email'] ?? '')),
                'notes' => trim((string)($_POST['notes'] ?? 'Automatic join request to main parent.')),
            ];
            if ($payload['site_name'] === '' || $payload['site_url'] === '' || $payload['site_fingerprint'] === '') {
                throw new RuntimeException('Local federation identity is incomplete. Set site_name and site_url in federation settings first.');
            }
            $result = jmp_post_json($parentUrl . '/api/federation/join-request-submit.php', $payload);
            if (empty($result['ok'])) {
                throw new RuntimeException('Parent rejected join request: ' . ($result['error'] ?? 'unknown error'));
            }
            fed_set_setting($db, 'main_parent_join_request_id', (string)($result['request_id'] ?? '0'));
            fed_set_setting($db, 'main_parent_join_request_token', $requestToken);
            fed_set_setting($db, 'main_parent_join_status', (string)($result['status'] ?? 'pending'));
            fed_log($db, null, null, 'INFO', 'MAIN_PARENT_JOIN_SUBMIT', json_encode($result, JSON_UNESCAPED_SLASHES));
            $_SESSION['fed_join_main_result'] = $result;
            header('Location: join-main-parent.php');
            exit;
        }

        if ($action === 'poll') {
            $requestId = (int)(fed_setting($db, 'main_parent_join_request_id', '0') ?: 0);
            $requestToken = (string)fed_setting($db, 'main_parent_join_request_token', '');
            if ($requestId <= 0 || $requestToken === '') {
                throw new RuntimeException('No stored main parent join request. Submit first.');
            }
            $result = jmp_post_json($parentUrl . '/api/federation/join-request-status.php', [
                'request_id' => $requestId,
                'site_id' => (string)$identity['site_id'],
                'request_token' => $requestToken,
            ]);
            if (!empty($result['parent']) && is_array($result['parent'])) {
                $peerId = jmp_configure_parent($db, $result['parent']);
                fed_set_setting($db, 'site_role', 'child');
                fed_set_setting($db, 'child_enabled', '1');
                fed_set_setting($db, 'main_parent_join_status', 'claimed');
                fed_set_setting($db, 'main_parent_join_request_token', '');
                $result['local_parent_peer_id'] = $peerId;
            } else {
                fed_set_setting($db, 'main_parent_join_status', (string)($result['status'] ?? 'unknown'));
            }
            fed_log($db, null, null, 'INFO', 'MAIN_PARENT_JOIN_POLL', json_encode($result, JSON_UNESCAPED_SLASHES));
            $_SESSION['fed_join_main_result'] = $result;
            header('Location: join-main-parent.php');
            exit;
        }
    }

    if (!catalog_require_admin_page('Join Main Federation Parent')) {
        exit;
    }

    catalog_head('Join Main Federation Parent');

    if (isset($_SESSION['fed_join_main_result'])) {
        echo '<div class="card"><h2>Last result</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_join_main_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></div>';
        unset($_SESSION['fed_join_main_result']);
    }

    $identity = fed_ensure_identity($db);
    $parentUrl = (string)fed_setting($db, 'main_parent_url', '');
    $joinStatus = (string)fed_setting($db, 'main_parent_join_status', 'none');
    $requestId = (string)fed_setting($db, 'main_parent_join_request_id', '');

    catalog_page_header('Join Main Federation Parent', 'Easy child setup for the hardcoded/main parent. This auto-submits your local identity, polls for approval, and configures the parent peer when approved.', catalog_federation_links() + ['Settings' => 'settings.php', 'Peers' => 'peers.php']);
	echo '<div class="card"><h2>Local identity sent to parent</h2><table>';
    echo '<tr><th>Main parent URL</th><td class="mono path">' . catalog_h($parentUrl) . '</td></tr>';
    echo '<tr><th>Local site name</th><td>' . catalog_h($identity['site_name']) . '</td></tr>';
    echo '<tr><th>Local site URL</th><td class="mono path">' . catalog_h($identity['site_url']) . '</td></tr>';
    echo '<tr><th>Local site ID</th><td class="mono">' . catalog_h($identity['site_id']) . '</td></tr>';
    echo '<tr><th>Local fingerprint</th><td class="mono">' . catalog_h($identity['site_fingerprint']) . '</td></tr>';
    echo '<tr><th>Stored request ID</th><td class="mono">' . catalog_h($requestId) . '</td></tr>';
    echo '<tr><th>Stored status</th><td>' . catalog_h($joinStatus) . '</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Step 1: submit to main parent</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_join_main_parent')) . '"><input type="hidden" name="action" value="submit"><p><label>Contact name<br><input name="contact_name" style="min-width:420px"></label></p><p><label>Contact email<br><input name="contact_email" style="min-width:420px"></label></p><p><label>Notes<br><textarea name="notes" rows="4" style="width:100%">Automatic request to join main federation parent.</textarea></label></p><p><button>Submit / resubmit join request to main parent</button></p></form></div>';
    echo '<div class="card"><h2>Step 2: poll approval and auto-claim</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_join_main_parent')) . '"><input type="hidden" name="action" value="poll"><p><button>Poll parent and auto-connect if approved</button></p></form></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Join main parent error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
