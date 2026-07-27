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
$expectedMigrations = 26;

migration_test_expect(!$schema->tableExists('ue_schema_migrations'), 'Legacy baseline unexpectedly contains migration metadata.');
$status = $runner->status();
migration_test_expect(count($status) === $expectedMigrations, 'Unexpected migration count.');
migration_test_expect(
    count(array_filter($status, static fn(array $row): bool => $row['state'] === 'pending')) === $expectedMigrations,
    'Legacy baseline did not report all migrations pending.'
);

$preview = $runner->migrate(true);
migration_test_expect(count($preview) === $expectedMigrations, 'Dry-run did not report all pending migrations.');
migration_test_expect(!$schema->tableExists('ue_schema_migrations'), 'Dry-run mutated the database.');

$applied = $runner->migrate();
migration_test_expect(count($applied) === $expectedMigrations, 'Migration runner did not apply every pending migration.');
migration_test_expect($schema->tableExists('ue_schema_migrations'), 'Migration metadata table was not created.');
migration_test_expect(
    (int)$db->query('SELECT COUNT(*) FROM ue_schema_migrations')->fetchColumn() === $expectedMigrations,
    'Applied migrations were not recorded.'
);

foreach ([
    'ue_remember_tokens',
    'ue_file_package_aliases',
    'ue_package_providers',
    'ue_search_documents',
    'ue_dependency_package_summaries',
    'ue_game_catalog_stats',
    'ue_source_file_fingerprints',
    'ue_asset_registry_assets',
    'ue_asset_registry_tags',
    'ue_asset_registry_dependencies',
    'ue_pak_archives',
    'ue_pak_entries',
    'ue_federation_join_requests',
    'ue_exact_count_telemetry',
    'ue_exact_count_query_plans',
    'ue_exact_count_cache',
    'ue_background_job_search',
    'ue_request_performance',
] as $table) {
    migration_test_expect($schema->tableExists($table), 'Required migrated table is missing: ' . $table);
}

