<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

function settings_csrf(): string
{
    $_SESSION['fed_settings_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['fed_settings_csrf'];
}

function settings_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['fed_settings_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function settings_apply_role(PDO $db, string $role): void
{
    if (!in_array($role, ['standalone', 'parent', 'child'], true)) {
        throw new RuntimeException('Invalid federation site role.');
    }

    fed_set_setting($db, 'site_role', $role);
    if ($role === 'child') {
        fed_set_setting($db, 'child_enabled', '1');
        fed_set_setting($db, 'parent_enabled', '0');
        fed_set_setting($db, 'join_requests_enabled', '0');
    } elseif ($role === 'standalone') {
        fed_set_setting($db, 'child_enabled', '0');
        fed_set_setting($db, 'parent_enabled', '0');
        fed_set_setting($db, 'join_requests_enabled', '0');
    }
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        settings_check_csrf();

        $siteRole = strtolower(trim((string)($_POST['site_role'] ?? 'standalone')));
        if (!in_array($siteRole, ['standalone', 'parent', 'child'], true)) {
            throw new RuntimeException('Invalid federation site role.');
        }

        $allowed = [
            'site_name', 'site_url', 'parent_enabled', 'child_enabled',
            'allow_parent_pull_from_child', 'allow_child_request_from_parent',
            'max_download_kbps', 'max_upload_kbps',
            'delay_between_downloads_seconds', 'delay_between_uploads_seconds',
            'max_files_per_transfer_run', 'max_transfer_file_size_mb',
            'auto_import_downloads', 'require_https_for_remote_sites',
            'api_nonce_ttl_seconds', 'transfer_token_ttl_seconds', 'log_retention_days',
            'cron_worker_enabled', 'cron_worker_token',
            'public_download_mode', 'external_mirror_auto_queue', 'external_mirror_expiry_days',
            'external_mirror_require_admin_approval', 'external_mirror_max_file_size_mb',
            'join_requests_enabled', 'join_claim_token_ttl_seconds', 'main_parent_url',
            'game_file_display_limit'
        ];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            fed_set_setting($db, $key, trim((string)$_POST[$key]));
        }

        settings_apply_role($db, $siteRole);
        fed_ensure_identity($db, trim((string)($_POST['site_url'] ?? '')), trim((string)($_POST['site_name'] ?? '')));
        fed_log($db, null, null, 'INFO', 'SETTINGS_SAVE', 'Federation/settings updated for role ' . $siteRole . '.');
        $_SESSION['fed_settings_flash'] = $siteRole === 'child'
            ? 'Settings saved. Child role enabled; parent features and public child join requests were disabled.'
            : 'Settings saved.';
        header('Location: settings.php');
        exit;
    }

    if (!catalog_require_admin_page('Federation Settings')) {
        exit;
    }

    $currentRole = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    if ($currentRole === 'child') {
        settings_apply_role($db, 'child');
    }

    catalog_head('Federation Settings');
    catalog_flash($_SESSION['fed_settings_flash'] ?? null);
    unset($_SESSION['fed_settings_flash']);

    $identity = fed_ensure_identity($db);
    $settings = fed_all_settings($db);
    $currentRole = strtolower(trim((string)($settings['site_role'] ?? 'standalone')));
    $isChild = $currentRole === 'child';

    catalog_page_header('Federation Settings', 'Configure site identity, catalog UI defaults, parent/child permissions, transfer limits, public download handling, and DSM cron worker settings.', catalog_federation_links() + ['Join Parent' => 'join.php', 'Join Requests' => 'join-requests.php', 'Mirror Providers' => '../mirror-providers.php', 'Mirror Links' => '../mirror-links.php']);

    echo '<form method="post" id="federation-settings-form"><input type="hidden" name="csrf" value="' . catalog_h(settings_csrf()) . '">';
    echo '<div class="card"><h2>Site identity</h2><table>';
    echo '<tr><th>Site ID</th><td class="mono">' . catalog_h($identity['site_id']) . '</td></tr>';
    echo '<tr><th>Fingerprint</th><td class="mono">' . catalog_h($identity['site_fingerprint']) . '</td></tr>';
    echo '<tr><th>Site role</th><td><select id="federation-site-role" name="site_role">';
    foreach (['standalone','parent','child'] as $role) {
        $sel = $currentRole === $role ? ' selected' : '';
        echo '<option value="' . catalog_h($role) . '"' . $sel . '>' . catalog_h($role) . '</option>';
    }
    echo '</select> <span class="muted">Child role automatically disables parent-only settings.</span></td></tr>';
    echo '<tr><th>Site name</th><td><input name="site_name" value="' . catalog_h($settings['site_name'] ?? '') . '" style="min-width:420px"></td></tr>';
    echo '<tr><th>Site URL</th><td><input name="site_url" value="' . catalog_h($settings['site_url'] ?? '') . '" style="min-width:640px" placeholder="https://example.com/catalog"></td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Catalog UI</h2><p class="muted">Display defaults used by public catalog pages.</p><table>';
    echo '<tr><th>Game files per page</th><td><input name="game_file_display_limit" type="number" min="1" max="500" value="' . catalog_h($settings['game_file_display_limit'] ?? '100') . '" style="width:120px"> <span class="muted">Default is 100. Users can still choose 25, 50, 100, 200, or 500 on the file list page.</span></td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Join / pairing</h2><p class="muted">Public join requests and claim-token settings apply only when this deployment acts as a parent.</p><table>';
    $joinEnabled = $isChild ? '0' : (string)($settings['join_requests_enabled'] ?? '1');
    $parentDisabled = $isChild ? ' disabled' : '';
    echo '<tr><th>Accept public child join requests on this parent</th><td><select name="join_requests_enabled" data-parent-only' . $parentDisabled . '><option value="0"' . ($joinEnabled === '0' ? ' selected' : '') . '>No</option><option value="1"' . ($joinEnabled === '1' ? ' selected' : '') . '>Yes</option></select><span class="muted parent-disabled-note"' . ($isChild ? '' : ' hidden') . '> Disabled for child role.</span></td></tr>';
    echo '<tr><th>Join claim token TTL, seconds</th><td><input name="join_claim_token_ttl_seconds" data-parent-only' . $parentDisabled . ' value="' . catalog_h($settings['join_claim_token_ttl_seconds'] ?? '86400') . '" style="width:160px"><span class="muted parent-disabled-note"' . ($isChild ? '' : ' hidden') . '> Disabled for child role.</span></td></tr>';
    echo '<tr><th>Main parent URL</th><td><input name="main_parent_url" value="' . catalog_h($settings['main_parent_url'] ?? '') . '" style="min-width:640px" placeholder="https://parent.example.com/catalog"> <a class="button" href="join.php">Join a parent</a></td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Role permissions</h2><table>';
    foreach ([
        'parent_enabled' => 'Parent features enabled',
        'child_enabled' => 'Child features enabled',
        'allow_parent_pull_from_child' => 'Allow paired parent to pull files from this child',
        'allow_child_request_from_parent' => 'Allow child to request missing dependency files from parent',
        'auto_import_downloads' => 'Auto-import downloaded files after transfer',
        'require_https_for_remote_sites' => 'Require HTTPS for remote federation sites'
    ] as $key => $label) {
        $val = (string)($settings[$key] ?? '0');
        $attributes = '';
        $note = '';
        if ($key === 'parent_enabled') {
            $val = $isChild ? '0' : $val;
            $attributes = ' data-parent-only' . $parentDisabled;
            $note = '<span class="muted parent-disabled-note"' . ($isChild ? '' : ' hidden') . '> Disabled for child role.</span>';
        } elseif ($key === 'child_enabled') {
            $val = $isChild ? '1' : $val;
            $attributes = ' data-child-forced' . ($isChild ? ' disabled' : '');
            $note = '<span class="muted child-forced-note"' . ($isChild ? '' : ' hidden') . '> Required for child role.</span>';
        }
        echo '<tr><th>' . catalog_h($label) . '</th><td><select name="' . catalog_h($key) . '"' . $attributes . '><option value="0"' . ($val === '0' ? ' selected' : '') . '>No</option><option value="1"' . ($val === '1' ? ' selected' : '') . '>Yes</option></select>' . $note . '</td></tr>';
    }
    echo '</table></div>';

    echo '<div class="card"><h2>Public downloads / shared provider links</h2><p class="muted">These settings only affect normal public/user downloads. Federation parent/child transfers bypass this and keep using the controlled federation API.</p><table>';
    $downloadMode = (string)($settings['public_download_mode'] ?? 'local_direct');
    echo '<tr><th>Public download mode</th><td><select name="public_download_mode">';
    foreach ([
        'local_direct' => 'Use own site / direct download',
        'external_mirror' => 'External provider links only',
        'external_mirror_preferred' => 'Prefer external provider links, fallback to own site',
        'disabled' => 'Disable public downloads'
    ] as $mode => $label) {
        echo '<option value="' . catalog_h($mode) . '"' . ($downloadMode === $mode ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></td></tr>';
    $autoQueue = (string)($settings['external_mirror_auto_queue'] ?? '1');
    echo '<tr><th>Auto-queue external mirror job when no active link exists</th><td><select name="external_mirror_auto_queue"><option value="0"' . ($autoQueue === '0' ? ' selected' : '') . '>No</option><option value="1"' . ($autoQueue === '1' ? ' selected' : '') . '>Yes</option></select></td></tr>';
    $adminApproval = (string)($settings['external_mirror_require_admin_approval'] ?? '0');
    echo '<tr><th>Require admin approval before external mirror job runs</th><td><select name="external_mirror_require_admin_approval"><option value="0"' . ($adminApproval === '0' ? ' selected' : '') . '>No</option><option value="1"' . ($adminApproval === '1' ? ' selected' : '') . '>Yes</option></select></td></tr>';
    echo '<tr><th>Shared provider link stale/expiry days</th><td><input name="external_mirror_expiry_days" value="' . catalog_h($settings['external_mirror_expiry_days'] ?? '7') . '" style="width:90px"> <span class="muted">Default is 7. Active external links older than this are treated as stale/expired and new requests can queue a fresh upload/link.</span></td></tr>';
    echo '<tr><th>Max external mirror file size, MB</th><td><input name="external_mirror_max_file_size_mb" value="' . catalog_h($settings['external_mirror_max_file_size_mb'] ?? '1024') . '" style="width:120px"></td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Transfer limits</h2><p class="muted">0 means unlimited where applicable. These values apply to controlled parent/child upload/download jobs.</p><table>';
    foreach ([
        'max_download_kbps' => 'Max download KB/s',
        'max_upload_kbps' => 'Max upload KB/s',
        'delay_between_downloads_seconds' => 'Delay between downloads, seconds',
        'delay_between_uploads_seconds' => 'Delay between uploads, seconds',
        'max_files_per_transfer_run' => 'Max files per transfer run',
        'max_transfer_file_size_mb' => 'Max transfer file size, MB',
        'api_nonce_ttl_seconds' => 'API nonce TTL, seconds',
        'transfer_token_ttl_seconds' => 'Transfer token TTL, seconds',
        'log_retention_days' => 'Log retention, days'
    ] as $key => $label) {
        echo '<tr><th>' . catalog_h($label) . '</th><td><input name="' . catalog_h($key) . '" value="' . catalog_h($settings[$key] ?? '') . '" style="width:160px"></td></tr>';
    }
    echo '</table></div>';

    echo '<div class="card"><h2>Cron / DSM Task Scheduler worker</h2><p class="muted">Use a long random token. The cron endpoint runs federation worker maintenance plus mirror maintenance.</p><table>';
    $cronEnabled = (string)($settings['cron_worker_enabled'] ?? '0');
    echo '<tr><th>Cron worker enabled</th><td><select name="cron_worker_enabled"><option value="0"' . ($cronEnabled === '0' ? ' selected' : '') . '>No</option><option value="1"' . ($cronEnabled === '1' ? ' selected' : '') . '>Yes</option></select></td></tr>';
    echo '<tr><th>Cron worker token</th><td><input name="cron_worker_token" value="' . catalog_h($settings['cron_worker_token'] ?? '') . '" style="min-width:520px" placeholder="long-random-token"></td></tr>';
    echo '</table></div><p><button>Save settings</button></p></form>';

    echo '<script>(function(){const role=document.getElementById("federation-site-role");const parentOnly=document.querySelectorAll("[data-parent-only]");const childForced=document.querySelectorAll("[data-child-forced]");const parentNotes=document.querySelectorAll(".parent-disabled-note");const childNotes=document.querySelectorAll(".child-forced-note");function sync(){const child=role.value==="child";parentOnly.forEach(function(field){field.disabled=child;if(child&&(field.name==="join_requests_enabled"||field.name==="parent_enabled"))field.value="0";});childForced.forEach(function(field){field.disabled=child;if(child)field.value="1";});parentNotes.forEach(function(note){note.hidden=!child;});childNotes.forEach(function(note){note.hidden=!child;});}role.addEventListener("change",sync);sync();})();</script>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation settings error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
