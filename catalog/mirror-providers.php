<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/FederationAuth.php';

function mp_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function mp_csrf(): string
{
    $_SESSION['mirror_providers_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['mirror_providers_csrf'];
}

function mp_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['mirror_providers_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!mp_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        mp_check_csrf();
        $action = (string)($_POST['action'] ?? 'save_settings');

        if ($action === 'save_settings') {
            foreach (['public_download_mode','external_mirror_auto_queue','external_mirror_expiry_days','external_mirror_require_admin_approval','external_mirror_max_file_size_mb'] as $key) {
                fed_set_setting($db, $key, trim((string)($_POST[$key] ?? '')));
            }
            $_SESSION['mirror_provider_flash'] = 'Public download settings saved.';
        } elseif ($action === 'add_provider') {
            $key = strtolower(trim((string)($_POST['provider_key'] ?? '')));
            $name = trim((string)($_POST['provider_name'] ?? ''));
            $class = trim((string)($_POST['provider_class'] ?? 'ManualProvider')) ?: 'ManualProvider';
            if (!preg_match('/^[a-z0-9_-]+$/', $key)) {
                throw new RuntimeException('Provider key may only use lowercase letters, numbers, underscore and dash.');
            }
            if ($name === '') {
                throw new RuntimeException('Provider name required.');
            }
            $stmt = $db->prepare('INSERT INTO ue_external_download_providers(provider_key,provider_name,provider_class,is_active,config_json,max_file_size_mb,expiry_days,priority,notes) VALUES(?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$key, $name, $class, (int)($_POST['is_active'] ?? 1), trim((string)($_POST['config_json'] ?? '{}')) ?: '{}', (int)($_POST['max_file_size_mb'] ?? 1024), (int)($_POST['expiry_days'] ?? 7), (int)($_POST['priority'] ?? 100), trim((string)($_POST['notes'] ?? '')) ?: null]);
            $_SESSION['mirror_provider_flash'] = 'Provider added.';
        } elseif ($action === 'toggle_provider') {
            $id = (int)($_POST['id'] ?? 0);
            $row = catalog_one($db, 'SELECT * FROM ue_external_download_providers WHERE id=?', [$id]);
            if (!$row) {
                throw new RuntimeException('Provider not found.');
            }
            $db->prepare('UPDATE ue_external_download_providers SET is_active=? WHERE id=?')->execute([(int)$row['is_active'] ? 0 : 1, $id]);
            $_SESSION['mirror_provider_flash'] = 'Provider toggled.';
        }
        header('Location: mirror-providers.php');
        exit;
    }

    catalog_head('External Mirror Providers');

    if (!mp_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    if (isset($_SESSION['mirror_provider_flash'])) {
        echo '<div class="card"><strong>' . catalog_h($_SESSION['mirror_provider_flash']) . '</strong></div>';
        unset($_SESSION['mirror_provider_flash']);
    }

    $settings = fed_all_settings($db);
    echo '<div class="card"><h1>External Mirror Providers</h1><p class="muted">Site-wide public download control. Federation transfers bypass this completely.</p><p><a class="button" href="admin.php">Catalog Admin</a> <a class="button" href="mirror-links.php">Mirror Links</a> <a class="button" href="mirror-queue.php">Mirror Queue</a></p></div>';

    echo '<div class="card"><h2>Public download mode</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(mp_csrf()) . '"><input type="hidden" name="action" value="save_settings"><table>';
    echo '<tr><th>Mode</th><td><select name="public_download_mode">';
    foreach (['local_direct'=>'Use own site / direct download','external_mirror'=>'External mirror only','external_mirror_preferred'=>'Prefer external mirror, fallback to own site','disabled'=>'Disable public downloads'] as $key => $label) {
        $sel = (($settings['public_download_mode'] ?? 'local_direct') === $key) ? ' selected' : '';
        echo '<option value="' . catalog_h($key) . '"' . $sel . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></td></tr>';
    foreach ([
        'external_mirror_auto_queue' => 'Auto queue mirror job when missing, 0/1',
        'external_mirror_expiry_days' => 'Default mirror expiry days',
        'external_mirror_require_admin_approval' => 'Require admin approval before mirror job, 0/1',
        'external_mirror_max_file_size_mb' => 'Max external mirror file size MB'
    ] as $key => $label) {
        echo '<tr><th>' . catalog_h($label) . '</th><td><input name="' . catalog_h($key) . '" value="' . catalog_h($settings[$key] ?? '') . '" style="width:160px"></td></tr>';
    }
    echo '</table><p><button>Save public download settings</button></p></form></div>';

    $providers = catalog_all($db, 'SELECT * FROM ue_external_download_providers ORDER BY priority, provider_name');
    echo '<div class="card"><h2>Providers</h2>';
    if (!$providers) {
        echo '<p class="muted">No providers configured.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Key</th><th>Name</th><th>Class</th><th>Active</th><th>Priority</th><th>Expiry</th><th>Max size</th><th>Notes</th><th>Action</th></tr>';
        foreach ($providers as $p) {
            echo '<tr><td class="mono">' . (int)$p['id'] . '</td><td class="mono">' . catalog_h($p['provider_key']) . '</td><td>' . catalog_h($p['provider_name']) . '</td><td class="mono">' . catalog_h($p['provider_class']) . '</td><td>' . ((int)$p['is_active'] ? 'yes' : 'no') . '</td><td>' . (int)$p['priority'] . '</td><td>' . (int)$p['expiry_days'] . ' days</td><td>' . (int)$p['max_file_size_mb'] . ' MB</td><td>' . catalog_h($p['notes']) . '</td><td><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(mp_csrf()) . '"><input type="hidden" name="action" value="toggle_provider"><input type="hidden" name="id" value="' . (int)$p['id'] . '"><button>' . ((int)$p['is_active'] ? 'Disable' : 'Enable') . '</button></form></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    echo '<div class="card"><h2>Add provider</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(mp_csrf()) . '"><input type="hidden" name="action" value="add_provider">';
    echo '<p><label>Provider key<br><input name="provider_key" required placeholder="mega" style="min-width:260px"></label></p>';
    echo '<p><label>Provider name<br><input name="provider_name" required style="min-width:420px"></label></p>';
    echo '<p><label>Provider class<br><input name="provider_class" value="ManualProvider" style="min-width:260px"></label></p>';
    echo '<p><label>Config JSON<br><textarea name="config_json" rows="4" style="width:100%">{}</textarea></label></p>';
    echo '<p><label>Max file size MB <input name="max_file_size_mb" value="1024" style="width:120px"></label> <label>Expiry days <input name="expiry_days" value="7" style="width:80px"></label> <label>Priority <input name="priority" value="100" style="width:80px"></label></p>';
    echo '<p><label>Active <select name="is_active"><option value="1">yes</option><option value="0">no</option></select></label></p>';
    echo '<p><label>Notes<br><textarea name="notes" rows="3" style="width:100%"></textarea></label></p><p><button>Add provider</button></p></form></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Mirror providers error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
