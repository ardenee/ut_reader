<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog remember me.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

function catalog_remember_cookie_name(): string
{
    return 'UNREALDB_REMEMBER';
}

function catalog_remember_days(array $config = []): int
{
    $days = (int)($config['auth']['remember_days'] ?? 30);
    return max(1, min($days, 365));
}

function catalog_remember_cookie_present(): bool
{
    return isset($_COOKIE[catalog_remember_cookie_name()]) && trim((string)$_COOKIE[catalog_remember_cookie_name()]) !== '';
}

function catalog_remember_cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => catalog_session_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function catalog_remember_ensure_table(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $row = catalog_one(
        $db,
        'SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_remember_tokens"'
    );
    if ((int)($row['c'] ?? 0) !== 1) {
        throw new RuntimeException('The remember-token schema is not migrated. Run php catalog/bin/migrate.php migrate followed by verify.');
    }
    $done = true;
}

function catalog_remember_parse_cookie(): ?array
{
    $raw = trim((string)($_COOKIE[catalog_remember_cookie_name()] ?? ''));
    if ($raw === '' || !str_contains($raw, ':')) {
        return null;
    }
    [$selector, $validator] = explode(':', $raw, 2);
    $selector = trim($selector);
    $validator = trim($validator);
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        return null;
    }
    return [$selector, $validator];
}

function catalog_remember_clear_cookie(): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(catalog_remember_cookie_name(), '', catalog_remember_cookie_options(time() - 3600));
    unset($_COOKIE[catalog_remember_cookie_name()]);
}

function catalog_remember_clear(?PDO $db = null): void
{
    $parts = catalog_remember_parse_cookie();
    if ($db instanceof PDO && $parts !== null) {
        catalog_remember_ensure_table($db);
        $db->prepare('DELETE FROM ue_remember_tokens WHERE selector=?')->execute([$parts[0]]);
    }
    catalog_remember_clear_cookie();
}

function catalog_remember_set_for_user(PDO $db, array $user, array $config = []): void
{
    catalog_remember_ensure_table($db);
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) {
        return;
    }
    if (!empty($user['mfa_enabled_at']) || trim((string)($user['mfa_totp_secret'] ?? '')) !== '') {
        $db->prepare('DELETE FROM ue_remember_tokens WHERE user_id=?')->execute([$userId]);
        catalog_remember_clear_cookie();
        return;
    }

    $days = catalog_remember_days($config);
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $validator);
    $expires = time() + ($days * 86400);
    $db->prepare('DELETE FROM ue_remember_tokens WHERE user_id=? AND expires_at < NOW()')->execute([$userId]);
    $stmt = $db->prepare('INSERT INTO ue_remember_tokens(user_id,selector,token_hash,expires_at) VALUES(?,?,?,?)');
    $stmt->execute([$userId, $selector, $tokenHash, date('Y-m-d H:i:s', $expires)]);
    if (!headers_sent()) {
        setcookie(catalog_remember_cookie_name(), $selector . ':' . $validator, catalog_remember_cookie_options($expires));
        $_COOKIE[catalog_remember_cookie_name()] = $selector . ':' . $validator;
    }
}

function catalog_remember_restore(PDO $db, array $config = []): bool
{
    catalog_start_session();
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        return true;
    }
    $parts = catalog_remember_parse_cookie();
    if ($parts === null) {
        return false;
    }

    catalog_remember_ensure_table($db);
    [$selector, $validator] = $parts;
    $row = catalog_one(
        $db,
        'SELECT t.id token_id,t.token_hash,t.expires_at,u.id,u.username,u.role,u.mfa_enabled_at,u.mfa_totp_secret '
        . 'FROM ue_remember_tokens t JOIN ue_users u ON u.id=t.user_id '
        . 'WHERE t.selector=? AND t.expires_at>NOW() LIMIT 1',
        [$selector]
    );
    if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $validator))) {
        $db->prepare('DELETE FROM ue_remember_tokens WHERE selector=?')->execute([$selector]);
        catalog_remember_clear_cookie();
        return false;
    }
    if (!empty($row['mfa_enabled_at']) || trim((string)($row['mfa_totp_secret'] ?? '')) !== '') {
        $db->prepare('DELETE FROM ue_remember_tokens WHERE user_id=?')->execute([(int)$row['id']]);
        catalog_remember_clear_cookie();
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int)$row['id'], 'username' => (string)$row['username'], 'role' => (string)$row['role']];
    catalog_mark_authenticated_session();
    $_SESSION['catalog_auth_verified_at'] = time();

    $days = catalog_remember_days($config);
    $newValidator = bin2hex(random_bytes(32));
    $newHash = hash('sha256', $newValidator);
    $expires = time() + ($days * 86400);
    $db->prepare('UPDATE ue_remember_tokens SET token_hash=?,expires_at=?,last_used_at=NOW() WHERE id=?')
        ->execute([$newHash, date('Y-m-d H:i:s', $expires), (int)$row['token_id']]);
    if (!headers_sent()) {
        setcookie(catalog_remember_cookie_name(), $selector . ':' . $newValidator, catalog_remember_cookie_options($expires));
        $_COOKIE[catalog_remember_cookie_name()] = $selector . ':' . $newValidator;
    }
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}
