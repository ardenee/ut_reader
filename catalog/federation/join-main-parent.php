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
        throw new RuntimeException('main_parent_url is not set. Set it in federation settings first.');
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
            fed_set_setting($db, 'main_parent_join_status', (string)($result['status'] ?? 'unknown'));
            if (($result['status'] ?? '') === 'claimed') {
                fed_set_setting($db, 'main_parent_join_request_token', '');
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

    catalog_page_header('Join Main Federation Parent', 'Submit local identity and poll the main parent for approval. Pairing credentials are never returned by status polling; after approval, enter the separately supplied POST endpoint and one-time token on Claim Parent.', catalog_federation_links() + ['Settings' => 'settings.php', 'Peers' => 'peers.php', 'Claim Parent' => 'claim-parent.php']);
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
    echo '<div class="card"><h2>Step 2: poll approval status</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_join_main_parent')) . '"><input type="hidden" name="action" value="poll"><p><button>Poll parent approval status</button></p></form><p class="muted">When approved, obtain the one-time claim endpoint and token from the parent administrator and open <a href="claim-parent.php">Claim Parent</a>.</p></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Join main parent error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
