<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\MigrationRunner;
use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

require_once __DIR__ . '/../bootstrap/autoload.php';

function migration_test_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$dsn = (string)(getenv('UNREALDB_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=unrealdb_test;charset=utf8mb4');
$user = (string)(getenv('UNREALDB_TEST_DB_USER') ?: 'root');
$password = (string)(getenv('UNREALDB_TEST_DB_PASSWORD') ?: 'root');
$db = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$runner = new MigrationRunner($db, __DIR__ . '/../migrations', 5);
$schema = new SchemaInspector($db);

migration_test_expect(!$schema->tableExists('ue_schema_migrations'), 'Legacy baseline unexpectedly contains migration metadata.');
$status = $runner->status();
migration_test_expect(count($status) === 4, 'Unexpected migration count.');
migration_test_expect(count(array_filter($status, static fn(array $row): bool => $row['state'] === 'pending')) === 4, 'Legacy baseline did not report all migrations pending.');

$preview = $runner->migrate(true);
migration_test_expect(count($preview) === 4, 'Dry-run did not report all pending migrations.');
migration_test_expect(!$schema->tableExists('ue_schema_migrations'), 'Dry-run mutated the database.');

$applied = $runner->migrate();
migration_test_expect(count($applied) === 4, 'Migration runner did not apply every pending migration.');
migration_test_expect($schema->tableExists('ue_schema_migrations'), 'Migration metadata table was not created.');
migration_test_expect((int)$db->query('SELECT COUNT(*) FROM ue_schema_migrations')->fetchColumn() === 4, 'Applied migrations were not recorded.');

migration_test_expect($schema->tableExists('ue_remember_tokens'), 'Remember-token migration is missing.');
migration_test_expect($schema->tableExists('ue_file_package_aliases'), 'Package-alias migration is missing.');
migration_test_expect($schema->columnExists('ue_dependencies', 'resolution_source'), 'Dependency resolution_source column is missing.');
migration_test_expect($schema->columnExists('ue_dependencies', 'resolution_confidence'), 'Dependency resolution_confidence column is missing.');
migration_test_expect($schema->indexExists('ue_dependencies', 'idx_ue_deps_resolution_source'), 'Dependency resolution_source index is missing.');
migration_test_expect($schema->indexExists('ue_dependencies', 'idx_ue_deps_resolution_confidence'), 'Dependency resolution_confidence index is missing.');
migration_test_expect($schema->tableExists('ue_asset_registry_assets'), 'Asset-registry assets table is missing.');
migration_test_expect($schema->tableExists('ue_asset_registry_tags'), 'Asset-registry tags table is missing.');
migration_test_expect($schema->tableExists('ue_asset_registry_dependencies'), 'Asset-registry dependencies table is missing.');

$gameId = $schema->column('ue_files', 'game_id');
migration_test_expect(is_array($gameId) && strtoupper((string)$gameId['IS_NULLABLE']) === 'YES', 'ue_files.game_id was not made nullable.');
$scanStatus = $schema->column('ue_files', 'scan_status');
migration_test_expect(is_array($scanStatus) && str_contains(strtolower((string)$scanStatus['COLUMN_TYPE']), "'unverified'"), 'ue_files.scan_status does not support unverified rows.');
foreach (['source_relative_path', 'unverified_queue_key', 'unverified_queue_game_id', 'unverified_queue_name', 'unverified_reason'] as $column) {
    migration_test_expect($schema->columnExists('ue_files', $column), 'Missing unverified staging column: ' . $column);
}
foreach (['uq_ue_files_unverified_queue_key', 'idx_ue_files_scan_status', 'idx_ue_files_unverified_queue'] as $index) {
    migration_test_expect($schema->indexExists('ue_files', $index), 'Missing unverified staging index: ' . $index);
}

migration_test_expect($runner->migrate() === [], 'Second migration run was not idempotent.');
$verified = $runner->status();
$runner->assertNoDrift($verified);
migration_test_expect(count(array_filter($verified, static fn(array $row): bool => $row['state'] === 'applied')) === 4, 'Migration status did not report every migration applied.');

$first = $db->query('SELECT version, checksum FROM ue_schema_migrations ORDER BY version LIMIT 1')->fetch();
migration_test_expect(is_array($first), 'Could not read an applied migration row.');
$db->prepare('UPDATE ue_schema_migrations SET checksum=? WHERE version=?')->execute([str_repeat('0', 64), $first['version']]);
$driftRejected = false;
try {
    $runner->migrate();
} catch (RuntimeException $error) {
    $driftRejected = str_contains($error->getMessage(), 'checksum');
} finally {
    $db->prepare('UPDATE ue_schema_migrations SET checksum=? WHERE version=?')->execute([$first['checksum'], $first['version']]);
}
migration_test_expect($driftRejected, 'Applied migration checksum drift was not rejected.');

fwrite(STDOUT, "Database migration integration tests passed.\n");
