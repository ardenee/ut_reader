<?php
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
        'secure' => catalog_security_is_https(),
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

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ue_remember_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  selector CHAR(24) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_remember_tokens_selector (selector),
  KEY idx_ue_remember_tokens_user (user_id),
  KEY idx_ue_remember_tokens_expires (expires_at),
  CONSTRAINT fk_ue_remember_tokens_user FOREIGN KEY (user_id) REFERENCES ue_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

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

    $days = catalog_remember_days($config);
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $validator);
    $expires = time() + ($days * 86400);
    $expiresSql = date('Y-m-d H:i:s', $expires);

    $db->prepare('DELETE FROM ue_remember_tokens WHERE user_id=? AND expires_at < NOW()')->execute([$userId]);
    $stmt = $db->prepare('INSERT INTO ue_remember_tokens(user_id,selector,token_hash,expires_at) VALUES(?,?,?,?)');
    $stmt->execute([$userId, $selector, $tokenHash, $expiresSql]);

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
        'SELECT t.id token_id, t.token_hash, t.expires_at, u.id, u.username, u.role
         FROM ue_remember_tokens t
         JOIN ue_users u ON u.id=t.user_id
         WHERE t.selector=? AND t.expires_at > NOW()
         LIMIT 1',
        [$selector]
    );

    if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $validator))) {
        $db->prepare('DELETE FROM ue_remember_tokens WHERE selector=?')->execute([$selector]);
        catalog_remember_clear_cookie();
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$row['id'],
        'username' => (string)$row['username'],
        'role' => (string)$row['role'],
    ];

    $days = catalog_remember_days($config);
    $newValidator = bin2hex(random_bytes(32));
    $newHash = hash('sha256', $newValidator);
    $expires = time() + ($days * 86400);
    $db->prepare('UPDATE ue_remember_tokens SET token_hash=?, expires_at=?, last_used_at=NOW() WHERE id=?')
        ->execute([$newHash, date('Y-m-d H:i:s', $expires), (int)$row['token_id']]);

    if (!headers_sent()) {
        setcookie(catalog_remember_cookie_name(), $selector . ':' . $newValidator, catalog_remember_cookie_options($expires));
        $_COOKIE[catalog_remember_cookie_name()] = $selector . ':' . $newValidator;
    }

    return ($_SESSION['user']['role'] ?? '') === 'admin';
}
