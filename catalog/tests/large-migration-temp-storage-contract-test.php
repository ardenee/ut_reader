<?php
declare(strict_types=1);

function large_migration_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$migration = file_get_contents(__DIR__ . '/../migrations/202607270003_search_documents.php');
large_migration_expect(is_string($migration), 'Search-document migration could not be read.');
large_migration_expect(
    hash('sha256', $migration) === '99494f3c0cfa76a0860265055ce1855d933aebbd54568294b0ba4c4dcdb86117'
        || str_contains($migration, "'version' => '202607270003'"),
    'Released search-document migration is unavailable.'
);

$executor = file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/SearchDocumentMigrationExecutor.php');
large_migration_expect(is_string($executor), 'Search-document migration executor could not be read.');
large_migration_expect(
    str_contains($executor, 'WHERE f.id>? AND f.id<=?')
        && str_contains($executor, 'WHERE i.id>? AND i.id<=?')
        && str_contains($executor, 'WHERE e.id>? AND e.id<=?')
        && str_contains($executor, 'ON DUPLICATE KEY UPDATE'),
    'Search-document migration is not restart-safe and bounded by source-ID ranges.'
);

$runner = file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/MigrationRunner.php');
large_migration_expect(is_string($runner), 'Migration runner could not be read.');
large_migration_expect(
    str_contains($runner, '$executionOverrides')
        && str_contains($runner, '$override($this->db, $inspector, $migration);'),
    'Migration runner does not support checksum-preserving execution overrides.'
);

$inspector = file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/SchemaInspector.php');
large_migration_expect(is_string($inspector), 'Schema inspector could not be read.');
large_migration_expect(
    str_contains($inspector, 'UNREALDB_DEFER_INDEXES')
        && str_contains($inspector, 'indexDeferred('),
    'Selected large indexes cannot be deferred safely.'
);

$cli = file_get_contents(__DIR__ . '/../bin/migrate.php');
large_migration_expect(is_string($cli), 'Migration CLI could not be read.');
large_migration_expect(
    str_contains($cli, '--defer-search-indexes')
        && str_contains($cli, '--search-backfill-batch=25000')
        && str_contains($cli, "['202607270003' => $searchExecutor]"),
    'Migration CLI does not activate the bounded search backfill and index deferral.'
);

$indexCli = file_get_contents(__DIR__ . '/../bin/search-document-indexes.php');
large_migration_expect(is_string($indexCli), 'Search-document index CLI could not be read.');
large_migration_expect(
    str_contains($indexCli, '@@global.tmpdir')
        && str_contains($indexCli, '@@global.innodb_tmpdir')
        && str_contains($indexCli, 'Refusing to build large search indexes'),
    'Search-document index builds are not guarded against system-drive temporary storage.'
);

fwrite(STDOUT, "Large migration temp-storage contract tests passed.\n");
