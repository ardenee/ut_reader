<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

function settings_csrf(): string
{
    $_SESSION['fed_settings_csrf'] ??= bin2hex(random_bytes(16));
    return (string)$_SESSION['fed_settings_csrf'];
}

function settings_check_csrf(): void
{
    $actual = (string)($_POST['csrf'] ?? '');
    $expected = (string)($_SESSION['fed_settings_csrf'] ?? '');
    if ($actual === '' || $expected === '' || !hash_equals($expected, $actual)) {
        throw new RuntimeException('Bad CSRF token.');
    }
}

function settings_apply_role(PDO $db, string $role): void
{
    if (!in_array($role, ['standalone', 'parent', 'child'], true)) {
        throw new RuntimeException('Invalid federation site role.');
    }

    fed_set_setting($db, 'site_role', $role);
    if ($role === 'parent') {
        fed_set_setting($db, 'parent_enabled', '1');
        fed_set_setting($db, 'child_enabled', '0');
    } elseif ($role === 'child') {
        fed_set_setting($db, 'parent_enabled', '0');
        fed_set_setting($db, 'child_enabled', '1');
        fed_set_setting($db, 'join_requests_enabled', '0');
    } else {
        fed_set_setting($db, 'parent_enabled', '0');
        fed_set_setting($db, 'child_enabled', '0');
        fed_set_setting($db, 'join_requests_enabled', '0');
    }
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required.');
        }
        settings_check_csrf();

        $siteRole = strtolower(trim((string)($_POST['site_role'] ?? 'standalone')));
        if (!in_array($siteRole, ['standalone', 'parent', 'child'], true)) {
            throw new RuntimeException('Invalid federation site role.');
        }

        $allowed = [
            'site_name', 'site_url',
            'max_download_kbps', 'max_upload_kbps',
            'delay_between_downloads_seconds', 'delay_between_uploads_seconds',
            'max_files_per_transfer_run', 'max_transfer_file_size_mb',
            'auto_import_downloads', 'require_https_for_remote_sites',
            'allow_self_signed_federation_certificates',
            'api_nonce_ttl_seconds', 'transfer_token_ttl_seconds', 'log_retention_days',
            'cron_worker_enabled', 'cron_worker_token',
            'inventory_sync_interval_hours',
            'public_download_mode', 'external_mirror_auto_queue', 'external_mirror_expiry_days',
            'external_mirror_require_admin_approval', 'external_mirror_max_file_size_mb',
            'join_requests_enabled', 'join_claim_token_ttl_seconds', 'main_parent_url',
            'game_file_display_limit',
        ];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $_POST)) {
                $value = trim((string)$_POST[$key]);
                if ($key === 'inventory_sync_interval_hours') {
                    $value = (string)max(0, min(720, (int)$value));
                }
                fed_set_setting($db, $key, $value);
            }
        }

        settings_apply_role($db, $siteRole);
        fed_ensure_identity($db, trim((string)($_POST['site_url'] ?? '')), trim((string)($_POST['site_name'] ?? '')));
        fed_log($db, null, null, 'INFO', 'SETTINGS_SAVE', 'Federation settings updated for role ' . $siteRole . '.');
        $_SESSION['fed_settings_flash'] = 'Settings saved. Federation authority is enforced by site role.';
        header('Location: settings.php');
        exit;
    }

    if (!catalog_require_admin_page('Federation Settings')) {
        exit;
    }

    $currentRole = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    settings_apply_role($db, $currentRole);

    catalog_head('Federation Settings');
    catalog_flash($_SESSION['fed_settings_flash'] ?? null);
    unset($_SESSION['fed_settings_flash']);

    $identity = fed_ensure_identity($db);
    $settings = fed_all_settings($db);
    $currentRole = strtolower(trim((string)($settings['site_role'] ?? 'standalone')));
    $isChild = $currentRole === 'child';
    $isParent = $currentRole === 'parent';

    catalog_page_header(
        'Federation Settings',
        'Configure identity, role, automatic inventory exchange, transfer limits, dependency downloads, and federation worker settings.',
        catalog_federation_links() + ['Join Parent' => 'join.php', 'Join Requests' => 'join-requests.php', 'Peers' => 'peers.php']
    );

    echo '<form method="post" id="federation-settings-form"><input type="hidden" name="csrf" value="' . catalog_h(settings_csrf()) . '">';

    echo '<div class="card"><h2>Site identity</h2><table>';
    echo '<tr><th>Site ID</th><td class="mono">' . catalog_h($identity['site_id']) . '</td></tr>';
    echo '<tr><th>Fingerprint</th><td class="mono">' . catalog_h($identity['site_fingerprint']) . '</td></tr>';
    echo '<tr><th>Site role</th><td><select id="federation-site-role" name="site_role">';
    foreach (['standalone', 'parent', 'child'] as $role) {
        echo '<option value="' . $role . '"' . ($currentRole === $role ? ' selected' : '') . '>' . $role . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th>Site name</th><td><input name="site_name" value="' . catalog_h($settings['site_name'] ?? '') . '" style="min-width:420px"></td></tr>';
    echo '<tr><th>Site URL</th><td><input name="site_url" value="' . catalog_h($settings['site_url'] ?? '') . '" style="min-width:640px" placeholder="https://example.com/catalog"></td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Fixed role authority</h2><table>';
    echo '<tr><th>Parent/master</th><td>May read child inventory and download any non-protected file absent from the parent. No child approval is required.</td></tr>';
    echo '<tr><th>Child</th><td>May download from the parent only when a file fulfils a local missing dependency and the parent has approved that request.</td></tr>';
    echo '<tr><th>Approved child downloads</th><td>Federation workers queue approved items only while the dependency is still missing.</td></tr>';
    echo '<tr><th>Current enforcement</th><td><strong>' . catalog_h($currentRole) . '</strong> — parent features ' . ($isParent ? 'enabled' : 'disabled') . '; child features ' . ($isChild ? 'enabled' : 'disabled') . '.</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Join and pairing</h2><table>';
    $joinEnabled = $isParent ? (string)($settings['join_requests_enabled'] ?? '1') : '0';
    echo '<tr><th>Accept public child join requests</th><td><select name="join_requests_enabled"' . (!$isParent ? ' disabled' : '') . '><option value="0"' . ($joinEnabled === '0' ? ' selected' : '') . '>No</option><option value="1"' . ($joinEnabled === '1' ? ' selected' : '') . '>Yes</option></select>' . (!$isParent ? ' <span class="muted">Available only on a parent.</span>' : '') . '</td></tr>';
    echo '<tr><th>Automatic pairing approval TTL, seconds</th><td><input name="join_claim_token_ttl_seconds"' . (!$isParent ? ' disabled' : '') . ' value="' . catalog_h($settings['join_claim_token_ttl_seconds'] ?? '86400') . '" style="width:160px"> <span class="muted">The child uses its original request token automatically; no token is copied manually.</span></td></tr>';
    echo '<tr><th>Main parent URL</th><td><input name="main_parent_url" value="' . catalog_h($settings['main_parent_url'] ?? '') . '" style="min-width:640px" placeholder="https://parent.example.com/catalog"> <a class="button" href="join.php">Join a parent</a></td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Inventory synchronization</h2><p>Each site pulls the current transferable inventory from its paired opposite role. Parent sites pull every child; child sites pull their parent.</p><table>';
    echo '<tr><th>Automatic inventory refresh interval, hours</th><td><input name="inventory_sync_interval_hours" type="number" min="0" max="720" value="' . catalog_h($settings['inventory_sync_interval_hours'] ?? '24') . '" style="width:120px"> <span class="muted">Default: 24 hours. Set 0 to disable automatic refresh. The federation worker checks whether each peer is due.</span></td></tr>';
    echo '<tr><th>Download authority</th><td>Inventory exchange only advertises availability. It does not allow a child to download arbitrary parent files; child transfers still require an approved missing-dependency request.</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Transfer behavior</h2><table>';
    foreach ([
        'auto_import_downloads' => ['Auto-import downloaded files after transfer', '1'],
        'require_https_for_remote_sites' => ['Require HTTPS for remote federation sites', '1'],
        'allow_self_signed_federation_certificates' => ['Allow self-signed federation certificates (testing only)', '0'],
    ] as $key => [$label, $default]) {
        $value = (string)($settings[$key] ?? $default);
        echo '<tr><th>' . catalog_h($label) . '</th><td><select name="' . $key . '"><option value="0"' . ($value === '0' ? ' selected' : '') . '>No</option><option value="1"' . ($value === '1' ? ' selected' : '') . '>Yes</option></select>';
        if ($key === 'allow_self_signed_federation_certificates') {
            echo ' <span class="muted">Disables certificate trust and hostname verification for testing only.</span>';
        }
        echo '</td></tr>';
    }
    echo '</table></div>';

    echo '<div class="card"><h2>Transfer limits</h2><p class="muted">0 means unlimited where applicable.</p><table>';
    foreach ([
        'max_download_kbps' => 'Max download KB/s',
        'max_upload_kbps' => 'Max upload KB/s',
        'delay_between_downloads_seconds' => 'Delay between downloads, seconds',
        'delay_between_uploads_seconds' => 'Delay between uploads, seconds',
        'max_files_per_transfer_run' => 'Max files per worker run',
        'max_transfer_file_size_mb' => 'Max transfer file size, MB',
        'api_nonce_ttl_seconds' => 'API nonce TTL, seconds',
        'transfer_token_ttl_seconds' => 'Transfer token TTL, seconds',
        'log_retention_days' => 'Log retention, days',
    ] as $key => $label) {
        echo '<tr><th>' . catalog_h($label) . '</th><td><input name="' . $key . '" value="' . catalog_h($settings[$key] ?? '') . '" style="width:160px"></td></tr>';
    }
    echo '</table></div>';

    echo '<div class="card"><h2>Public downloads and mirrors</h2><p class="muted">These settings do not alter parent/child authority.</p><table>';
    $downloadMode = (string)($settings['public_download_mode'] ?? 'local_direct');
    echo '<tr><th>Public download mode</th><td><select name="public_download_mode">';
    foreach ([
        'local_direct' => 'Use own site / direct download',
        'external_mirror' => 'External provider links only',
        'external_mirror_preferred' => 'Prefer external provider links, fallback to own site',
        'disabled' => 'Disable public downloads',
    ] as $mode => $label) {
        echo '<option value="' . $mode . '"' . ($downloadMode === $mode ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></td></tr>';
    foreach ([
        'external_mirror_auto_queue' => ['Auto-queue external mirror jobs', '1'],
        'external_mirror_require_admin_approval' => ['Require admin approval for external mirror jobs', '0'],
    ] as $key => [$label, $default]) {
        $value = (string)($settings[$key] ?? $default);
        echo '<tr><th>' . catalog_h($label) . '</th><td><select name="' . $key . '"><option value="0"' . ($value === '0' ? ' selected' : '') . '>No</option><option value="1"' . ($value === '1' ? ' selected' : '') . '>Yes</option></select></td></tr>';
    }
    echo '<tr><th>External mirror expiry days</th><td><input name="external_mirror_expiry_days" value="' . catalog_h($settings['external_mirror_expiry_days'] ?? '7') . '" style="width:100px"></td></tr>';
    echo '<tr><th>Max external mirror file size, MB</th><td><input name="external_mirror_max_file_size_mb" value="' . catalog_h($settings['external_mirror_max_file_size_mb'] ?? '1024') . '" style="width:120px"></td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Catalog UI</h2><table><tr><th>Game files per page</th><td><input name="game_file_display_limit" type="number" min="1" max="500" value="' . catalog_h($settings['game_file_display_limit'] ?? '100') . '" style="width:120px"></td></tr></table></div>';

    echo '<div class="card"><h2>Cron / DSM Task Scheduler worker</h2><p class="muted">The worker refreshes due inventories, polls parent approvals, queues dependency-only child downloads, transfers, and imports.</p><table>';
    $cronEnabled = (string)($settings['cron_worker_enabled'] ?? '0');
    echo '<tr><th>Cron worker enabled</th><td><select name="cron_worker_enabled"><option value="0"' . ($cronEnabled === '0' ? ' selected' : '') . '>No</option><option value="1"' . ($cronEnabled === '1' ? ' selected' : '') . '>Yes</option></select></td></tr>';
    echo '<tr><th>Cron worker token</th><td><input name="cron_worker_token" value="' . catalog_h($settings['cron_worker_token'] ?? '') . '" style="min-width:520px" placeholder="long-random-token"></td></tr>';
    echo '</table></div>';

    echo '<p><button>Save settings</button></p></form>';
    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation settings error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
