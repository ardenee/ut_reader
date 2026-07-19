<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogMfa.php';

use UnrealDb\Catalog\Application\Security\TotpService;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    if (!catalog_require_admin_page('Administrator Security')) {
        exit;
    }
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    $user = catalog_one($db, 'SELECT * FROM ue_users WHERE id=? AND role="admin"', [$userId]);
    if (!$user) {
        throw new RuntimeException('Administrator account is unavailable.');
    }

    $flash = '';
    $recoveryCodes = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('admin-security');
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $code = trim((string)($_POST['mfa_code'] ?? ''));
        if (!password_verify($password, (string)$user['password_hash'])) {
            usleep(random_int(150000, 300000));
            throw new RuntimeException('Password verification failed.');
        }

        if ($action === 'enable') {
            if (catalog_mfa_enabled($user)) {
                throw new RuntimeException('MFA is already enabled.');
            }
            $secret = trim((string)($_SESSION['catalog_mfa_setup_secret'] ?? ''));
            if ($secret === '') {
                throw new RuntimeException('MFA setup has expired. Reload this page and try again.');
            }
            $recoveryCodes = catalog_mfa_enable($db, $userId, $secret, $code);
            unset($_SESSION['catalog_mfa_setup_secret']);
            catalog_mark_recent_admin_auth();
            $flash = 'MFA enabled. Save the recovery codes shown below; they will not be displayed again.';
        } elseif ($action === 'disable') {
            if (!catalog_mfa_enabled($user) || !catalog_mfa_verify($db, $user, $code)) {
                throw new RuntimeException('A valid authenticator or recovery code is required to disable MFA.');
            }
            catalog_mfa_disable($db, $userId);
            unset($_SESSION['catalog_mfa_setup_secret']);
            catalog_mark_recent_admin_auth();
            $flash = 'MFA disabled.';
        } elseif ($action === 'reauth') {
            if (catalog_mfa_enabled($user) && !catalog_mfa_verify($db, $user, $code)) {
                throw new RuntimeException('Authenticator or recovery code verification failed.');
            }
            catalog_mark_recent_admin_auth();
            $flash = 'Administrator reauthentication confirmed for ' . catalog_recent_admin_auth_seconds() . ' seconds.';
        } elseif ($action === 'regenerate_recovery') {
            if (!catalog_mfa_enabled($user) || !catalog_mfa_verify($db, $user, $code)) {
                throw new RuntimeException('A valid authenticator or recovery code is required.');
            }
            $recoveryCodes = catalog_mfa_recovery_codes();
            $db->prepare('UPDATE ue_users SET mfa_recovery_codes_json=? WHERE id=?')
                ->execute([catalog_mfa_recovery_hashes($recoveryCodes), $userId]);
            catalog_mark_recent_admin_auth();
            $flash = 'New recovery codes created. Previous recovery codes are no longer valid.';
        } else {
            throw new RuntimeException('Unknown security action.');
        }
        $user = catalog_one($db, 'SELECT * FROM ue_users WHERE id=?', [$userId]);
    }

    $enabled = catalog_mfa_enabled($user);
    $secret = '';
    $uri = '';
    if (!$enabled) {
        $secret = trim((string)($_SESSION['catalog_mfa_setup_secret'] ?? ''));
        if ($secret === '') {
            $secret = TotpService::generateSecret();
            $_SESSION['catalog_mfa_setup_secret'] = $secret;
        }
        $uri = TotpService::provisioningUri((string)($config['site_name'] ?? 'UnrealDB'), (string)$user['username'], $secret);
    }

    catalog_head('Administrator Security');
    catalog_page_header(
        'Administrator Security',
        'Manage time-based one-time-password MFA, recovery codes, and recent authentication for sensitive operations.',
        ['Dashboard' => 'dashboard.php']
    );
    if ($flash !== '') {
        echo CatalogUi::alert('success', $flash);
    }
    if ($recoveryCodes !== []) {
        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Recovery codes</h2><p>Store these offline. Each code can be used once.</p></div></div><div class="ui-section__body"><pre class="mono">' . catalog_h(implode("\n", $recoveryCodes)) . '</pre></div></section>';
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>MFA status</h2></div></div><div class="ui-section__body">';
    echo '<p><strong>' . ($enabled ? 'Enabled' : 'Disabled') . '</strong></p>';
    if (!$enabled) {
        echo '<p>Add this secret or provisioning URI to any RFC 6238-compatible authenticator application.</p>';
        echo '<table><tr><th>Secret</th><td class="mono">' . catalog_h($secret) . '</td></tr><tr><th>Provisioning URI</th><td class="mono path">' . catalog_h($uri) . '</td></tr></table>';
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('admin-security')) . '"><input type="hidden" name="action" value="enable">';
        echo '<p><label>Password<br><input type="password" name="password" required autocomplete="current-password"></label></p>';
        echo '<p><label>Six-digit authenticator code<br><input name="mfa_code" inputmode="numeric" pattern="[0-9]{6}" required autocomplete="one-time-code"></label></p>';
        echo '<p><button type="submit">Enable MFA</button></p></form>';
    } else {
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('admin-security')) . '"><input type="hidden" name="action" value="regenerate_recovery">';
        echo '<p><label>Password<br><input type="password" name="password" required autocomplete="current-password"></label></p><p><label>Authenticator or recovery code<br><input name="mfa_code" required autocomplete="one-time-code"></label></p>';
        echo '<p><button type="submit">Regenerate recovery codes</button></p></form>';
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('admin-security')) . '"><input type="hidden" name="action" value="disable">';
        echo '<p><label>Password<br><input type="password" name="password" required autocomplete="current-password"></label></p><p><label>Authenticator or recovery code<br><input name="mfa_code" required autocomplete="one-time-code"></label></p>';
        echo '<p><button class="danger" type="submit">Disable MFA</button></p></form>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Reauthenticate</h2><p>Sensitive API actions require authentication completed within the last ' . catalog_recent_admin_auth_seconds() . ' seconds.</p></div></div><div class="ui-section__body">';
    echo '<p>Current status: <strong>' . (catalog_has_recent_admin_auth() ? 'recently authenticated' : 'reauthentication required') . '</strong></p>';
    echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('admin-security')) . '"><input type="hidden" name="action" value="reauth">';
    echo '<p><label>Password<br><input type="password" name="password" required autocomplete="current-password"></label></p>';
    if ($enabled) {
        echo '<p><label>Authenticator or recovery code<br><input name="mfa_code" required autocomplete="one-time-code"></label></p>';
    }
    echo '<p><button type="submit">Confirm recent authentication</button></p></form></div></section>';
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB administrator security][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Administrator Security Error');
    }
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'Security request failed.');
    catalog_foot();
}
