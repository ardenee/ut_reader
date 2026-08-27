<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and processes External Mirror Providers administration.
 * Why: Provider lifecycle belongs here while site-wide download behavior is controlled from Download Settings.
 * Role: Presentation adapter; retains existing custom admin/CSRF behavior and HTML.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogExternalMirrorAdminService;

catalog_start_session();

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
    $service = new CatalogExternalMirrorAdminService($db, $config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!mp_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        mp_check_csrf();
        $flash = $service->handleProviderAction((string)($_POST['action'] ?? ''), $_POST);
        if ($flash !== '') {
            $_SESSION['mirror_provider_flash'] = $flash;
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

    echo '<div class="card"><h1>External Mirror Providers</h1><p class="muted">Manage provider definitions, priorities and provider-specific limits. Site-wide mode, mirror behavior and public download limits are controlled from Download Settings.</p><p><a class="button primary" href="downloads-settings.php">Download Settings</a> <a class="button" href="mirror-links.php">Mirror Links</a> <a class="button" href="mirror-queue.php">Mirror Queue</a></p></div>';

    $providers = $service->providers();
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
