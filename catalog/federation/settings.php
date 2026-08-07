<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders or processes the federation interface for Federation Settings.
 * Why: It keeps parent/child federation administration, inventory, requests, and transfer workflows separate from
 *      general catalog pages.
 * Role: Federation UI/administration entry point backed by shared federation services.
 * Audit: Federation-specific route; consolidate shared behavior into services rather than merging distinct
 *        parent/child screens blindly.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationBaseGamePolicy.php';
require_once __DIR__ . '/../lib/FederationState.php';

try {
    $db = catalog_db(catalog_config());
    federation_reconcile_site_role($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required.');
        }
        catalog_check_csrf('fed_settings');
        $role = federation_site_role($db);
        $allowed = [
            'site_name',
            'site_url',
            'inventory_sync_interval_hours',
            'auto_import_downloads',
            'require_https_for_remote_sites',
            'allow_self_signed_federation_certificates',
            'max_download_kbps',
            'max_upload_kbps',
            'delay_between_downloads_seconds',
            'delay_between_uploads_seconds',
            'max_files_per_transfer_run',
            'max_transfer_file_size_mb',
            'api_nonce_ttl_seconds',
            'transfer_token_ttl_seconds',
            'join_claim_token_ttl_seconds',
            'log_retention_days',
            'cron_worker_enabled',
            'cron_worker_token',
        ];
        if ($role === 'parent') {
            $allowed[] = 'ignore_base_game_files';
        }

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $value = trim((string)$_POST[$key]);
            if ($key === 'inventory_sync_interval_hours') {
                $value = (string)max(0, min(720, (int)$value));
            } elseif (in_array($key, ['auto_import_downloads', 'require_https_for_remote_sites', 'allow_self_signed_federation_certificates', 'cron_worker_enabled', 'ignore_base_game_files'], true)) {
                $value = $value === '1' ? '1' : '0';
            }
            fed_set_setting($db, $key, $value);
        }

        fed_ensure_identity(
            $db,
            trim((string)($_POST['site_url'] ?? '')),
            trim((string)($_POST['site_name'] ?? ''))
        );
        fed_log($db, null, null, 'INFO', 'SETTINGS_SAVE', 'Federation settings updated; role remained ' . $role . '.');
        $_SESSION['fed_settings_flash'] = 'Federation settings saved. Connection-derived role was not changed.';
        header('Location: settings.php');
        exit;
    }

    if (!catalog_require_admin_page('Federation Settings')) {
        exit;
    }

    $identity = fed_ensure_identity($db);
    $settings = fed_all_settings($db);
    $role = federation_site_role($db);
    $parent = federation_parent_peer($db, true);
    $effectiveIgnore = federation_ignore_base_game_files($db, $parent ?: null);

    catalog_head('Federation Settings');
    catalog_flash($_SESSION['fed_settings_flash'] ?? null);
    unset($_SESSION['fed_settings_flash']);
    catalog_page_header(
        'Federation Settings',
        'Configure identity, policy, synchronization, transfer limits, security and worker behaviour. Federation role is controlled from Connections.',
        federation_main_links()
    );

    echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_settings')) . '">';

    echo '<div class="card"><h2>Identity and role</h2><table>';
    echo '<tr><th>Current role</th><td><strong>' . catalog_h(federation_display_role($db)) . '</strong> <span class="muted">Managed by established connections.</span></td></tr>';
    echo '<tr><th>Site ID</th><td class="mono">' . catalog_h($identity['site_id']) . '</td></tr>';
    echo '<tr><th>Fingerprint</th><td class="mono">' . catalog_h($identity['site_fingerprint']) . '</td></tr>';
    echo '<tr><th>Site name</th><td><input name="site_name" value="' . catalog_h($settings['site_name'] ?? '') . '" style="min-width:420px"></td></tr>';
    echo '<tr><th>Site URL</th><td><input name="site_url" value="' . catalog_h($settings['site_url'] ?? '') . '" style="min-width:640px" placeholder="https://example.com/catalog"></td></tr>';
    echo '</table><p><a class="button" href="connections.php">Manage federation role and connections</a></p></div>';

    echo '<div class="card"><h2>Role authority</h2><table>';
    echo '<tr><th>Parent</th><td>May read child inventories and pull eligible files without child approval. Approves or denies child dependency requests.</td></tr>';
    echo '<tr><th>Child</th><td>May request only files required by current local missing dependencies. Downloads require Parent approval.</td></tr>';
    echo '<tr><th>Pending join</th><td>A submitted Parent join request leaves this server Standalone until approval and automatic pairing complete.</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Base-game federation policy</h2><table>';
    if ($role === 'parent') {
        $value = (string)($settings['ignore_base_game_files'] ?? '1');
        echo '<tr><th>Ignore official base-game files</th><td><select name="ignore_base_game_files"><option value="1"' . ($value !== '0' ? ' selected' : '') . '>Yes</option><option value="0"' . ($value === '0' ? ' selected' : '') . '>No</option></select></td></tr>';
        echo '<tr><th>Effect</th><td>Applies to inventory exchange, required/missing lists, file requests, transfer queues and download endpoints. There is no missing-dependency exception.</td></tr>';
    } elseif ($role === 'child') {
        echo '<tr><th>Controlled by Parent</th><td><strong>' . ($effectiveIgnore ? 'Ignore base-game files: Yes' : 'Ignore base-game files: No') . '</strong></td></tr>';
    } else {
        echo '<tr><th>Available in Parent mode</th><td>The policy becomes editable after the first Child is approved.</td></tr>';
    }
    echo '</table></div>';

    echo '<div class="card"><h2>Inventory synchronization</h2><table>';
    echo '<tr><th>Automatic refresh interval, hours</th><td><input name="inventory_sync_interval_hours" type="number" min="0" max="720" value="' . catalog_h($settings['inventory_sync_interval_hours'] ?? '24') . '" style="width:120px"> <span class="muted">0 disables automatic refresh.</span></td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Transfer behaviour and security</h2><table>';
    foreach ([
        'auto_import_downloads' => ['Auto-import downloaded files', '1'],
        'require_https_for_remote_sites' => ['Require HTTPS for remote sites', '1'],
        'allow_self_signed_federation_certificates' => ['Allow self-signed certificates (testing only)', '0'],
    ] as $key => [$label, $default]) {
        $value = (string)($settings[$key] ?? $default);
        echo '<tr><th>' . catalog_h($label) . '</th><td><select name="' . $key . '"><option value="0"' . ($value === '0' ? ' selected' : '') . '>No</option><option value="1"' . ($value === '1' ? ' selected' : '') . '>Yes</option></select></td></tr>';
    }
    echo '</table></div>';

    echo '<div class="card"><h2>Transfer limits</h2><p class="muted">0 means unlimited where applicable.</p><table>';
    foreach ([
        'max_download_kbps' => ['Max download KB/s', '0'],
        'max_upload_kbps' => ['Max upload KB/s', '0'],
        'delay_between_downloads_seconds' => ['Delay between downloads, seconds', '5'],
        'delay_between_uploads_seconds' => ['Delay between uploads, seconds', '5'],
        'max_files_per_transfer_run' => ['Max files per worker run', '1'],
        'max_transfer_file_size_mb' => ['Max transfer file size, MB', '0'],
        'api_nonce_ttl_seconds' => ['API nonce TTL, seconds', '300'],
        'transfer_token_ttl_seconds' => ['Transfer token TTL, seconds', '300'],
        'join_claim_token_ttl_seconds' => ['Join claim TTL, seconds', '86400'],
        'log_retention_days' => ['Log retention, days', '90'],
    ] as $key => [$label, $default]) {
        echo '<tr><th>' . catalog_h($label) . '</th><td><input name="' . $key . '" value="' . catalog_h($settings[$key] ?? $default) . '" style="width:160px"></td></tr>';
    }
    echo '</table></div>';

    echo '<div class="card"><h2>Scheduled worker</h2><table>';
    $cronEnabled = (string)($settings['cron_worker_enabled'] ?? '0');
    echo '<tr><th>Worker enabled</th><td><select name="cron_worker_enabled"><option value="0"' . ($cronEnabled === '0' ? ' selected' : '') . '>No</option><option value="1"' . ($cronEnabled === '1' ? ' selected' : '') . '>Yes</option></select></td></tr>';
    echo '<tr><th>Worker token</th><td><input name="cron_worker_token" value="' . catalog_h($settings['cron_worker_token'] ?? '') . '" style="min-width:520px" placeholder="long-random-token"></td></tr>';
    echo '</table><p class="muted">The worker refreshes inventories, polls approvals, queues dependency downloads, transfers files and imports completed downloads.</p></div>';

    echo '<p><button>Save federation settings</button></p></form>';
    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Federation settings error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
