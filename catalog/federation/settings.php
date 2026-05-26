<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';

function settings_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

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

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!settings_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        settings_check_csrf();

        $allowed = [
            'site_role', 'site_name', 'site_url', 'parent_enabled', 'child_enabled',
            'allow_parent_pull_from_child', 'allow_child_request_from_parent',
            'max_download_kbps', 'max_upload_kbps',
            'delay_between_downloads_seconds', 'delay_between_uploads_seconds',
            'max_files_per_transfer_run', 'max_transfer_file_size_mb',
            'auto_import_downloads', 'require_https_for_remote_sites',
            'api_nonce_ttl_seconds', 'transfer_token_ttl_seconds', 'log_retention_days',
            'cron_worker_enabled', 'cron_worker_token'
        ];

        foreach ($allowed as $key) {
            $value = trim((string)($_POST[$key] ?? ''));
            fed_set_setting($db, $key, $value);
        }

        fed_ensure_identity($db, trim((string)$_POST['site_url']), trim((string)$_POST['site_name']));
        fed_log($db, null, null, 'INFO', 'SETTINGS_SAVE', 'Federation settings updated.');
        $_SESSION['fed_settings_flash'] = 'Federation settings saved.';
        header('Location: settings.php');
        exit;
    }

    catalog_head('Federation Settings');

    if (!settings_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="../index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    $identity = fed_ensure_identity($db);
    $settings = fed_all_settings($db);

    if (isset($_SESSION['fed_settings_flash'])) {
        echo '<div class="card"><strong>' . catalog_h($_SESSION['fed_settings_flash']) . '</strong></div>';
        unset($_SESSION['fed_settings_flash']);
    }

    echo '<div class="card"><h1>Federation Settings</h1><p><a class="button" href="admin.php">Federation admin</a> <a class="button" href="peers.php">Peers</a> <a class="button" href="maintenance.php">Maintenance</a> <a class="button" href="logs.php">Logs</a></p></div>';

    echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(settings_csrf()) . '">';
    echo '<div class="card"><h2>Site identity</h2><table>';
    echo '<tr><th>Site ID</th><td class="mono">' . catalog_h($identity['site_id']) . '</td></tr>';
    echo '<tr><th>Fingerprint</th><td class="mono">' . catalog_h($identity['site_fingerprint']) . '</td></tr>';
    echo '<tr><th>Site role</th><td><select name="site_role">';
    foreach (['standalone','parent','child'] as $role) {
        $sel = (($settings['site_role'] ?? 'standalone') === $role) ? ' selected' : '';
        echo '<option value="' . catalog_h($role) . '"' . $sel . '>' . catalog_h($role) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th>Site name</th><td><input name="site_name" value="' . catalog_h($settings['site_name'] ?? '') . '" style="min-width:420px"></td></tr>';
    echo '<tr><th>Site URL</th><td><input name="site_url" value="' . catalog_h($settings['site_url'] ?? '') . '" style="min-width:640px" placeholder="https://example.com/catalog"></td></tr>';
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
        echo '<tr><th>' . catalog_h($label) . '</th><td><select name="' . catalog_h($key) . '"><option value="0"' . ($val === '0' ? ' selected' : '') . '>No</option><option value="1"' . ($val === '1' ? ' selected' : '') . '>Yes</option></select></td></tr>';
    }
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

    echo '<div class="card"><h2>Cron / DSM Task Scheduler worker</h2><p class="muted">Use a long random token. The cron endpoint runs the same controlled bulk worker as worker-run.php.</p><table>';
    $cronEnabled = (string)($settings['cron_worker_enabled'] ?? '0');
    echo '<tr><th>Cron worker enabled</th><td><select name="cron_worker_enabled"><option value="0"' . ($cronEnabled === '0' ? ' selected' : '') . '>No</option><option value="1"' . ($cronEnabled === '1' ? ' selected' : '') . '>Yes</option></select></td></tr>';
    echo '<tr><th>Cron worker token</th><td><input name="cron_worker_token" value="' . catalog_h($settings['cron_worker_token'] ?? '') . '" style="min-width:520px" placeholder="long-random-token"></td></tr>';
    echo '</table></div><p><button>Save federation settings</button></p></form>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation settings error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
