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
$expectedMigrations = 7;

migration_test_expect(!$schema->tableExists('ue_schema_migrations'), 'Legacy baseline unexpectedly contains migration metadata.');
$status = $runner->status();
migration_test_expect(count($status) === $expectedMigrations, 'Unexpected migration count.');
migration_test_expect(count(array_filter($status, static fn(array $row): bool => $row['state'] === 'pending')) === $expectedMigrations, 'Legacy baseline did not report all migrations pending.');

$preview = $runner->migrate(true);
migration_test_expect(count($preview) === $expectedMigrations, 'Dry-run did not report all pending migrations.');
migration_test_expect(!$schema->tableExists('ue_schema_migrations'), 'Dry-run mutated the database.');

$applied = $runner->migrate();
migration_test_expect(count($applied) === $expectedMigrations, 'Migration runner did not apply every pending migration.');
migration_test_expect($schema->tableExists('ue_schema_migrations'), 'Migration metadata table was not created.');
migration_test_expect((int)$db->query('SELECT COUNT(*) FROM ue_schema_migrations')->fetchColumn() === $expectedMigrations, 'Applied migrations were not recorded.');

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

$jobStatus = $schema->column('ue_background_jobs', 'status');
migration_test_expect(is_array($jobStatus) && str_contains(strtolower((string)$jobStatus['COLUMN_TYPE']), "'dead_letter'"), 'Background job status does not support dead letters.');
foreach (['progress_json', 'progress_updated_at', 'last_heartbeat_at', 'recovery_count', 'cancel_requested_at', 'cancel_requested_by', 'cancel_reason', 'dead_lettered_at'] as $column) {
    migration_test_expect($schema->columnExists('ue_background_jobs', $column), 'Missing background-job reliability column: ' . $column);
}
foreach (['idx_ue_background_jobs_cancel', 'idx_ue_background_jobs_dead_letter', 'idx_ue_background_jobs_heartbeat'] as $index) {
    migration_test_expect($schema->indexExists('ue_background_jobs', $index), 'Missing background-job reliability index: ' . $index);
}
foreach (['resource_class', 'resource_limit', 'concurrency_key'] as $column) {
    migration_test_expect($schema->columnExists('ue_background_jobs', $column), 'Missing background-job resource column: ' . $column);
}
foreach (['idx_ue_background_jobs_resource', 'idx_ue_background_jobs_concurrency'] as $index) {
    migration_test_expect($schema->indexExists('ue_background_jobs', $index), 'Missing background-job resource index: ' . $index);
}

/* Verify that deleting a catalog file clears every parser/asset child row. */
$testGameId = (int)$db->query('SELECT id FROM ue_games ORDER BY id LIMIT 1')->fetchColumn();
migration_test_expect($testGameId > 0, 'Fresh schema has no game for cascade verification.');
$insertFile = $db->prepare(
    'INSERT INTO ue_files '
    . '(game_id,package_name,original_name,stored_name,relative_path,extension,file_size,md5,sha1,scan_status) '
    . 'VALUES (?,?,?,?,?,?,?,?,?,"verified")'
);
$insertFile->execute([
    $testGameId,
    'MigrationCascadeTest',
    'MigrationCascadeTest.u',
    'migration-cascade-test.u',
    'storage/migration-cascade-test.u',
    'u',
    1,
    md5('migration-cascade-test'),
    sha1('migration-cascade-test'),
]);
$testFileId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO ue_names(file_id,name_index,name_text,flags) VALUES(?,0,"CascadeName",0)')->execute([$testFileId]);
$db->prepare('INSERT INTO ue_imports(file_id,import_index,class_package,class_name,object_name,outer_index,full_path,root_package,relative_object_path,is_common) VALUES(?,0,"Core","Class","CascadeImport",0,"Core.CascadeImport","Core","CascadeImport",0)')->execute([$testFileId]);
$db->prepare('INSERT INTO ue_exports(file_id,export_index,class_name,object_name,outer_index,local_path,full_path) VALUES(?,0,"Class","CascadeExport",0,"CascadeExport","MigrationCascadeTest.CascadeExport")')->execute([$testFileId]);
$db->prepare('INSERT INTO ue_asset_registry_assets(file_id,object_path,package_name,package_path,asset_name,asset_class) VALUES(?,"/Game/Cascade.Cascade","/Game/Cascade","/Game","Cascade","Class")')->execute([$testFileId]);
$testAssetId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO ue_asset_registry_tags(asset_id,tag_name,tag_value) VALUES(?,"Cascade","1")')->execute([$testAssetId]);
$db->prepare('INSERT INTO ue_asset_registry_dependencies(file_id,source_asset_id,dependency_object_path,dependency_type) VALUES(?,?,"/Game/Dependency","test")')->execute([$testFileId, $testAssetId]);
$db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$testFileId]);
foreach (['ue_names', 'ue_imports', 'ue_exports', 'ue_asset_registry_assets', 'ue_asset_registry_dependencies'] as $table) {
    $statement = $db->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE file_id=?');
    $statement->execute([$testFileId]);
    migration_test_expect((int)$statement->fetchColumn() === 0, 'File deletion left child rows in ' . $table . '.');
}
$tagStatement = $db->prepare('SELECT COUNT(*) FROM ue_asset_registry_tags WHERE asset_id=?');
$tagStatement->execute([$testAssetId]);
migration_test_expect((int)$tagStatement->fetchColumn() === 0, 'File deletion left asset-registry tags behind.');

migration_test_expect($runner->migrate() === [], 'Second migration run was not idempotent.');
$verified = $runner->status();
$runner->assertNoDrift($verified);
migration_test_expect(count(array_filter($verified, static fn(array $row): bool => $row['state'] === 'applied')) === $expectedMigrations, 'Migration status did not report every migration applied.');

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
