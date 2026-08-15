<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog security.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *      `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

/**
 * Shared runtime safeguards for browser-facing catalog entry points.
 *
 * This file is intentionally dependency-free so it can run before database
 * configuration or session state is available.
 */

function catalog_security_forwarded_proto_trusted(): bool
{
    $enabled = strtolower(trim((string)(getenv('UNREALDB_TRUST_PROXY_HEADERS') ?: '')));
    if (!in_array($enabled, ['1', 'true', 'yes', 'on'], true)) {
        return false;
    }

    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote === '' || filter_var($remote, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $configured = trim((string)(getenv('UNREALDB_TRUSTED_PROXY_IPS') ?: ''));
    if ($configured === '') {
        return false;
    }

    foreach (preg_split('/[\s,;]+/', $configured) ?: [] as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== ''
            && filter_var($candidate, FILTER_VALIDATE_IP) !== false
            && hash_equals($candidate, $remote)) {
            return true;
        }
    }
    return false;
}

function catalog_security_is_https(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }

    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https === 'on' || $https === '1') {
        return true;
    }

    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }

    if (catalog_security_forwarded_proto_trusted()) {
        $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 2)[0]));
        return $forwardedProto === 'https';
    }

    return false;
}

function catalog_security_csp_nonce(): string
{
    static $nonce = null;
    if (is_string($nonce)) {
        return $nonce;
    }
    $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    return $nonce;
}

function catalog_security_content_security_policy(): string
{
    $nonce = catalog_security_csp_nonce();
    $policy = [
        "default-src 'self'",
        "base-uri 'self'",
        "frame-ancestors 'none'",
        "object-src 'none'",
        "form-action 'self'",
        "img-src 'self' data:",
        "font-src 'self'",
        "connect-src 'self'",
        // Inline styles remain a legacy UI requirement. JavaScript elements are
        // separately nonce-restricted below.
        "style-src 'self' 'unsafe-inline'",
        // script-src-elem is authoritative in modern browsers. The unsafe-inline
        // fallback in script-src is retained only for CSP1 clients; when a nonce
        // source is understood it does not authorize un-nonced script elements.
        "script-src 'self' 'nonce-{$nonce}' 'unsafe-inline'",
        "script-src-elem 'self' 'nonce-{$nonce}'",
        // Existing server-rendered confirm/onchange attributes are deliberately
        // isolated to script attributes rather than reopening inline <script>.
        "script-src-attr 'unsafe-inline'",
    ];
    if (catalog_security_is_https()) {
        $policy[] = 'upgrade-insecure-requests';
    }
    return implode('; ', $policy);
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

    // Session directives are PHP_INI_ALL only before the active session has
    // consumed them. Some legacy/bootstrap paths can load CatalogSupport after
    // another layer already started the session; do not turn that harmless
    // ordering difference into thousands of E_WARNING System Error records.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
    }

    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Content-Security-Policy: ' . catalog_security_content_security_policy());
        if (catalog_security_is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
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

        /*
         * Expire only the short-lived authenticated session. A valid rotating
         * UNREALDB_REMEMBER token must survive so catalog_support_is_admin()
         * can restore the administrator immediately on this same request.
         * Explicit logout, invalid tokens and enabling MFA still revoke it.
         */
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

    catalog_start_session(true);
    $now = time();
    $_SESSION['catalog_auth_started_at'] = $now;
    $_SESSION['catalog_auth_last_activity_at'] = $now;
    unset($_SESSION['catalog_auth_expired']);
}

function catalog_session_cookie_present(): bool
{
    $name = session_name();
    return $name !== '' && isset($_COOKIE[$name]) && trim((string)$_COOKIE[$name]) !== '';
}

/**
 * Anonymous read-only requests do not need a session and therefore do not need
 * a PHP session-file lock. POSTs, login/setup forms and remembered/admin users
 * still start the normal shared session.
 */
function catalog_session_request_needs_state(): bool
{
    if (catalog_session_cookie_present() || isset($_COOKIE['UNREALDB_REMEMBER'])) {
        return true;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return true;
    }

    $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    if ($script === 'setup.php') {
        return true;
    }
    if ($script === 'index.php') {
        $page = strtolower(trim((string)($_GET['page'] ?? 'home')));
        return in_array($page, ['login', 'logout'], true);
    }

    return false;
}

function catalog_start_session(bool $force = false): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (!$force && !catalog_session_request_needs_state()) {
            return;
        }

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

    catalog_start_session(true);
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
    $GLOBALS['catalog_public_cache_abort'] = true;
    return 'The request could not be completed. Reference: ' . catalog_request_id();
}

/**
 * Public pages must not turn an internal Throwable into SQL, filesystem or
 * implementation disclosure. An already authenticated administrator may still
 * receive the detailed message because these diagnostics are useful for the
 * maintenance UI; anonymous users receive only a request reference.
 */
function catalog_exception_display_message(Throwable $error): string
{
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] ' . get_class($error) . ': ' . $error->getMessage());

    if (PHP_SAPI !== 'cli'
        && session_status() === PHP_SESSION_ACTIVE
        && (($_SESSION['user']['role'] ?? '') === 'admin')) {
        return trim($error->getMessage()) !== '' ? $error->getMessage() : 'Unknown application error.';
    }

    return catalog_public_error_message();
}
