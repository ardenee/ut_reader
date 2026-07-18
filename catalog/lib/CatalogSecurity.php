<?php
declare(strict_types=1);

/**
 * Shared runtime safeguards for browser-facing catalog entry points.
 *
 * This file is intentionally dependency-free so it can run before database
 * configuration or session state is available.
 */

function catalog_security_is_https(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }

    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https === 'on' || $https === '1') {
        return true;
    }

    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto === 'https') {
        return true;
    }

    return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function catalog_security_env_seconds(string $name, int $default, int $minimum, int $maximum): int
{
    $configured = (int)(getenv($name) ?: 0);
    return max($minimum, min($configured > 0 ? $configured : $default, $maximum));
}

function catalog_session_lifetime_seconds(): int
{
    return catalog_session_absolute_timeout_seconds();
}

function catalog_session_idle_timeout_seconds(): int
{
    return catalog_security_env_seconds('UNREALDB_CATALOG_SESSION_IDLE_SECONDS', 1800, 300, 86400);
}

function catalog_session_absolute_timeout_seconds(): int
{
    return catalog_security_env_seconds('UNREALDB_CATALOG_SESSION_ABSOLUTE_SECONDS', 43200, 3600, 7 * 86400);
}

function catalog_session_cookie_secure(): bool
{
    $configured = strtolower(trim((string)(getenv('UNREALDB_SESSION_COOKIE_SECURE') ?: '')));
    if (in_array($configured, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($configured, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return catalog_security_is_https();
}

function catalog_apply_runtime_safeguards(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('Cross-Origin-Opener-Policy: same-origin');
    }
}

function catalog_clear_remember_cookie_after_session_expiry(): void
{
    if (headers_sent()) {
        return;
    }

    setcookie('UNREALDB_REMEMBER', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => catalog_session_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['UNREALDB_REMEMBER']);
}

function catalog_enforce_authenticated_session_limits(): void
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    if (($_SESSION['user']['role'] ?? '') !== 'admin') {
        unset($_SESSION['catalog_auth_started_at'], $_SESSION['catalog_auth_last_activity_at']);
        return;
    }

    $now = time();
    $startedAt = (int)($_SESSION['catalog_auth_started_at'] ?? $now);
    $lastActivityAt = (int)($_SESSION['catalog_auth_last_activity_at'] ?? $now);
    $idleExpired = $lastActivityAt > 0 && ($now - $lastActivityAt) > catalog_session_idle_timeout_seconds();
    $absoluteExpired = $startedAt > 0 && ($now - $startedAt) > catalog_session_absolute_timeout_seconds();

    if ($idleExpired || $absoluteExpired) {
        unset($_SESSION['user'], $_SESSION['catalog_auth_started_at'], $_SESSION['catalog_auth_last_activity_at']);
        $_SESSION['catalog_auth_expired'] = true;
        catalog_clear_remember_cookie_after_session_expiry();
        session_regenerate_id(true);
        return;
    }

    $_SESSION['catalog_auth_started_at'] = $startedAt;
    $_SESSION['catalog_auth_last_activity_at'] = $now;
    unset($_SESSION['catalog_auth_expired']);
}

function catalog_mark_authenticated_session(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    catalog_start_session();
    $now = time();
    $_SESSION['catalog_auth_started_at'] = $now;
    $_SESSION['catalog_auth_last_activity_at'] = $now;
    unset($_SESSION['catalog_auth_expired']);
}

function catalog_start_session(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        $lifetime = catalog_session_lifetime_seconds();
        ini_set('session.gc_maxlifetime', (string)$lifetime);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        /*
         * Keep the default PHPSESSID name so legacy entry points and the newer
         * application bootstrap share the same session. The session cookie is
         * browser-scoped; persistent login is handled by the separate rotating
         * remember-me token.
         */
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => catalog_session_cookie_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    catalog_enforce_authenticated_session_limits();
}

function catalog_destroy_session(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    catalog_start_session();
    $_SESSION = [];

    $params = session_get_cookie_params();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => (string)($params['path'] ?? '/'),
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => (string)($params['samesite'] ?? 'Lax'),
        ]);
    }

    session_destroy();
}

function catalog_client_ip(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function catalog_request_id(): string
{
    static $requestId = null;
    if (is_string($requestId)) {
        return $requestId;
    }

    $requestId = bin2hex(random_bytes(12));
    return $requestId;
}

function catalog_public_error_message(): string
{
    return 'The request could not be completed. Reference: ' . catalog_request_id();
}
