<?php
declare(strict_types=1);

function remember_expiry_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$security = file_get_contents($root . '/lib/CatalogSecurity.php');
$remember = file_get_contents($root . '/lib/CatalogRememberMe.php');
$support = file_get_contents($root . '/lib/CatalogSupportCore.php');
$login = file_get_contents($root . '/index.php');

foreach (compact('security', 'remember', 'support', 'login') as $name => $source) {
    remember_expiry_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

remember_expiry_expect(
    str_contains($security, "UNREALDB_CATALOG_SESSION_IDLE_SECONDS', 1800")
        && str_contains($security, "UNREALDB_CATALOG_SESSION_ABSOLUTE_SECONDS', 43200")
        && str_contains($remember, "['auth']['remember_days'] ?? 30"),
    'The 30-minute idle, 12-hour absolute and 30-day remember defaults changed unexpectedly.'
);

remember_expiry_expect(
    str_contains($security, "\$_SESSION['catalog_auth_expired'] = true")
        && str_contains($security, 'A valid rotating')
        && str_contains($security, 'UNREALDB_REMEMBER token must survive')
        && !str_contains($security, 'catalog_clear_remember_cookie_after_session_expiry')
        && !str_contains($security, "unset(\$_COOKIE['UNREALDB_REMEMBER'])"),
    'Session expiry still destroys the persistent remember-login credential.'
);

remember_expiry_expect(
    str_contains($support, 'catalog_start_session();')
        && str_contains($support, 'catalog_remember_cookie_present()')
        && str_contains($support, 'catalog_remember_restore($db, $config)'),
    'Admin authorization no longer restores a remembered login after session rollover.'
);

remember_expiry_expect(
    str_contains($login, "if (\$page === 'logout')")
        && str_contains($login, 'catalog_remember_clear($db);')
        && str_contains($remember, 'DELETE FROM ue_remember_tokens WHERE selector=?')
        && str_contains($remember, 'catalog_remember_clear_cookie();'),
    'Explicit logout must continue to revoke the remember token and cookie.'
);

remember_expiry_expect(
    str_contains($remember, "if (!empty(\$user['mfa_enabled_at'])")
        && str_contains($remember, 'DELETE FROM ue_remember_tokens WHERE user_id=?')
        && str_contains($remember, 'catalog_remember_clear_cookie();'),
    'Enabling MFA must continue to revoke persistent login tokens.'
);

fwrite(STDOUT, "Remember-login session-expiry contract tests passed.\n");
