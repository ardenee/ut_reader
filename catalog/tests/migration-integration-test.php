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
$expectedMigrations = 20;

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
migration_test_expect($schema->tableExists('ue_package_providers'), 'Package-provider migration is missing.');
migration_test_expect($schema->indexExists('ue_package_providers', 'idx_ue_package_providers_lookup'), 'Package-provider lookup index is missing.');
migration_test_expect($schema->indexExists('ue_package_providers', 'idx_ue_package_providers_file'), 'Package-provider file index is missing.');
migration_test_expect($schema->tableExists('ue_search_documents'), 'Search-document migration is missing.');
foreach (['idx_ue_search_game_primary', 'idx_ue_search_game_secondary', 'idx_ue_search_file', 'ft_ue_search_values'] as $index) {
    migration_test_expect($schema->indexExists('ue_search_documents', $index), 'Search-document index is missing: ' . $index);
}
migration_test_expect($schema->tableExists('ue_dependency_package_summaries'), 'Dependency package summary migration is missing.');
foreach (['idx_ue_dep_summary_game_status', 'idx_ue_dep_summary_package_game', 'idx_ue_dep_summary_provider'] as $index) {
    migration_test_expect($schema->indexExists('ue_dependency_package_summaries', $index), 'Dependency package summary index is missing: ' . $index);
}
migration_test_expect($schema->columnExists('ue_dependency_package_summaries', 'example_required_object_path'), 'Federation example dependency path column is missing.');
migration_test_expect($schema->indexExists('ue_dependency_package_summaries', 'idx_ue_dep_summary_game_package_missing'), 'Federation dependency summary cursor index is missing.');
migration_test_expect($schema->indexExists('ue_federation_peer_files', 'idx_ue_peer_files_inventory_cursor'), 'Federation peer inventory cursor index is missing.');
migration_test_expect($schema->tableExists('ue_game_catalog_stats'), 'Game catalog stats migration is missing.');
migration_test_expect($schema->indexExists('ue_game_catalog_stats', 'idx_ue_game_catalog_stats_updated'), 'Game catalog stats freshness index is missing.');
foreach (['file_count', 'verified_count', 'missing_dependency_count', 'missing_base_game_dependency_count', 'updated_at'] as $column) {
    migration_test_expect($schema->columnExists('ue_game_catalog_stats', $column), 'Game catalog stats column is missing: ' . $column);
}
migration_test_expect($schema->tableExists('ue_source_file_fingerprints'), 'Source fingerprint cache migration is missing.');
foreach (['source_relative_path', 'file_size', 'modified_at', 'quick_fingerprint', 'work_name', 'content_md5', 'matched_file_id', 'last_seen_at'] as $column) {
    migration_test_expect($schema->columnExists('ue_source_file_fingerprints', $column), 'Source fingerprint column is missing: ' . $column);
}
foreach (['uq_ue_source_fingerprint_path', 'idx_ue_source_fingerprint_match', 'idx_ue_source_fingerprint_seen'] as $index) {
    migration_test_expect($schema->indexExists('ue_source_file_fingerprints', $index), 'Source fingerprint index is missing: ' . $index);
}
foreach ([
    'idx_ue_files_game_package_cursor',
    'idx_ue_files_game_original_cursor',
    'idx_ue_files_game_version_cursor',
    'idx_ue_files_game_size_cursor',
    'idx_ue_files_game_compression_cursor',
    'idx_ue_files_game_uploaded_cursor',
] as $index) {
    migration_test_expect($schema->indexExists('ue_files', $index), 'Keyset pagination index is missing: ' . $index);
}
migration_test_expect($schema->columnExists('ue_dependencies', 'resolution_source'), 'Dependency resolution_source column is missing.');
migration_test_expect($schema->columnExists('ue_dependencies', 'resolution_confidence'), 'Dependency resolution_confidence column is missing.');
migration_test_expect($schema->indexExists('ue_dependencies', 'idx_ue_deps_resolution_source'), 'Dependency resolution_source index is missing.');
migration_test_expect($schema->indexExists('ue_dependencies', 'idx_ue_deps_resolution_confidence'), 'Dependency resolution_confidence index is missing.');
migration_test_expect($schema->tableExists('ue_asset_registry_assets'), 'Asset-registry assets table is missing.');
migration_test_expect($schema->tableExists('ue_asset_registry_tags'), 'Asset-registry tags table is missing.');
migration_test_expect($schema->tableExists('ue_asset_registry_dependencies'), 'Asset-registry dependencies table is missing.');
migration_test_expect($schema->tableExists('ue_pak_archives'), 'PAK archive table is missing.');
migration_test_expect($schema->tableExists('ue_pak_entries'), 'PAK entry table is missing.');
migration_test_expect($schema->tableExists('ue_federation_join_requests'), 'Federation join-request upgrade migration is missing.');

foreach ([
    ['ue_files', 'idx_ue_files_game_status_package'],
    ['ue_files', 'idx_ue_files_game_status_original'],
    ['ue_file_package_aliases', 'idx_ue_file_alias_game_original'],
    ['ue_imports', 'idx_ue_imports_root_file'],
    ['ue_exports', 'idx_ue_exports_file_local'],
    ['ue_dependencies', 'idx_ue_deps_required_file'],
] as [$table, $index]) {
    migration_test_expect($schema->indexExists($table, $index), 'Missing catalog scale index: ' . $index);
}

$ue5Profile = $db->query(
    'SELECT id,engine_key,allowed_extensions_json FROM ue_game_profiles '
    . 'WHERE profile_name="UE5 container package profile" LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
migration_test_expect(is_array($ue5Profile), 'UE5 container profile migration did not create the reusable profile.');
migration_test_expect(strtoupper((string)$ue5Profile['engine_key']) === 'UE5', 'UE5 container profile has the wrong engine key.');
$ue5Extensions = json_decode((string)$ue5Profile['allowed_extensions_json'], true);
migration_test_expect(
    is_array($ue5Extensions) && in_array('uasset', $ue5Extensions, true) && in_array('umap', $ue5Extensions, true),
    'UE5 container profile does not accept loose uasset/umap package entries.'
);
$ue5Game = $db->query(
    'SELECT g.id,g.profile_id,p.engine_key FROM ue_games g '
    . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id WHERE g.slug="ue5" LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
migration_test_expect(is_array($ue5Game), 'UE5 default game target was not created.');
migration_test_expect((int)$ue5Game['profile_id'] === (int)$ue5Profile['id'], 'UE5 default game is not assigned to the reusable UE5 profile.');
migration_test_expect(strtoupper((string)$ue5Game['engine_key']) === 'UE5', 'UE5 default game does not resolve to a UE5 profile.');

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
