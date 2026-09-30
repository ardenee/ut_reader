<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5StagingRegistrationRepository;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ClassicSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader;

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

$checks = [];
$failures = [];
$check = static function (string $name, bool $ok) use (&$checks, &$failures): void {
    $checks[$name] = $ok;
    if (!$ok) { $failures[] = $name; }
};
$read = static fn(string $relative): string => (string)file_get_contents($root . '/' . $relative);
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-uedb5-registration-' . bin2hex(random_bytes(5));
$pdo = new PDO('sqlite::memory:');
$writer = new Uedb5MetadataSnapshotWriter($tempRoot);
$repo = new PdoUedb5StagingRegistrationRepository($pdo, $tempRoot);

try {
    $classic = [
        'file' => ['id' => 510001, 'game_id' => 7, 'package_name' => '/Game/Test/Classic', 'original_name' => 'Classic.uasset'],
        'package_family' => Uedb5Ue5ClassicSnapshotBuilder::PACKAGE_FAMILY,
        'source_policy' => Uedb5Ue5ClassicSnapshotBuilder::SOURCE_POLICY,
        'section_schemas' => [],
        'sections' => [
            'names' => [['index' => 0, 'text' => 'Classic']],
            'imports' => [['index' => 0]],
            'exports' => [['index' => 0], ['index' => 1]],
        ],
    ];
    $classicWritten = $writer->write($classic, 1);
    $classicRegistration = $repo->inspect(7, 510001);
    $check('classic_staging_registration_is_format_5', (int)$classicRegistration['format_version'] === 5);
    $check('classic_registration_hash_matches_verified_container',
        hash_equals((string)$classicWritten['payload_sha256'], (string)$classicRegistration['payload_sha256']));
    $check('classic_registration_size_matches_writer',
        (int)$classicWritten['compressed_size'] === (int)$classicRegistration['compressed_size']
        && (int)$classicWritten['uncompressed_size'] === (int)$classicRegistration['uncompressed_size']);
    $classicCounts = json_decode((string)$classicRegistration['section_counts_json'], true);
    $check('classic_registration_persists_section_counts', is_array($classicCounts)
        && (int)($classicCounts['names'] ?? -1) === 1
        && (int)($classicCounts['imports'] ?? -1) === 1
        && (int)($classicCounts['exports'] ?? -1) === 2);
    $check('classic_registration_persists_source_policy',
        (string)$classicRegistration['source_policy'] === Uedb5Ue5ClassicSnapshotBuilder::SOURCE_POLICY
        && (string)$classicRegistration['package_family'] === Uedb5Ue5ClassicSnapshotBuilder::PACKAGE_FAMILY);
    $check('classic_registration_uses_normalized_package_name_key',
        (int)$classicRegistration['package_key_kind'] === Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME
        && strlen((string)$classicRegistration['package_key']) === 16);

    $zen = [
        'file' => ['id' => 510002, 'game_id' => 7, 'package_name' => '/Game/Test/Zen', 'original_name' => 'Zen.uasset'],
        'package_family' => Uedb5ZenPackageReader::PACKAGE_FAMILY,
        'source_policy' => Uedb5ZenPackageReader::SOURCE_POLICY,
        'section_schemas' => [],
        'sections' => [
            'package_summary' => [['package_id' => '0123456789ABCDEF']],
            'imports' => [],
            'exports' => [],
        ],
    ];
    $writer->write($zen, 1);
    $zenRegistration = $repo->inspect(7, 510002);
    $check('zen_registration_uses_package_id_key',
        (int)$zenRegistration['package_key_kind'] === Uedb5SqlProjectionContract::PACKAGE_KEY_ZEN_PACKAGE_ID
        && strtoupper(bin2hex((string)$zenRegistration['package_key'])) === '0123456789ABCDEF');
    $check('zen_registration_keeps_source_policy',
        (string)$zenRegistration['source_policy'] === Uedb5ZenPackageReader::SOURCE_POLICY
        && (string)$zenRegistration['package_family'] === Uedb5ZenPackageReader::PACKAGE_FAMILY);

    $migration = $read('migrations/202609300001_uedb5_staging_registration.php');
    foreach (array_keys(Uedb5SqlProjectionContract::baselineTables()) as $table) {
        $check('migration_creates_' . $table, str_contains($migration, 'CREATE TABLE ' . $table . ' '));
    }
    $check('optional_object_path_projection_not_created_early',
        !str_contains($migration, 'CREATE TABLE ue_uedb5_object_path_candidates '));
    foreach (['block_count', 'package_family', 'source_policy', 'section_counts_json'] as $column) {
        $check('ue_file_metadata_v5_column_' . $column,
            str_contains($migration, 'ALTER TABLE ue_file_metadata ADD COLUMN ' . $column . ' '));
    }
    $check('live_registration_primary_key_is_not_changed',
        !str_contains(strtolower($migration), 'drop primary key')
        && !str_contains(strtolower($migration), 'modify primary key'));
    $repoSource = $read('src/Infrastructure/Metadata/PdoUedb5StagingRegistrationRepository.php');
    $check('staging_repository_never_writes_live_registration',
        !preg_match('/(?:INSERT\s+INTO|UPDATE|REPLACE\s+INTO)\s+ue_file_metadata/i', $repoSource));
    $check('staging_repository_requires_live_v4_before_register',
        str_contains($repoSource, 'BlockedCompressedMetadataContainer::FORMAT_VERSION')
        && str_contains($repoSource, 'ue_file_metadata'));
    $check('staging_repository_writes_only_v5_registration_table',
        str_contains($repoSource, 'INSERT INTO ue_uedb5_files('));

    $v4Reader = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataReader.php');
    $check('production_reader_still_rejects_non_v4_registration',
        str_contains($v4Reader, '!== BlockedCompressedMetadataContainer::FORMAT_VERSION')
        && str_contains($v4Reader, 'UEDBM4'));

    $contract = Uedb5SqlProjectionContract::baselineTables();
    $nameColumns = (array)($contract['ue_uedb5_name_candidates']['columns'] ?? []);
    $check('fname_dedup_key_is_collision_safe',
        in_array('name_key_fingerprint', $nameColumns, true)
        && str_contains($migration, 'name_key_fingerprint BINARY(32) NOT NULL'));
    $fileColumns = (array)($contract['ue_uedb5_files']['columns'] ?? []);
    $check('v5_registration_contract_includes_section_counts',
        in_array('section_counts_json', $fileColumns, true)
        && str_contains($migration, 'section_counts_json JSON NOT NULL'));
} finally {
    if (is_dir($tempRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($tempRoot);
    }
}

echo json_encode(
    ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
), PHP_EOL;
exit($failures === [] ? 0 : 1);
