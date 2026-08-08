<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders Administrator Security and manages session-bound MFA setup state.
 * Why: Password/MFA verification and durable recovery-code mutations now belong to a dedicated security service.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Security\CatalogAdminSecurityService;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    if (!catalog_require_admin_page('Administrator Security')) {
        exit;
    }

    $service = new CatalogAdminSecurityService($db);
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    $user = $service->administrator($userId);
    $flash = '';
    $recoveryCodes = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('admin-security');
        $result = $service->execute(
            $user,
            strtolower(trim((string)($_POST['action'] ?? ''))),
            (string)($_POST['password'] ?? ''),
            trim((string)($_POST['mfa_code'] ?? '')),
            trim((string)($_SESSION['catalog_mfa_setup_secret'] ?? ''))
        );
        if ($result['clear_setup_secret']) {
            unset($_SESSION['catalog_mfa_setup_secret']);
        }
        $user = $result['user'];
        $flash = $result['flash'];
        $recoveryCodes = $result['recovery_codes'];
    }

    $enabled = catalog_mfa_enabled($user);
    $secret = '';
    $uri = '';
    if (!$enabled) {
        $setup = $service->setup(
            (string)($config['site_name'] ?? 'UnrealDB'),
            (string)$user['username'],
            (string)($_SESSION['catalog_mfa_setup_secret'] ?? '')
        );
        $secret = $setup['secret'];
        $uri = $setup['uri'];
        $_SESSION['catalog_mfa_setup_secret'] = $secret;
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
