<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationPairing.php';

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
    $url = trim((string)fed_setting($db, 'main_parent_url', ''));
    if ($url === '') {
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

/** @return array<string,mixed> */
function jmp_post_json(PDO $db, string $url, array $payload): array
{
    $body = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    TrustedHttpSourceClient::configureFederationTesting(jmp_allow_self_signed_tls($db));
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

/** @param array<string,mixed> $result */
function jmp_store_join_status(PDO $db, array $result): void
{
    $status = strtolower(trim((string)($result['status'] ?? 'unknown')));
    fed_set_setting($db, 'main_parent_join_status', $status !== '' ? $status : 'unknown');
    fed_set_setting($db, 'main_parent_join_status_message', trim((string)($result['message'] ?? '')));
    fed_set_setting($db, 'main_parent_join_admin_notes', trim((string)($result['admin_notes'] ?? '')));
}

/** @return array<string,mixed> */
function jmp_poll_parent_status(PDO $db, array $identity): array
{
    $parentUrl = jmp_parent_url($db);
    $requestId = (int)(fed_setting($db, 'main_parent_join_request_id', '0') ?: 0);
    $requestToken = (string)fed_setting($db, 'main_parent_join_request_token', '');
    if ($requestId <= 0) {
        throw new RuntimeException('No stored parent join request. Submit first.');
    }

    $localParent = catalog_one(
        $db,
        'SELECT id,site_name,site_url,peer_site_id FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY id LIMIT 1'
    );
    if ($localParent && $requestToken === '') {
        $result = [
            'ok' => true,
            'request_id' => $requestId,
            'status' => 'claimed',
            'message' => 'Parent pairing is connected.',
            'peer_id' => (int)$localParent['id'],
        ];
        jmp_store_join_status($db, $result);
        return $result;
    }
    if ($requestToken === '') {
        throw new RuntimeException('Stored parent join token is unavailable. Submit a new request.');
    }

    $previousStatus = strtolower(trim((string)fed_setting($db, 'main_parent_join_status', 'none')));
    $result = jmp_post_json($db, $parentUrl . '/api/federation/join-request-status.php', [
        'request_id' => $requestId,
        'site_id' => (string)$identity['site_id'],
        'request_token' => $requestToken,
    ]);
    if (empty($result['ok'])) {
        throw new RuntimeException('Parent status check failed: ' . ($result['error'] ?? 'unknown error'));
    }

    $status = strtolower(trim((string)($result['status'] ?? 'unknown')));
    if (in_array($status, ['approved', 'claimed'], true) && !empty($result['claim_ready'])) {
        $result = federation_auto_claim_parent($db, $parentUrl, $requestId, $requestToken);
        $status = 'claimed';
    } else {
        jmp_store_join_status($db, $result);
    }

    if ($status !== $previousStatus) {
        fed_log(
            $db,
            null,
            null,
            'INFO',
            'MAIN_PARENT_JOIN_STATUS_CHANGED',
            'Status changed from ' . $previousStatus . ' to ' . $status . ': ' . json_encode($result, JSON_UNESCAPED_SLASHES)
        );
    }

    return $result;
}

function jmp_default_status_message(string $status): string
{
    return match ($status) {
        'pending' => 'Waiting for parent admin approval.',
        'approved' => 'Approved. Automatic pairing is being completed.',
        'denied' => 'Join request denied by parent admin.',
        'claimed' => 'Parent pairing is connected.',
        'expired' => 'Join approval expired. Submit a new request.',
        default => 'No active parent join request.',
    };
}

function jmp_status_label(string $status): string
{
    return match ($status) {
        'pending' => 'Pending',
        'approved' => 'Approved',
        'denied' => 'Denied',
        'claimed' => 'Connected',
        'expired' => 'Expired',
        default => ucfirst($status !== '' ? $status : 'none'),
    };
}

function jmp_status_pill_class(string $status): string
{
    return match ($status) {
        'approved', 'claimed' => 'pill green',
        'denied', 'expired' => 'pill red',
        'pending' => 'pill amber',
        default => 'pill',
    };
}

$jmpWantsJson = false;

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = strtolower(trim((string)($_POST['action'] ?? 'submit')));
        $jmpWantsJson = $action === 'poll_json';

        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_join_main_parent');
        $identity = fed_ensure_identity($db);

        if ($action === 'submit') {
            $parentMode = strtolower(trim((string)($_POST['parent_mode'] ?? 'manual')));
            $parentUrl = jmp_validate_parent_url(
                $parentMode === 'official' ? JMP_OFFICIAL_PARENT_URL : (string)($_POST['parent_url'] ?? '')
            );

            $localUrl = rtrim(strtolower(trim((string)$identity['site_url'])), '/');
            if ($localUrl !== '' && hash_equals($localUrl, strtolower($parentUrl))) {
                throw new RuntimeException('This deployment cannot join itself as its own parent.');
            }

            $storedStatus = strtolower(trim((string)fed_setting($db, 'main_parent_join_status', 'none')));
            $storedParentUrl = rtrim(trim((string)fed_setting($db, 'main_parent_url', '')), '/');
            $storedToken = (string)fed_setting($db, 'main_parent_join_request_token', '');
            $requestToken = in_array($storedStatus, ['pending', 'approved'], true)
                && $storedParentUrl === $parentUrl
                && $storedToken !== ''
                    ? $storedToken
                    : fed_random_secret();

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
            jmp_store_join_status($db, $result);
            fed_log($db, null, null, 'INFO', 'MAIN_PARENT_JOIN_SUBMIT', 'Parent=' . $parentUrl . ' result=' . json_encode($result, JSON_UNESCAPED_SLASHES));

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

        if ($action === 'poll' || $action === 'poll_json') {
            $result = jmp_poll_parent_status($db, $identity);
            if ($action === 'poll_json') {
                fed_json_response($result);
            }
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
    $joinStatus = strtolower(trim((string)fed_setting($db, 'main_parent_join_status', 'none')));
    $joinMessage = trim((string)fed_setting($db, 'main_parent_join_status_message', ''));
    $joinAdminNotes = trim((string)fed_setting($db, 'main_parent_join_admin_notes', ''));
    $requestId = (string)fed_setting($db, 'main_parent_join_request_id', '');
    $manualUrl = $parentUrl !== JMP_OFFICIAL_PARENT_URL ? $parentUrl : '';
    $allowSelfSigned = jmp_allow_self_signed_tls($db);
    if ($joinMessage === '') {
        $joinMessage = jmp_default_status_message($joinStatus);
    }

    catalog_page_header(
        'Join Federation Parent',
        'Submit this child to a parent. After parent approval, pairing completes automatically without a separate claim step.',
        catalog_federation_links() + ['Settings' => 'settings.php', 'Peers' => 'peers.php']
    );

    if ($allowSelfSigned) {
        echo CatalogUi::alert('warning', 'Self-signed federation certificates are currently allowed for testing.', 'Testing TLS mode enabled');
    }

    echo '<div class="card" id="join-status-card" data-status="' . catalog_h($joinStatus) . '" data-request-id="' . catalog_h($requestId) . '">';
    echo '<h2>Parent approval status</h2>';
    echo '<p><span id="join-status-pill" class="' . catalog_h(jmp_status_pill_class($joinStatus)) . '">' . catalog_h(jmp_status_label($joinStatus)) . '</span></p>';
    echo '<p id="join-status-message">' . catalog_h($joinMessage) . '</p>';
    echo '<div id="join-admin-notes"' . ($joinAdminNotes === '' ? ' hidden' : '') . '><h3>Parent admin notes</h3><p id="join-admin-notes-text">' . catalog_h($joinAdminNotes) . '</p></div>';
    echo '<p class="muted" id="join-auto-poll-note">' . ($joinStatus === 'pending' || $joinStatus === 'approved' ? 'Automatically checking the parent every 15 seconds.' : 'Automatic checking stops when pairing reaches a final state.') . '</p>';
    echo '</div>';

    echo '<div class="card"><h2>Local identity</h2><table>';
    echo '<tr><th>Current site role</th><td>' . catalog_h($currentRole) . '</td></tr>';
    echo '<tr><th>Stored parent URL</th><td class="mono path">' . catalog_h($parentUrl ?: 'not set') . '</td></tr>';
    echo '<tr><th>Local site name</th><td>' . catalog_h($identity['site_name']) . '</td></tr>';
    echo '<tr><th>Local site URL</th><td class="mono path">' . catalog_h($identity['site_url']) . '</td></tr>';
    echo '<tr><th>Local site ID</th><td class="mono">' . catalog_h($identity['site_id']) . '</td></tr>';
    echo '<tr><th>Local fingerprint</th><td class="mono">' . catalog_h($identity['site_fingerprint']) . '</td></tr>';
    echo '<tr><th>Stored request ID</th><td class="mono">' . catalog_h($requestId ?: 'none') . '</td></tr>';
    echo '<tr><th>Stored status</th><td id="stored-join-status">' . catalog_h($joinStatus) . '</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Submit join request</h2><form method="post" id="federation-join-form">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_join_main_parent')) . '"><input type="hidden" name="action" value="submit">';
    echo '<fieldset><legend>Choose parent</legend>';
    echo '<p><label><input type="radio" name="parent_mode" value="official"' . ($parentUrl === '' || $parentUrl === JMP_OFFICIAL_PARENT_URL ? ' checked' : '') . '> <strong>Join official UnrealDB parent</strong><br><span class="mono">' . catalog_h(JMP_OFFICIAL_PARENT_URL) . '</span></label></p>';
    echo '<p><label><input type="radio" name="parent_mode" value="manual"' . ($parentUrl !== '' && $parentUrl !== JMP_OFFICIAL_PARENT_URL ? ' checked' : '') . '> <strong>Join another parent</strong></label><br><input id="manual-parent-url" name="parent_url" value="' . catalog_h($manualUrl) . '" style="min-width:680px" placeholder="https://parent.example.com/catalog"></p>';
    echo '</fieldset><p><label>Contact name<br><input name="contact_name" style="min-width:420px"></label></p><p><label>Contact email<br><input name="contact_email" style="min-width:420px"></label></p><p><label>Notes<br><textarea name="notes" rows="4" style="width:100%">Request to join this federation parent.</textarea></label></p><p><button>Submit join request</button></p></form></div>';

    echo '<div class="card"><h2>Check approval status</h2><form method="post" id="poll-status-form">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_join_main_parent')) . '"><input type="hidden" name="action" value="poll"><p><button id="poll-status-button"' . ($requestId === '' ? ' disabled' : '') . '>Check parent now</button></p></form><p class="muted">Pending and approved requests are checked automatically. Approval completes pairing without further child action.</p></div>';

    echo <<<'HTML'
