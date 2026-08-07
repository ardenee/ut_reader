#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the command-line utility for migrate.
 * Why: It handles administrator, migration, verification, repair, generation, or worker work that should not execute
 *      as an interactive browser request.
 * Role: CLI/maintenance entry point used from the server shell or operational scripts.
 * Audit: Operational entry point; verify scheduled/manual usage before considering removal.
 */
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

/** @return array{command:string,dry_run:bool,lock_timeout:int} */
function migration_parse_arguments(array $arguments): array
{
    $command = 'status';
    $commandSet = false;
    $dryRun = false;
    $timeout = (int)(getenv('UNREALDB_MIGRATION_LOCK_TIMEOUT') ?: 30);

    for ($index = 0, $count = count($arguments); $index < $count; $index++) {
        $argument = (string)$arguments[$index];
        if ($argument === '--dry-run') {
            $dryRun = true;
            continue;
        }
        if (str_starts_with($argument, '--lock-timeout=')) {
            $timeout = (int)substr($argument, strlen('--lock-timeout='));
            continue;
        }
        if ($argument === '--lock-timeout') {
            $index++;
            if ($index >= $count) {
                throw new InvalidArgumentException('--lock-timeout requires a value.');
            }
            $timeout = (int)$arguments[$index];
            continue;
        }
        if (str_starts_with($argument, '-')) {
            throw new InvalidArgumentException('Unknown migration option: ' . $argument);
        }
        if ($commandSet) {
            throw new InvalidArgumentException('Unexpected migration argument: ' . $argument);
        }
        $command = strtolower(trim($argument));
        $commandSet = true;
    }

    return [
        'command' => $command,
        'dry_run' => $dryRun,
        'lock_timeout' => max(0, min($timeout, 300)),
    ];
}

/** @param list<array<string,mixed>> $rows */
function migration_print_status(array $rows): void
{
    if ($rows === []) {
        fwrite(STDOUT, "No pending migration files found.\n");
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

try {
    $arguments = migration_parse_arguments(array_slice($argv, 1));
    $command = $arguments['command'];
    if (in_array($command, ['help', '--help', '-h'], true)) {
        migration_usage();
        exit(0);
    }
    if (!in_array($command, ['status', 'migrate', 'verify'], true)) {
        throw new InvalidArgumentException('Unknown migration command: ' . $command);
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $runner = new MigrationRunner(
        $db,
        __DIR__ . '/../migrations',
        $arguments['lock_timeout']
    );

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

    $changed = $runner->migrate($arguments['dry_run']);
    if ($arguments['dry_run']) {
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
    migration_usage();
    exit(1);
}
