<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and processes Public Access & Mail settings.
 * Why: Development notice, public feedback and SMTP delivery are administered independently from download controls.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogPublicAccessSettingsService;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Public Access & Mail')) {
        exit;
    }

    $service = new CatalogPublicAccessSettingsService($db, $config);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('public_access_settings');
        $action = strtolower(trim((string)($_POST['action'] ?? 'save')));
        $_SESSION['public_access_flash'] = $service->save($_POST, $action, catalog_request_id());
        header('Location: public-access-settings.php', true, 303);
        exit;
    }

    $current = $service->current();
    $public = $current['public'];
    $smtp = $current['smtp'];

    catalog_head('Public Access & Mail');
    catalog_page_header(
        'Public Access & Mail',
        'Control the public development notice, feedback form and SMTP delivery.',
        ['Download Settings' => 'downloads-settings.php', 'Landing page' => 'index.php', 'Feedback form' => 'feedback.php']
    );
    if (isset($_SESSION['public_access_flash'])) {
        catalog_flash((string)$_SESSION['public_access_flash']);
        unset($_SESSION['public_access_flash']);
    }

    echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('public_access_settings')) . '">';

    echo '<div class="card"><h2>Public development notice</h2><table>';
    echo '<tr><th>Show notice</th><td><label><input type="checkbox" name="site_development_mode" value="1"' . ($public['site_development_mode'] ? ' checked' : '') . '> Site is under active development and some functions are unavailable</label></td></tr>';
    echo '<tr><th>Notice title</th><td><input name="site_development_title" maxlength="180" value="' . catalog_h($public['site_development_title']) . '" style="min-width:520px"></td></tr>';
    echo '<tr><th>Notice text</th><td><textarea name="site_development_message" rows="4" maxlength="2000" style="min-width:620px">' . catalog_h($public['site_development_message']) . '</textarea></td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Public feedback</h2><p class="muted">The public form contains only feedback fields. It uses the SMTP configuration below; mail settings are never displayed on the feedback page.</p><table>';
    echo '<tr><th>Feedback form</th><td><label><input type="checkbox" name="feedback_enabled" value="1"' . ($public['feedback_enabled'] ? ' checked' : '') . '> enabled</label></td></tr>';
    echo '<tr><th>Recipient</th><td><input type="email" name="feedback_recipient" maxlength="254" value="' . catalog_h($public['feedback_recipient']) . '" style="min-width:360px"></td></tr>';
    echo '<tr><th>Submission limit</th><td><input type="number" min="1" max="1000" name="feedback_max_requests" value="' . (int)$public['feedback_max_requests'] . '"> per <input type="number" min="60" max="604800" name="feedback_window_seconds" value="' . (int)$public['feedback_window_seconds'] . '"> seconds, per IP</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>SMTP mail server</h2><table>';
    echo '<tr><th>SMTP delivery</th><td><label><input type="checkbox" name="smtp_enabled" value="1"' . ($smtp['enabled'] ? ' checked' : '') . '> enabled</label></td></tr>';
    echo '<tr><th>Host</th><td><input name="smtp_host" maxlength="255" value="' . catalog_h($smtp['host']) . '" placeholder="smtp.example.com" style="min-width:360px"></td></tr>';
    echo '<tr><th>Port / encryption</th><td><input type="number" min="1" max="65535" name="smtp_port" value="' . (int)$smtp['port'] . '"> <select name="smtp_encryption">';
    foreach (['starttls' => 'STARTTLS', 'ssl' => 'TLS/SSL on connect', 'none' => 'None'] as $value => $label) {
        echo '<option value="' . catalog_h($value) . '"' . ($smtp['encryption'] === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th>Username</th><td><input name="smtp_username" maxlength="255" value="' . catalog_h($smtp['username']) . '" autocomplete="username" style="min-width:360px"></td></tr>';
    echo '<tr><th>Password</th><td><input type="password" name="smtp_password" autocomplete="new-password" placeholder="' . ($smtp['password_is_set'] ? 'Saved; leave blank to keep' : 'Not set') . '" style="min-width:360px"> <label><input type="checkbox" name="smtp_password_clear" value="1"> clear saved password</label><br><span class="muted small">When UNREALDB_FEDERATION_MASTER_KEY is configured, a newly saved password is encrypted before storage.</span></td></tr>';
    echo '<tr><th>From address</th><td><input type="email" name="smtp_from_email" maxlength="254" value="' . catalog_h($smtp['from_email']) . '" style="min-width:360px"></td></tr>';
    echo '<tr><th>From name</th><td><input name="smtp_from_name" maxlength="180" value="' . catalog_h($smtp['from_name']) . '" style="min-width:360px"></td></tr>';
    echo '<tr><th>Timeout</th><td><input type="number" min="3" max="120" name="smtp_timeout_seconds" value="' . (int)$smtp['timeout_seconds'] . '"> seconds</td></tr>';
    echo '</table></div>';

    echo '<p><button class="primary" type="submit" name="action" value="save">Save settings</button> <button type="submit" name="action" value="test_mail">Save and send SMTP test</button></p></form>';
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] public access settings failed: ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Public Access & Mail error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'Settings could not be saved');
    catalog_foot();
}
