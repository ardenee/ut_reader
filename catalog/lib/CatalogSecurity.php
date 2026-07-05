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

    return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
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

    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('Cross-Origin-Opener-Policy: same-origin');
    }
}

function catalog_start_session(): void
{
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    /*
     * Several catalog pages predate CatalogSecurity and still call raw
     * session_start(). Keep the default PHPSESSID name so their session and
     * the login session are the same cookie.
     */
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => catalog_security_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
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
