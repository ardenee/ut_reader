#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\MigrationRunner;
use UnrealDb\Catalog\Infrastructure\Persistence\SearchDocumentMigrationExecutor;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';

function migration_usage(): void
{
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php catalog/bin/migrate.php status\n");
    fwrite(STDOUT, "  php catalog/bin/migrate.php migrate [--dry-run] [--lock-timeout=30] [--search-backfill-batch=25000] [--defer-search-indexes]\n");
    fwrite(STDOUT, "  php catalog/bin/migrate.php verify\n");
}

/** @return array{command:string,dry_run:bool,lock_timeout:int,search_backfill_batch:int,defer_search_indexes:bool} */
function migration_parse_arguments(array $arguments): array
{
    $command = 'status';
    $commandSet = false;
    $dryRun = false;
    $deferSearchIndexes = false;
    $timeout = (int)(getenv('UNREALDB_MIGRATION_LOCK_TIMEOUT') ?: 30);
    $searchBackfillBatch = (int)(getenv('UNREALDB_SEARCH_BACKFILL_BATCH') ?: 25000);

    for ($index = 0, $count = count($arguments); $index < $count; $index++) {
        $argument = (string)$arguments[$index];
        if ($argument === '--dry-run') {
            $dryRun = true;
            continue;
        }
        if ($argument === '--defer-search-indexes') {
            $deferSearchIndexes = true;
            continue;
        }
        if (str_starts_with($argument, '--search-backfill-batch=')) {
            $searchBackfillBatch = (int)substr($argument, strlen('--search-backfill-batch='));
            continue;
        }
        if ($argument === '--search-backfill-batch') {
            $index++;
            if ($index >= $count) {
                throw new InvalidArgumentException('--search-backfill-batch requires a value.');
            }
            $searchBackfillBatch = (int)$arguments[$index];
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
        'search_backfill_batch' => max(1000, min($searchBackfillBatch, 250000)),
        'defer_search_indexes' => $deferSearchIndexes,
    ];
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

/** @return array{tmpdir:string,innodb_tmpdir:string,datadir:string} */
function migration_database_paths(PDO $db): array
{
    $row = $db->query(
        'SELECT @@global.tmpdir tmpdir,@@global.innodb_tmpdir innodb_tmpdir,@@global.datadir datadir'
    )->fetch(PDO::FETCH_ASSOC);
    return [
        'tmpdir' => trim((string)($row['tmpdir'] ?? '')),
        'innodb_tmpdir' => trim((string)($row['innodb_tmpdir'] ?? '')),
        'datadir' => trim((string)($row['datadir'] ?? '')),
    ];
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

    if ($arguments['defer_search_indexes']) {
        putenv('UNREALDB_DEFER_INDEXES=' . implode(',', [
            'idx_ue_search_game_primary',
            'idx_ue_search_game_secondary',
            'idx_ue_search_file',
            'ft_ue_search_values',
        ]));
    }

    $lastProgress = [];
    $searchExecutor = static function (PDO $database, $schema, array $migration) use ($arguments, &$lastProgress): void {
        fwrite(STDOUT, "Running migration 202607270003 with bounded, restart-safe source-ID batches.\n");
        SearchDocumentMigrationExecutor::execute(
            $database,
            $schema,
            $arguments['search_backfill_batch'],
            static function (string $source, int $completed, int $maximum) use (&$lastProgress): void {
                $percent = $maximum > 0 ? (int)floor(($completed / $maximum) * 100) : 100;
                $previous = (int)($lastProgress[$source] ?? -5);
                if ($completed >= $maximum || $percent >= $previous + 5) {
                    $lastProgress[$source] = $percent;
                    fwrite(STDOUT, sprintf("  %-8s %3d%% (%d / %d source IDs)\n", $source, $percent, $completed, $maximum));
                }
            }
        );
    };

    $runner = new MigrationRunner(
        $db,
        __DIR__ . '/../migrations',
        $arguments['lock_timeout'],
        ['202607270003' => $searchExecutor]
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

    $statusBefore = $runner->status();
    $searchMigrationPending = count(array_filter(
        $statusBefore,
        static fn(array $row): bool => (string)$row['version'] === '202607270003' && (string)$row['state'] === 'pending'
    )) > 0;
    if ($searchMigrationPending && !$arguments['dry_run']) {
        $paths = migration_database_paths($db);
        fwrite(STDOUT, "MariaDB temporary/data paths before search backfill:\n");
        fwrite(STDOUT, '  tmpdir:        ' . ($paths['tmpdir'] !== '' ? $paths['tmpdir'] : '(server default)') . "\n");
        fwrite(STDOUT, '  innodb_tmpdir: ' . ($paths['innodb_tmpdir'] !== '' ? $paths['innodb_tmpdir'] : '(uses tmpdir)') . "\n");
        fwrite(STDOUT, '  datadir:       ' . ($paths['datadir'] !== '' ? $paths['datadir'] : '(unknown)') . "\n");
        if ($arguments['defer_search_indexes']) {
            fwrite(STDOUT, "Search-document secondary/FULLTEXT indexes are deferred for this run.\n");
        }
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
    if ($arguments['defer_search_indexes']) {
        fwrite(STDOUT, "Search-document indexes remain deferred. Build them after moving MariaDB temporary work to a drive with adequate free space:\n");
        fwrite(STDOUT, "  php catalog/bin/search-document-indexes.php status\n");
        fwrite(STDOUT, "  php catalog/bin/search-document-indexes.php build\n");
    }
    exit(0);
} catch (Throwable $exception) {
    error_log('[UnrealDB migrations] ' . get_class($exception) . ': ' . $exception->getMessage());
    fwrite(STDERR, "Migration command failed: " . $exception->getMessage() . "\n");
    migration_usage();
    exit(1);
}
