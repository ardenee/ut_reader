<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/FederationAuth.php';
require_once __DIR__ . '/lib/CatalogPublicAccess.php';
require_once __DIR__ . '/lib/CatalogSmtpMailer.php';

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Public Access & Mail')) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('public_access_settings');
        $action = strtolower(trim((string)($_POST['action'] ?? 'save')));

        $publicValues = [
            'site_development_mode' => isset($_POST['site_development_mode']) ? '1' : '0',
            'site_development_title' => (string)($_POST['site_development_title'] ?? ''),
            'site_development_message' => (string)($_POST['site_development_message'] ?? ''),
            'feedback_enabled' => isset($_POST['feedback_enabled']) ? '1' : '0',
            'feedback_recipient' => (string)($_POST['feedback_recipient'] ?? 'info@unrealdb.com'),
            'public_download_max_files' => (string)($_POST['public_download_max_files'] ?? '10'),
            'public_download_window_seconds' => (string)($_POST['public_download_window_seconds'] ?? '3600'),
            'public_package_max_builds' => (string)($_POST['public_package_max_builds'] ?? '10'),
            'public_package_window_seconds' => (string)($_POST['public_package_window_seconds'] ?? '3600'),
            'public_download_speed_kbps' => (string)($_POST['public_download_speed_kbps'] ?? '0'),
            'public_block_crawlers' => isset($_POST['public_block_crawlers']) ? '1' : '0',
            'public_burst_max_requests' => (string)($_POST['public_burst_max_requests'] ?? '30'),
            'public_burst_window_seconds' => (string)($_POST['public_burst_window_seconds'] ?? '10'),
            'public_burst_block_seconds' => (string)($_POST['public_burst_block_seconds'] ?? '600'),
            'feedback_max_requests' => (string)($_POST['feedback_max_requests'] ?? '5'),
            'feedback_window_seconds' => (string)($_POST['feedback_window_seconds'] ?? '3600'),
        ];
        $publicSettings = catalog_public_access_save($db, $config, $publicValues);

        $smtpEnabled = isset($_POST['smtp_enabled']) ? '1' : '0';
        $smtpHost = substr(trim((string)($_POST['smtp_host'] ?? '')), 0, 255);
        $smtpPort = (string)catalog_public_access_int($_POST['smtp_port'] ?? null, 587, 1, 65535);
        $smtpEncryption = strtolower(trim((string)($_POST['smtp_encryption'] ?? 'starttls')));
        if (!in_array($smtpEncryption, ['none', 'starttls', 'ssl'], true)) {
            $smtpEncryption = 'starttls';
        }
        $smtpUsername = substr(trim((string)($_POST['smtp_username'] ?? '')), 0, 255);
        $smtpFromEmail = substr(trim((string)($_POST['smtp_from_email'] ?? 'info@unrealdb.com')), 0, 254);
        $smtpFromName = substr(trim((string)($_POST['smtp_from_name'] ?? 'UnrealDB')), 0, 180);
        $smtpTimeout = (string)catalog_public_access_int($_POST['smtp_timeout_seconds'] ?? null, 20, 3, 120);

        if ($publicSettings['feedback_enabled'] && $smtpEnabled !== '1') {
            throw new RuntimeException('Enable SMTP delivery before enabling the public feedback form.');
        }
        if ($smtpEnabled === '1') {
            if ($smtpHost === '') {
                throw new RuntimeException('SMTP host is required when SMTP is enabled.');
            }
            catalog_smtp_address($smtpFromEmail, 'SMTP From address');
            catalog_smtp_address((string)$publicSettings['feedback_recipient'], 'Feedback recipient');
        }

        foreach ([
            'smtp_enabled' => $smtpEnabled,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_encryption' => $smtpEncryption,
            'smtp_username' => $smtpUsername,
            'smtp_from_email' => $smtpFromEmail,
            'smtp_from_name' => $smtpFromName,
            'smtp_timeout_seconds' => $smtpTimeout,
        ] as $name => $value) {
            fed_set_setting($db, $name, $value);
        }

        if (isset($_POST['smtp_password_clear'])) {
            fed_set_setting($db, 'smtp_password', '');
        } else {
            $smtpPassword = (string)($_POST['smtp_password'] ?? '');
            if ($smtpPassword !== '') {
                $storedPassword = $smtpPassword;
                $secretStore = fed_secret_store();
                if ($secretStore->hasMasterKey()) {
                    $storedPassword = $secretStore->encrypt($smtpPassword);
                }
                fed_set_setting($db, 'smtp_password', $storedPassword);
            }
        }

        if ($action === 'test_mail') {
            catalog_smtp_send(
                $db,
                (string)$publicSettings['feedback_recipient'],
                'UnrealDB SMTP test',
                "This is a test message from UnrealDB.\n\nThe saved SMTP and feedback recipient settings are working.\nRequest reference: " . catalog_request_id()
            );
            $_SESSION['public_access_flash'] = 'Settings saved and the SMTP test message was accepted by the mail server.';
        } else {
            $_SESSION['public_access_flash'] = 'Public access, feedback and SMTP settings saved.';
        }
        header('Location: public-access-settings.php', true, 303);
        exit;
    }

    $public = catalog_public_access_settings($db, $config);
    $smtp = catalog_smtp_settings($db);

    catalog_head('Public Access & Mail');
    catalog_page_header(
        'Public Access & Mail',
        'Control the development notice, public feedback delivery, download/package limits, transfer speed and automated-access protection.',
        ['Downloads' => 'download-admin.php', 'Landing page' => 'index.php', 'Feedback form' => 'feedback.php']
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

    echo '<div class="card"><h2>Public downloads and generated packages</h2><table>';
    echo '<tr><th>Individual file downloads</th><td><input type="number" min="1" max="10000" name="public_download_max_files" value="' . (int)$public['public_download_max_files'] . '"> per <input type="number" min="60" max="604800" name="public_download_window_seconds" value="' . (int)$public['public_download_window_seconds'] . '"> seconds, per IP</td></tr>';
    echo '<tr><th>Generated packages</th><td><input type="number" min="1" max="10000" name="public_package_max_builds" value="' . (int)$public['public_package_max_builds'] . '"> per <input type="number" min="60" max="604800" name="public_package_window_seconds" value="' . (int)$public['public_package_window_seconds'] . '"> seconds, per IP</td></tr>';
    echo '<tr><th>Local download speed</th><td><input type="number" min="0" max="1048576" name="public_download_speed_kbps" value="' . (int)$public['public_download_speed_kbps'] . '"> KB/s per transfer <span class="muted small">0 means unlimited. External mirror speed cannot be controlled here.</span></td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Automated access and rapid-link protection</h2><table>';
    echo '<tr><th>Known crawlers</th><td><label><input type="checkbox" name="public_block_crawlers" value="1"' . ($public['public_block_crawlers'] ? ' checked' : '') . '> block known crawler, spider, scripted downloader and headless-browser user agents</label></td></tr>';
    echo '<tr><th>Rapid requests</th><td>More than <input type="number" min="2" max="10000" name="public_burst_max_requests" value="' . (int)$public['public_burst_max_requests'] . '"> public page/link requests in <input type="number" min="1" max="3600" name="public_burst_window_seconds" value="' . (int)$public['public_burst_window_seconds'] . '"> seconds blocks the IP.</td></tr>';
    echo '<tr><th>Temporary block</th><td><input type="number" min="10" max="86400" name="public_burst_block_seconds" value="' . (int)$public['public_burst_block_seconds'] . '"> seconds</td></tr>';
    echo '</table><p class="muted small">Logged-in administrators are exempt. Every blocked request is still available to the central PHP/system error logging path when it causes an application error.</p></div>';

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
