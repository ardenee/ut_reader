#!/usr/bin/env php
<?php
/**
 * Verifies that the consolidated fresh-install schema, migration boundary and
 * optional live database all agree with baseline 202609240002.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

$withDatabase = in_array('--database', array_slice($argv, 1), true);
$baseline = '202609240002';
$checks = [];
$failures = [];

$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$installPath = $root . '/install.sql';
$install = (string)@file_get_contents($installPath);
$runnerPath = $root . '/src/Infrastructure/Persistence/MigrationRunner.php';
$runner = (string)@file_get_contents($runnerPath);

$record(
    'install_baseline_version',
    str_contains($install, 'Consolidated migration baseline: ' . $baseline),
    'install.sql must identify baseline ' . $baseline
);
$record(
    'runner_baseline_version',
    str_contains($runner, "BASELINE_VERSION = '" . $baseline . "'"),
    'MigrationRunner baseline must equal ' . $baseline
);

$requiredInstallFragments = [
    'CREATE TABLE ue_unverified_game_match_cache',
    'ADD COLUMN parent_job_id',
    'CREATE TABLE ue_job_logging_settings',
    'ADD COLUMN metadata_status',
    'CREATE TABLE ue_unverified_pak_members',
    'dependency_package_key',
    'idx_ue_dependency_required_file',
    'CREATE TABLE ue_geoip_country_ranges',
    'CREATE TABLE ue_access_events',
    'CREATE TABLE ue_site_blocked_ips',
    'CREATE TABLE ue_site_block_feedback',
    "'normal_upload_limit_bytes','2147483648'",
    "'public_upload_max_file_bytes','2147483648'",
    'CREATE TABLE ue_invalid_file_identities',
    'CREATE TABLE ue_package_coverage_cache',
    'CREATE TABLE ue_package_provider_coverage_cache',
    'CREATE TABLE ue_legacy_export_identity_lookup',
    'path_hash_ci BINARY(16) NULL',
    'CREATE TABLE ue_export_path_lookup',
    'CREATE TABLE ue_dependency_identity_lookup',
];
$missingFragments = [];
foreach ($requiredInstallFragments as $fragment) {
    if (!str_contains($install, $fragment)) {
        $missingFragments[] = $fragment;
    }
}
$record(
    'install_contains_current_schema',
    $missingFragments === [],
    $missingFragments === [] ? 'all current baseline fragments present' : implode(', ', $missingFragments)
);

$activeMigrationFiles = [];
foreach (glob($root . '/migrations/*.php') ?: [] as $path) {
    $activeMigrationFiles[] = basename($path);
}
sort($activeMigrationFiles, SORT_STRING);
$record(
    'no_post_baseline_migrations',
    $activeMigrationFiles === [],
    $activeMigrationFiles === [] ? 'none' : implode(', ', $activeMigrationFiles)
);

if ($withDatabase) {
    $db = catalog_db(catalog_config());
    $schema = new SchemaInspector($db);

    $requiredTables = [
        'ue_unverified_game_match_cache',
        'ue_job_logging_settings',
        'ue_unverified_pak_members',
        'ue_geoip_country_ranges',
        'ue_access_events',
        'ue_site_blocked_ips',
        'ue_site_block_feedback',
        'ue_invalid_file_identities',
        'ue_package_coverage_cache',
        'ue_package_provider_coverage_cache',
        'ue_legacy_export_identity_lookup',
        'ue_export_path_lookup',
        'ue_dependency_identity_lookup',
    ];
    $missingTables = [];
    foreach ($requiredTables as $table) {
        if (!$schema->tableExists($table)) {
            $missingTables[] = $table;
        }
    }
    $record(
        'database_current_tables',
        $missingTables === [],
        $missingTables === [] ? 'all present' : implode(', ', $missingTables)
    );

    $requiredColumns = [
        ['ue_background_jobs', 'parent_job_id'],
        ['ue_background_jobs', 'workflow_unit_key'],
        ['ue_files', 'metadata_status'],
        ['ue_files', 'metadata_error'],
        ['ue_files', 'metadata_updated_at'],
        ['ue_files', 'dependency_package_key'],
        ['ue_files', 'dependency_original_stem_key'],
        ['ue_base_game_files', 'dependency_package_key'],
        ['ue_base_game_files', 'dependency_original_stem_key'],
        ['ue_geoip_country_ranges', 'subdivision_code'],
        ['ue_geoip_country_ranges', 'city_name'],
        ['ue_geoip_country_ranges', 'latitude'],
        ['ue_geoip_country_ranges', 'longitude'],
        ['ue_geoip_country_ranges', 'accuracy_radius_km'],
        ['ue_legacy_export_identity_lookup', 'path_hash_ci'],
    ];
    $missingColumns = [];
    foreach ($requiredColumns as [$table, $column]) {
        if (!$schema->columnExists($table, $column)) {
            $missingColumns[] = $table . '.' . $column;
        }
    }
    $record(
        'database_current_columns',
        $missingColumns === [],
        $missingColumns === [] ? 'all present' : implode(', ', $missingColumns)
    );

    $requiredIndexes = [
        ['ue_background_jobs', 'idx_ue_background_jobs_parent_status'],
        ['ue_background_jobs', 'uq_ue_background_jobs_parent_unit'],
        ['ue_files', 'idx_ue_files_game_dependency_package_key'],
        ['ue_files', 'idx_ue_files_game_dependency_stem_key'],
        ['ue_base_game_files', 'idx_ue_base_game_dependency_package_key'],
        ['ue_base_game_files', 'idx_ue_base_game_dependency_stem_key'],
        ['ue_dependency_links', 'idx_ue_dependency_required_file'],
        ['ue_dependency_links', 'idx_ue_dependency_resolved_file'],
        ['ue_dependency_package_summaries', 'idx_ue_dep_summary_game_missing_package'],
        ['ue_legacy_export_identity_lookup', 'idx_legacy_verify_identity'],
        ['ue_legacy_export_identity_lookup', 'idx_legacy_verify_path'],
        ['ue_export_path_lookup', 'idx_export_path_ci'],
        ['ue_export_path_lookup', 'idx_export_path_file_hash'],
        ['ue_dependency_identity_lookup', 'idx_dependency_verify_identity'],
        ['ue_dependency_identity_lookup', 'idx_dependency_path_ci'],
    ];
    $missingIndexes = [];
    foreach ($requiredIndexes as [$table, $index]) {
        if (!$schema->indexExists($table, $index)) {
            $missingIndexes[] = $table . '.' . $index;
        }
    }
    $record(
        'database_current_indexes',
        $missingIndexes === [],
        $missingIndexes === [] ? 'all present' : implode(', ', $missingIndexes)
    );
}

echo json_encode([
    'ok' => $failures === [],
    'baseline' => $baseline,
    'database_checked' => $withDatabase,
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 2);