foreach ([
    ['ue_package_providers', 'idx_ue_package_providers_lookup'],
    ['ue_package_providers', 'idx_ue_package_providers_file'],
    ['ue_search_documents', 'idx_ue_search_game_primary'],
    ['ue_search_documents', 'idx_ue_search_game_secondary'],
    ['ue_search_documents', 'idx_ue_search_file'],
    ['ue_search_documents', 'ft_ue_search_values'],
    ['ue_dependency_package_summaries', 'idx_ue_dep_summary_game_status'],
    ['ue_dependency_package_summaries', 'idx_ue_dep_summary_package_game'],
    ['ue_dependency_package_summaries', 'idx_ue_dep_summary_provider'],
    ['ue_dependency_package_summaries', 'idx_ue_dep_summary_game_package_missing'],
    ['ue_federation_peer_files', 'idx_ue_peer_files_inventory_cursor'],
    ['ue_game_catalog_stats', 'idx_ue_game_catalog_stats_updated'],
    ['ue_source_file_fingerprints', 'uq_ue_source_fingerprint_path'],
    ['ue_source_file_fingerprints', 'idx_ue_source_fingerprint_match'],
    ['ue_source_file_fingerprints', 'idx_ue_source_fingerprint_seen'],
    ['ue_federation_requests', 'idx_ue_federation_requests_history'],
    ['ue_federation_requests', 'idx_ue_federation_requests_peer_history'],
    ['ue_federation_request_items', 'idx_ue_federation_request_items_history'],
    ['ue_federation_transfer_jobs', 'idx_ue_federation_transfer_history'],
    ['ue_federation_transfer_jobs', 'idx_ue_federation_transfer_peer_history'],
    ['ue_federation_transfer_logs', 'idx_ue_federation_logs_history'],
    ['ue_federation_transfer_logs', 'idx_ue_federation_logs_level_history'],
    ['ue_federation_transfer_logs', 'idx_ue_federation_logs_peer_history'],
    ['ue_dependencies', 'idx_ue_deps_resolution_source'],
    ['ue_dependencies', 'idx_ue_deps_resolution_confidence'],
    ['ue_dependencies', 'idx_ue_deps_missing_package_cursor'],
    ['ue_dependencies', 'idx_ue_deps_missing_file_cursor'],
    ['ue_files', 'idx_ue_files_game_status_package'],
    ['ue_files', 'idx_ue_files_game_status_original'],
    ['ue_file_package_aliases', 'idx_ue_file_alias_game_original'],
    ['ue_imports', 'idx_ue_imports_root_file'],
    ['ue_exports', 'idx_ue_exports_file_local'],
    ['ue_dependencies', 'idx_ue_deps_required_file'],
    ['ue_background_jobs', 'idx_ue_background_jobs_cancel'],
    ['ue_background_jobs', 'idx_ue_background_jobs_dead_letter'],
    ['ue_background_jobs', 'idx_ue_background_jobs_heartbeat'],
    ['ue_background_jobs', 'idx_ue_background_jobs_resource'],
    ['ue_background_jobs', 'idx_ue_background_jobs_concurrency'],
    ['ue_background_jobs', 'idx_ue_background_jobs_queue_id'],
    ['ue_background_jobs', 'idx_ue_background_jobs_queue_status_id'],
    ['ue_federation_peer_files', 'idx_ue_peer_files_conflict_cursor'],
    ['ue_exact_count_telemetry', 'uq_ue_exact_count_metric_context'],
    ['ue_exact_count_telemetry', 'idx_ue_exact_count_last_seen'],
    ['ue_exact_count_telemetry', 'idx_ue_exact_count_max_duration'],
    ['ue_exact_count_telemetry', 'idx_ue_exact_count_metric'],
    ['ue_exact_count_query_plans', 'uq_ue_exact_plan_metric_context'],
    ['ue_exact_count_query_plans', 'idx_ue_exact_plan_assessment'],
    ['ue_exact_count_query_plans', 'idx_ue_exact_plan_captured'],
    ['ue_exact_count_query_plans', 'idx_ue_exact_plan_metric'],
    ['ue_exact_count_cache', 'idx_ue_exact_count_cache_query'],
    ['ue_exact_count_cache', 'idx_ue_exact_count_cache_expiry'],
    ['ue_background_job_search', 'idx_ue_job_search_queue_job'],
    ['ue_background_job_search', 'idx_ue_job_search_status_job'],
    ['ue_background_job_search', 'idx_ue_job_search_updated'],
    ['ue_background_job_search', 'ft_ue_job_search_text'],
    ['ue_request_performance', 'uq_ue_request_performance_route'],
    ['ue_request_performance', 'idx_ue_request_performance_slow'],
    ['ue_request_performance', 'idx_ue_request_performance_seen'],
] as [$table, $index]) {
    migration_test_expect($schema->indexExists($table, $index), 'Required migrated index is missing: ' . $table . '.' . $index);
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

foreach (['file_count', 'verified_count', 'missing_dependency_count', 'missing_base_game_dependency_count', 'updated_at'] as $column) {
    migration_test_expect($schema->columnExists('ue_game_catalog_stats', $column), 'Game catalog stats column is missing: ' . $column);
}
foreach (['source_relative_path', 'file_size', 'modified_at', 'quick_fingerprint', 'work_name', 'content_md5', 'matched_file_id', 'last_seen_at'] as $column) {
    migration_test_expect($schema->columnExists('ue_source_file_fingerprints', $column), 'Source fingerprint column is missing: ' . $column);
}
foreach (['resolution_source', 'resolution_confidence'] as $column) {
    migration_test_expect($schema->columnExists('ue_dependencies', $column), 'Dependency resolution column is missing: ' . $column);
}
migration_test_expect(
    $schema->columnExists('ue_dependency_package_summaries', 'example_required_object_path'),
    'Federation example dependency path column is missing.'
);
foreach (['metric_key', 'context_hash', 'sample_count', 'total_duration_us', 'max_duration_us', 'last_duration_us', 'slow_sample_count', 'last_result_count'] as $column) {
    migration_test_expect($schema->columnExists('ue_exact_count_telemetry', $column), 'Exact-count telemetry column is missing: ' . $column);
}
foreach (['metric_key', 'context_hash', 'query_hash', 'query_sql', 'plan_json', 'estimated_rows', 'full_scan_rows', 'selected_keys', 'assessment', 'recommendation', 'captured_at'] as $column) {
    migration_test_expect($schema->columnExists('ue_exact_count_query_plans', $column), 'Exact-count query-plan column is missing: ' . $column);
}
foreach (['cache_key', 'query_hash', 'result_count', 'expires_at', 'generated_at', 'hit_count'] as $column) {
    migration_test_expect($schema->columnExists('ue_exact_count_cache', $column), 'Exact-count cache column is missing: ' . $column);
}
foreach (['job_id', 'queue_name', 'job_type', 'source_status', 'search_text', 'source_updated_at'] as $column) {
    migration_test_expect($schema->columnExists('ue_background_job_search', $column), 'Background-job search column is missing: ' . $column);
}
foreach (['route_key', 'method', 'sample_count', 'total_duration_us', 'total_sql_us', 'max_duration_us', 'last_query_count', 'last_status'] as $column) {
    migration_test_expect($schema->columnExists('ue_request_performance', $column), 'Request-performance column is missing: ' . $column);
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
foreach (['progress_json', 'progress_updated_at', 'last_heartbeat_at', 'recovery_count', 'cancel_requested_at', 'cancel_requested_by', 'cancel_reason', 'dead_lettered_at', 'resource_class', 'resource_limit', 'concurrency_key'] as $column) {
    migration_test_expect($schema->columnExists('ue_background_jobs', $column), 'Missing background-job column: ' . $column);
}

migration_test_expect($runner->migrate() === [], 'Second migration run was not idempotent.');
$verified = $runner->status();
$runner->assertNoDrift($verified);
migration_test_expect(
    count(array_filter($verified, static fn(array $row): bool => $row['state'] === 'applied')) === $expectedMigrations,
    'Migration status did not report every migration applied.'
);

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
