<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

const JMP_OFFICIAL_PARENT_URL = 'https://unrealdb.com';

function jmp_validate_parent_url(string $url): string
{
    $url = rtrim(trim($url), '/');
    $parts = parse_url($url);
    if (!is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || trim((string)($parts['host'] ?? '')) === ''
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        throw new RuntimeException('Parent URL must be a plain HTTPS URL without credentials, query parameters, or a fragment.');
    }
    return $url;
}

function jmp_parent_url(PDO $db): string
{
    $url = (string)fed_setting($db, 'main_parent_url', '');
    if (trim($url) === '') {
        throw new RuntimeException('No parent URL is stored. Submit a join request first.');
    }
    return jmp_validate_parent_url($url);
}

function jmp_apply_child_role(PDO $db, string $parentUrl): void
{
    fed_set_setting($db, 'main_parent_url', $parentUrl);
    fed_set_setting($db, 'site_role', 'child');
    fed_set_setting($db, 'child_enabled', '1');
    fed_set_setting($db, 'parent_enabled', '0');
    fed_set_setting($db, 'join_requests_enabled', '0');
}

function jmp_allow_self_signed_tls(PDO $db): bool
{
    return (string)fed_setting($db, 'allow_self_signed_federation_certificates', '0') === '1';
}

function jmp_post_json(PDO $db, string $url, array $payload): array
{
    try {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $error) {
        throw new RuntimeException('Could not encode federation join request.', 0, $error);
    }

    $allowSelfSigned = jmp_allow_self_signed_tls($db);
    TrustedHttpSourceClient::configureFederationTesting($allowSelfSigned);

    try {
        return TrustedHttpSourceClient::postJson(
            $url,
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: UnrealFileCatalogFederation/2.0',
            ],
            $body,
            1048576,
            60
        );
    } catch (Throwable $error) {
        throw new RuntimeException('POST failed: ' . $url . ' — ' . $error->getMessage(), 0, $error);
    }
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_join_main_parent');
        $action = strtolower(trim((string)($_POST['action'] ?? 'submit')));
        $identity = fed_ensure_identity($db);

        if ($action === 'submit') {
            $parentMode = strtolower(trim((string)($_POST['parent_mode'] ?? 'manual')));
            $parentUrl = $parentMode === 'official'
                ? JMP_OFFICIAL_PARENT_URL
                : (string)($_POST['parent_url'] ?? '');
            $parentUrl = jmp_validate_parent_url($parentUrl);

            $localUrl = rtrim(strtolower(trim((string)$identity['site_url'])), '/');
            if ($localUrl !== '' && hash_equals($localUrl, strtolower($parentUrl))) {
                throw new RuntimeException('This deployment cannot join itself as its own parent.');
            }

            $requestToken = fed_random_secret();
            $payload = [
                'site_name' => (string)$identity['site_name'],
                'site_url' => (string)$identity['site_url'],
                'site_id' => (string)$identity['site_id'],
                'site_fingerprint' => (string)$identity['site_fingerprint'],
                'request_token' => $requestToken,
                'contact_name' => trim((string)($_POST['contact_name'] ?? '')),
                'contact_email' => trim((string)($_POST['contact_email'] ?? '')),
                'notes' => trim((string)($_POST['notes'] ?? 'Automatic join request to parent.')),
                'self_signed_tls_requested' => jmp_allow_self_signed_tls($db),
            ];
            if ($payload['site_name'] === '' || $payload['site_url'] === '' || $payload['site_fingerprint'] === '') {
                throw new RuntimeException('Local federation identity is incomplete. Set site_name and site_url in federation settings first.');
            }

            $result = jmp_post_json($db, $parentUrl . '/api/federation/join-request-submit.php', $payload);
            if (empty($result['ok'])) {
                throw new RuntimeException('Parent rejected join request: ' . ($result['error'] ?? 'unknown error'));
            }

            jmp_apply_child_role($db, $parentUrl);
            fed_set_setting($db, 'main_parent_join_request_id', (string)($result['request_id'] ?? '0'));
            fed_set_setting($db, 'main_parent_join_request_token', $requestToken);
            fed_set_setting($db, 'main_parent_join_status', (string)($result['status'] ?? 'pending'));
            fed_log(
                $db,
                null,
                null,
                'INFO',
                'MAIN_PARENT_JOIN_SUBMIT',
                'Parent=' . $parentUrl . ' result=' . json_encode($result, JSON_UNESCAPED_SLASHES)
            );
            $result['local_role'] = 'child';
            $result['parent_url'] = $parentUrl;
            $result['settings_updated'] = [
                'child_enabled' => '1',
                'parent_enabled' => '0',
                'join_requests_enabled' => '0',
            ];
            $_SESSION['fed_join_main_result'] = $result;
            header('Location: join-main-parent.php');
            exit;
        }

        if ($action === 'poll') {
            $parentUrl = jmp_parent_url($db);
            $requestId = (int)(fed_setting($db, 'main_parent_join_request_id', '0') ?: 0);
            $requestToken = (string)fed_setting($db, 'main_parent_join_request_token', '');
            if ($requestId <= 0 || $requestToken === '') {
                throw new RuntimeException('No stored parent join request. Submit first.');
            }
            $result = jmp_post_json($db, $parentUrl . '/api/federation/join-request-status.php', [
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

        throw new RuntimeException('Unknown join action.');
    }

    if (!catalog_require_admin_page('Join Federation Parent')) {
        exit;
    }

    catalog_head('Join Federation Parent');

    if (isset($_SESSION['fed_join_main_result'])) {
        echo '<div class="card"><h2>Last result</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_join_main_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></div>';
        unset($_SESSION['fed_join_main_result']);
    }

    $identity = fed_ensure_identity($db);
    $currentRole = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    $parentUrl = rtrim((string)fed_setting($db, 'main_parent_url', ''), '/');
    $joinStatus = (string)fed_setting($db, 'main_parent_join_status', 'none');
    $requestId = (string)fed_setting($db, 'main_parent_join_request_id', '');
    $manualUrl = $parentUrl !== JMP_OFFICIAL_PARENT_URL ? $parentUrl : '';
    $allowSelfSigned = jmp_allow_self_signed_tls($db);

    catalog_page_header(
        'Join Federation Parent',
        'Choose the official UnrealDB parent or enter another federation parent URL. Local identity values are generated and submitted automatically.',
        catalog_federation_links() + ['Settings' => 'settings.php', 'Peers' => 'peers.php', 'Claim Parent' => 'claim-parent.php']
    );

    if ($allowSelfSigned) {
        echo CatalogUi::alert(
            'warning',
            'Self-signed federation certificates are currently allowed. Certificate trust and hostname verification are disabled for outbound join requests. Use this only for development or testing.',
            'Testing TLS mode enabled'
        );
    }

    if ($currentRole === 'parent') {
        echo CatalogUi::alert(
            'warning',
            'This deployment is currently configured as a parent. Continuing will change its site role to child, enable child features, disable parent features, and stop accepting public child join requests.',
            'Role will change'
        );
    } elseif ($currentRole !== 'child') {
        echo CatalogUi::alert(
            'info',
            'Continuing will configure this deployment as a child and disable parent-only join features.',
            'Child role will be enabled'
        );
    }

    echo '<div class="card"><h2>Local identity sent automatically</h2><table>';
    echo '<tr><th>Current site role</th><td>' . catalog_h($currentRole) . '</td></tr>';
    echo '<tr><th>Stored parent URL</th><td class="mono path">' . catalog_h($parentUrl ?: 'not set') . '</td></tr>';
    echo '<tr><th>Local site name</th><td>' . catalog_h($identity['site_name']) . '</td></tr>';
    echo '<tr><th>Local site URL</th><td class="mono path">' . catalog_h($identity['site_url']) . '</td></tr>';
    echo '<tr><th>Local site ID</th><td class="mono">' . catalog_h($identity['site_id']) . '</td></tr>';
    echo '<tr><th>Local fingerprint</th><td class="mono">' . catalog_h($identity['site_fingerprint']) . '</td></tr>';
    echo '<tr><th>Stored request ID</th><td class="mono">' . catalog_h($requestId ?: 'none') . '</td></tr>';
    echo '<tr><th>Stored status</th><td>' . catalog_h($joinStatus) . '</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Submit join request</h2>';
    echo '<form method="post" id="federation-join-form">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_join_main_parent')) . '">';
    echo '<input type="hidden" name="action" value="submit">';
    echo '<fieldset><legend>Choose parent</legend>';
    echo '<p><label><input type="radio" name="parent_mode" value="official"' . ($parentUrl === '' || $parentUrl === JMP_OFFICIAL_PARENT_URL ? ' checked' : '') . '> <strong>Join official UnrealDB parent</strong><br><span class="mono">' . catalog_h(JMP_OFFICIAL_PARENT_URL) . '</span></label></p>';
    echo '<p><label><input type="radio" name="parent_mode" value="manual"' . ($parentUrl !== '' && $parentUrl !== JMP_OFFICIAL_PARENT_URL ? ' checked' : '') . '> <strong>Join another parent</strong></label><br>';
    echo '<input id="manual-parent-url" name="parent_url" value="' . catalog_h($manualUrl) . '" style="min-width:680px" placeholder="https://parent.example.com/catalog"></p>';
    echo '</fieldset>';
    echo '<p><label>Contact name<br><input name="contact_name" style="min-width:420px"></label></p>';
    echo '<p><label>Contact email<br><input name="contact_email" style="min-width:420px"></label></p>';
    echo '<p><label>Notes<br><textarea name="notes" rows="4" style="width:100%">Request to join this federation parent.</textarea></label></p>';
    echo '<p><button>Continue and submit join request</button></p>';
    echo '<p class="muted">A successful submission automatically changes this deployment to child role and stores the selected parent URL.</p>';
    echo '</form></div>';

    echo '<div class="card"><h2>Poll approval status</h2><form method="post">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_join_main_parent')) . '">';
    echo '<input type="hidden" name="action" value="poll">';
    echo '<p><button' . ($requestId === '' ? ' disabled' : '') . '>Poll parent approval status</button></p>';
    echo '</form><p class="muted">After approval, obtain the one-time claim endpoint and token from the parent administrator and open <a href="claim-parent.php">Claim Parent</a>.</p></div>';

    echo '<script>(function(){const modes=document.querySelectorAll(\'input[name="parent_mode"]\');const manual=document.getElementById("manual-parent-url");function sync(){const selected=document.querySelector(\'input[name="parent_mode"]:checked\');const enabled=selected&&selected.value==="manual";manual.disabled=!enabled;manual.required=Boolean(enabled);}modes.forEach(function(mode){mode.addEventListener("change",sync);});sync();})();</script>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Join parent error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
