#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the command-line utility for create admin.
 * Why: It handles administrator, migration, verification, repair, generation, or worker work that should not execute
 *      as an interactive browser request.
 * Role: CLI/maintenance entry point used from the server shell or operational scripts.
 * Audit: Operational entry point; verify scheduled/manual usage before considering removal.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupport.php';

function admin_bootstrap_option(array $options, string $name): string
{
    $value = trim((string)($options[$name] ?? ''));
    if ($value === '') {
        fwrite(STDERR, "Missing required --{$name} option.\n");
        exit(1);
    }
    return $value;
}

function admin_bootstrap_password(): string
{
    fwrite(STDOUT, 'Password: ');
    $password = fgets(STDIN);
    if ($password === false) {
        fwrite(STDERR, "Could not read password from STDIN.\n");
        exit(1);
    }

    $password = rtrim($password, "\r\n");
    if (strlen($password) < 12) {
        fwrite(STDERR, "Password must be at least 12 characters.\n");
        exit(1);
    }

    return $password;
}

$options = getopt('', ['username:']);
$username = admin_bootstrap_option($options, 'username');
if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)) {
    fwrite(STDERR, "Username must use 3-80 letters, numbers, dots, underscores, or hyphens.\n");
    exit(1);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $existing = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_users WHERE role="admin"')['c'] ?? 0);
    if ($existing > 0) {
        fwrite(STDERR, "An administrator already exists. Refusing to create another bootstrap account.\n");
        exit(1);
    }

    $password = admin_bootstrap_password();
    $statement = $db->prepare('INSERT INTO ue_users(username, password_hash, role) VALUES (?, ?, "admin")');
    $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);

    fwrite(STDOUT, "Administrator '{$username}' created.\n");
    exit(0);
} catch (Throwable $exception) {
    error_log('[UnrealDB create-admin] ' . get_class($exception) . ': ' . $exception->getMessage());
    fwrite(STDERR, "Administrator creation failed. Check the server error log.\n");
    exit(1);
}
