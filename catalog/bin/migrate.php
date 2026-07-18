#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\MigrationRunner;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';

function migration_usage(): void
{
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php catalog/bin/migrate.php status\n");
    fwrite(STDOUT, "  php catalog/bin/migrate.php migrate [--dry-run] [--lock-timeout=30]\n");
    fwrite(STDOUT, "  php catalog/bin/migrate.php verify\n");
}

/** @param list<array<string,mixed>> $rows */
function migration_print_status(array $rows): void
{
    if ($rows === []) {
        fwrite(STDOUT, "No migration files found.\n");
        return;
    }

    fwrite(STDOUT, str_pad('VERSION', 16) . str_pad('STATE', 20) . str_pad('BATCH', 8) . "DESCRIPTION\n");
    foreach ($rows as $row) {
        $batch = $row['batch'] === null ? '-' : (string)$row['batch'];
        fwrite(
            STDOUT,
            str_pad((string)$row['version'], 16)
            . str_pad((string)$row['state'], 20)
            . str_pad($batch, 8)
            . (string)$row['description']
            . "\n"
        );
    }
}

$command = strtolower(trim((string)($argv[1] ?? 'status')));
if (in_array($command, ['help', '--help', '-h'], true)) {
    migration_usage();
    exit(0);
}
if (!in_array($command, ['status', 'migrate', 'verify'], true)) {
    fwrite(STDERR, "Unknown migration command: {$command}\n");
    migration_usage();
    exit(1);
}

$options = getopt('', ['dry-run', 'lock-timeout:']);
$dryRun = array_key_exists('dry-run', $options);
$configuredTimeout = (int)($options['lock-timeout'] ?? (getenv('UNREALDB_MIGRATION_LOCK_TIMEOUT') ?: 30));
$lockTimeout = max(0, min($configuredTimeout, 300));

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $runner = new MigrationRunner($db, __DIR__ . '/../migrations', $lockTimeout);

    if ($command === 'status') {
        migration_print_status($runner->status());
        exit(0);
    }

    if ($command === 'verify') {
        $status = $runner->status();
        $runner->assertNoDrift($status);
        $pending = array_values(array_filter(
            $status,
            static fn(array $row): bool => $row['state'] === 'pending'
        ));
        migration_print_status($status);
        if ($pending !== []) {
            fwrite(STDERR, count($pending) . " migration(s) are pending.\n");
            exit(2);
        }
        fwrite(STDOUT, "Database migration state verified.\n");
        exit(0);
    }

    $changed = $runner->migrate($dryRun);
    if ($dryRun) {
        migration_print_status($changed);
        fwrite(STDOUT, count($changed) . " migration(s) would be applied.\n");
        exit(0);
    }

    if ($changed === []) {
        fwrite(STDOUT, "Database is already up to date.\n");
        exit(0);
    }

    migration_print_status($changed);
    fwrite(STDOUT, count($changed) . " migration(s) applied.\n");
    exit(0);
} catch (Throwable $exception) {
    error_log('[UnrealDB migrations] ' . get_class($exception) . ': ' . $exception->getMessage());
    fwrite(STDERR, "Migration command failed: " . $exception->getMessage() . "\n");
    exit(1);
}
