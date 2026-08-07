<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies migration integration behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\MigrationRunner;

require_once __DIR__ . '/../bootstrap/autoload.php';

function baseline_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$dsn = trim((string)getenv('UNREALDB_TEST_DSN'));
$user = (string)getenv('UNREALDB_TEST_DB_USER');
$password = (string)getenv('UNREALDB_TEST_DB_PASSWORD');
if ($dsn === '') {
    throw new RuntimeException('UNREALDB_TEST_DSN is required.');
}

$db = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$tableExists = static function (string $table) use ($db): bool {
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables '
        . 'WHERE table_schema=DATABASE() AND table_name=?'
    );
    $statement->execute([$table]);
    return (int)$statement->fetchColumn() === 1;
};

foreach ([
    'ue_schema_migrations',
    'ue_file_metadata',
    'ue_terms',
    'ue_export_lookup',
    'ue_dependency_links',
    'ue_package_providers',
    'ue_dependency_package_summaries',
    'ue_game_catalog_stats',
] as $table) {
    baseline_expect($tableExists($table), 'Consolidated baseline is missing table ' . $table . '.');
}
baseline_expect(!$tableExists('ue_search_documents'), 'Obsolete ue_search_documents table returned.');

$migrationFiles = glob(__DIR__ . '/../migrations/*.php') ?: [];
baseline_expect($migrationFiles === [], 'Historical migration PHP files remain after consolidation.');

$runner = new MigrationRunner($db, __DIR__ . '/../migrations', 0);
$runner->assertNoDrift($runner->status());
baseline_expect($runner->migrate(true) === [], 'Fresh baseline unexpectedly has pending migrations.');

$version = '202607180001';
$insert = $db->prepare(
    'INSERT INTO ue_schema_migrations '
    . '(version,migration,description,checksum,batch,execution_ms,applied_at) '
    . 'VALUES (?, ?, ?, ?, 1, 0, NOW())'
);
$insert->execute([$version, 'remember_login', 'Archived baseline test', str_repeat('a', 64)]);
try {
    $rows = $runner->status();
    $archived = array_values(array_filter(
        $rows,
        static fn(array $row): bool => (string)$row['version'] === $version
    ));
    baseline_expect(count($archived) === 1, 'Archived baseline migration was not reported.');
    baseline_expect((string)$archived[0]['state'] === 'archived', 'Baseline migration was not archived.');
    $runner->assertNoDrift($rows);
} finally {
    $delete = $db->prepare('DELETE FROM ue_schema_migrations WHERE version=?');
    $delete->execute([$version]);
}

echo "Consolidated schema baseline tests passed.\n";