<script>
(function () {
    const modes = document.querySelectorAll('input[name="parent_mode"]');
    const manual = document.getElementById('manual-parent-url');
    const pollForm = document.getElementById('poll-status-form');
    const pollButton = document.getElementById('poll-status-button');
    const card = document.getElementById('join-status-card');
    const pill = document.getElementById('join-status-pill');
    const message = document.getElementById('join-status-message');
    const notesWrap = document.getElementById('join-admin-notes');
    const notesText = document.getElementById('join-admin-notes-text');
    const autoNote = document.getElementById('join-auto-poll-note');
    const storedStatus = document.getElementById('stored-join-status');
    let timer = null;
    let polling = false;

    function syncParentMode() {
        const selected = document.querySelector('input[name="parent_mode"]:checked');
        const enabled = selected && selected.value === 'manual';
        manual.disabled = !enabled;
        manual.required = Boolean(enabled);
    }

    function presentation(status) {
        const value = String(status || 'unknown').toLowerCase();
        const labels = {pending:'Pending',approved:'Approved',denied:'Denied',claimed:'Connected',expired:'Expired'};
        const classes = {pending:'pill amber',approved:'pill green',denied:'pill red',claimed:'pill green',expired:'pill red'};
        return {status:value,label:labels[value] || value,className:classes[value] || 'pill'};
    }

    function stopPolling() {
        if (timer !== null) window.clearTimeout(timer);
        timer = null;
    }

    function schedulePoll(delay) {
        stopPolling();
        if (!['pending','approved'].includes(card.dataset.status) || document.hidden) return;
        timer = window.setTimeout(function () { void pollStatus(false); }, typeof delay === 'number' ? delay : 15000);
    }

    function renderStatus(result) {
        const view = presentation(result.status);
        card.dataset.status = view.status;
        pill.className = view.className;
        pill.textContent = view.label;
        message.textContent = result.message || 'Parent status received.';
        storedStatus.textContent = view.status;
        const notes = String(result.admin_notes || '').trim();
        notesText.textContent = notes;
        notesWrap.hidden = notes === '';
        if (['pending','approved'].includes(view.status)) {
            autoNote.textContent = view.status === 'approved' ? 'Approval received; completing pairing automatically…' : 'Automatically checking the parent every 15 seconds.';
            schedulePoll(view.status === 'approved' ? 2000 : 15000);
        } else {
            stopPolling();
            autoNote.textContent = view.status === 'claimed' ? 'Automatic pairing completed.' : 'Automatic checking stopped because the request is ' + view.status + '.';
        }
    }

    async function pollStatus(manualRequest) {
        if (polling || !pollForm || pollButton.disabled) return;
        polling = true;
        pollButton.disabled = true;
        if (manualRequest) autoNote.textContent = 'Checking the parent now…';
        const data = new FormData(pollForm);
        data.set('action', 'poll_json');
        try {
            const response = await fetch('join-main-parent.php', {method:'POST',body:data,credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            const text = await response.text();
            let result;
            try { result = JSON.parse(text); } catch (error) { throw new Error('Status check returned invalid JSON.'); }
            if (!response.ok || !result.ok) throw new Error(result.error || ('Status check failed with HTTP ' + response.status + '.'));
            renderStatus(result);
        } catch (error) {
            autoNote.textContent = 'Automatic status check failed: ' + (error instanceof Error ? error.message : String(error));
            if (['pending','approved'].includes(card.dataset.status)) schedulePoll(30000);
        } finally {
            polling = false;
            pollButton.disabled = card.dataset.requestId === '';
        }
    }

    modes.forEach(function (mode) { mode.addEventListener('change', syncParentMode); });
    syncParentMode();
    pollForm.addEventListener('submit', function (event) { event.preventDefault(); void pollStatus(true); });
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) stopPolling();
        else if (['pending','approved'].includes(card.dataset.status)) void pollStatus(false);
    });
    if (['pending','approved'].includes(card.dataset.status) && card.dataset.requestId !== '') schedulePoll(3000);
}());
</script>
HTML;

    catalog_foot();
} catch (Throwable $e) {
    if ($jmpWantsJson) {
        fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    if (!headers_sent()) {
        catalog_head('Join parent error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
