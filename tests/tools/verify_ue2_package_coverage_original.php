<?php
declare(strict_types=1);
/**
 * Read-only original Unreal II / AutoTurretFX UE2 coverage regression.
 * Run with production catalog configuration, or override the DB host and
 * metadata storage root for a read-only DEV session with exact copied fixtures.
 * No files, background jobs or database records are modified.
 */
$root = dirname(__DIR__, 2);
require_once $root . '/catalog/lib/CatalogSupport.php';
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageSupersetAnalyzer;

$config = catalog_config();
$host = trim((string)(getenv('UE2_COVERAGE_DB_HOST') ?: ''));
$storage = trim((string)(getenv('UE2_COVERAGE_STORAGE_ROOT') ?: ''));
if ($host !== '') { $config['db']['host'] = $host; }
if ($storage !== '') { $config['storage_path'] = $storage; }
$db = catalog_db($config);
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    $result = PdoPackageSupersetAnalyzer::analyze($db, 2, 'AutoTurretFX', $config);
    $paths = (array)($result['required_object_paths'] ?? []);
    sort($paths, SORT_STRING);
    $providers = [];
    foreach ((array)($result['providers'] ?? []) as $provider) {
        $providers[(int)($provider['file_id'] ?? 0)] = $provider;
    }
    $valid = (int)($result['consumer_count'] ?? 0) === 1
        && $paths === ['ParticleSalamander2', 'ParticleSalamander3']
        && (int)($result['required_object_count'] ?? 0) === 2
        && (int)($providers[149589]['matched_count'] ?? -1) === 2
        && (int)($providers[149589]['missing_count'] ?? -1) === 0
        && (int)($providers[111224]['matched_count'] ?? -1) === 0
        && (int)($providers[111224]['missing_count'] ?? -1) === 2;
    $evidence = [
        'pass' => $valid,
        'game_id' => 2,
        'source_consumer_file_id' => 149516,
        'required_object_paths' => $paths,
        'providers' => [
            111224 => [
                'matched' => (int)($providers[111224]['matched_count'] ?? -1),
                'missing' => (int)($providers[111224]['missing_count'] ?? -1),
            ],
            149589 => [
                'matched' => (int)($providers[149589]['matched_count'] ?? -1),
                'missing' => (int)($providers[149589]['missing_count'] ?? -1),
            ],
        ],
        'physical_runtime_provider_selected' => false,
        'production_changes' => false,
    ];
    $db->rollBack();
    echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($valid ? 0 : 2);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
