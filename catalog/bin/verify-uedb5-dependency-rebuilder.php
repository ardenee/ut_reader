<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ClassicSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader;

$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-uedb5-rebuild-' . bin2hex(random_bytes(5));
$gameId = 7;
$failures = [];
$checks = [];
$check = static function (bool $ok, string $name) use (&$failures, &$checks): void {
    $checks[$name] = $ok;
    if (!$ok) { $failures[] = $name; }
};

$fname = static fn(string $text): array => ['name_index' => 0, 'number' => 0, 'text' => $text];
$effective = static fn(string $text): array => ['name_index' => $text === '' ? null : 0, 'number' => $text === '' ? null : 0, 'text' => $text, 'is_none' => $text === ''];
$classicImport = static function (int $index, string $object, string $class, int $outer, string $package = '', bool $optional = false) use ($fname, $effective): array {
    return [
        'index' => $index,
        'class_package' => $fname('/Script/CoreUObject'),
        'class_name' => $fname($class),
        'object_name' => $fname($object),
        'outer_index' => $outer,
        'effective_package_name' => $effective($package),
        'b_import_optional_present' => true,
        'b_import_optional' => $optional,
    ];
};
$classicExport = static function (int $index, string $object, int $classIndex = 0, int $outer = 0 ) use ($fname): array {
    return [
        'index' => $index,
        'object_name' => $fname($object),
        'object_flags' => '0000000000000001',
        'object_flags_serialized_width_bits' => 32,
        'class_index' => $classIndex,
        'outer_index' => $outer,
    ];
};
$classicSnapshot = static function (int $fileId, string $packageName, array $imports, array $exports): array {
    return [
        'file' => ['id' => $fileId, 'game_id' => 7, 'package_name' => $packageName, 'original_name' => basename($packageName) . '.uasset'],
        'package_family' => Uedb5Ue5ClassicSnapshotBuilder::PACKAGE_FAMILY,
        'source_policy' => Uedb5Ue5ClassicSnapshotBuilder::SOURCE_POLICY,
        'section_schemas' => [
            'imports' => 'ue5.classic.object-import.v1',
            'exports' => 'ue5.classic.object-export.v1',
        ],
        'sections' => ['imports' => array_values($imports), 'exports' => array_values($exports)],
    ];
};

try {
    $writer = new Uedb5MetadataSnapshotWriter($tempRoot);
    $reader = new Uedb5MetadataReader($tempRoot);
    $rebuilder = new Uedb5DependencyRebuilder($reader, $writer);

    $consumerId = 30001;
    $providerId = 30002;
    $consumer = $classicSnapshot($consumerId, '/Game/Consumer', [
        $classicImport(0, '/Game/Provider', 'Package', 0),
        $classicImport(1, 'Obj', 'Class', -1),
    ], []);
    $provider = $classicSnapshot($providerId, '/Game/Provider', [], [
        $classicExport(0, 'Obj'),
    ]);
    $writer->write($consumer);
    $writer->write($provider);
    $classicBuilt = $rebuilder->rebuild($gameId, $consumerId, [
        ['game_id' => $gameId, 'file_id' => $providerId],
    ]);
    $classicRows = $reader->snapshot($gameId, $consumerId)['sections'][Uedb5DependencyRebuilder::SECTION] ?? [];
    $check($classicBuilt['dependency_schema'] === Uedb5DependencyRebuilder::CLASSIC_SCHEMA, 'v5_rebuilder_uses_classic_dependency_schema');
    $check(count($classicRows) === 2, 'v5_rebuilder_covers_every_classic_import');
    $check(($classicRows[0]['outcome'] ?? null) === 'package_only', 'v5_rebuilder_normalizes_classic_package_only');
    $check(($classicRows[1]['outcome'] ?? null) === 'resolved'
        && (int)($classicRows[1]['selected_provider_file_id'] ?? 0) === $providerId
        && (int)($classicRows[1]['selected_provider_object']['export_index'] ?? -1) === 0,
        'v5_rebuilder_persists_classic_provider_provenance');

    $rebuilder->rebuild($gameId, $consumerId, [[
        'game_id' => $gameId,
        'file_id' => null,
        'package_name' => '/Game/Provider',
        'selection_status' => 'ambiguous',
        'candidate_file_ids' => [$providerId, 39999],
    ]]);
    $ambiguousClassicRows = $reader->snapshot($gameId, $consumerId)['sections'][Uedb5DependencyRebuilder::SECTION] ?? [];
    $check(count($ambiguousClassicRows) === 2
        && ($ambiguousClassicRows[0]['outcome'] ?? null) === 'unresolved'
        && ($ambiguousClassicRows[1]['outcome'] ?? null) === 'unresolved'
        && ($ambiguousClassicRows[0]['reason_code'] ?? null) === 'provider_environment_ambiguous'
        && ($ambiguousClassicRows[1]['resolver_detail']['candidate_file_ids'] ?? []) === [$providerId,39999],
        'v5_rebuilder_records_classic_provider_environment_ambiguity');

    $missingBuilt = $rebuilder->rebuild($gameId, $consumerId, []);
    $missingRows = $reader->snapshot($gameId, $consumerId)['sections'][Uedb5DependencyRebuilder::SECTION] ?? [];
    $check(count($missingRows) === 2 && ($missingRows[0]['outcome'] ?? null) === 'missing'
        && ($missingRows[1]['outcome'] ?? null) === 'missing',
        'v5_rebuilder_replaces_not_appends_dependency_results');
    $check((int)($missingBuilt['dependency_outcomes']['missing'] ?? 0) === 2, 'v5_rebuilder_returns_canonical_outcome_counts');

    $v4Path = BlockedCompressedMetadataContainer::path($tempRoot, $gameId, $consumerId);
    $v4Dir = dirname($v4Path);
    if (!is_dir($v4Dir)) { mkdir($v4Dir, 0775, true); }
    file_put_contents($v4Path, 'V4-UNCHANGED');
    $v4Hash = hash_file('sha256', $v4Path);

    $zenSnapshot = static function (int $fileId, string $packageId, array $imports, array $exports): array {
        return [
            'file' => ['id' => $fileId, 'game_id' => 7, 'package_name' => '/Game/Zen' . $fileId, 'original_name' => 'Zen' . $fileId . '.uasset'],
            'package_family' => Uedb5ZenPackageReader::PACKAGE_FAMILY,
            'source_policy' => Uedb5ZenPackageReader::SOURCE_POLICY,
            'section_schemas' => [
                'package_summary' => 'ue5.zen.package-summary.v1',
                'imports' => 'ue5.zen.package-object-index.v1',
                'exports' => 'ue5.zen.export-map-entry.v1',
            ],
            'sections' => [
                'package_summary' => [['package_id' => $packageId]],
                'imports' => array_values($imports),
                'exports' => array_values($exports),
                'cell_imports' => [],
                'cell_exports' => [],
                'soft_package_references' => [],
                'dependency_bundle_entries' => [],
            ],
        ];
    };
    $zenConsumerId = 30003;
    $zenProviderId = 30004;
    $providerPackageId = 'FEDCBA9876543210';
    $publicHash = '1122334455667788';
    $zenConsumer = $zenSnapshot($zenConsumerId, '0123456789ABCDEF', [[
        'type' => 'PackageImport',
        'dependency_class' => 'hard',
        'provider_package_id' => $providerPackageId,
        'provider_public_export_hash' => $publicHash,
    ]], []);
    $zenProvider = $zenSnapshot($zenProviderId, $providerPackageId, [], [[
        'index' => 0,
        'public_export_hash' => $publicHash,
        'filter_flags' => 0,
        'object_name' => ['text' => 'ZenTarget'],
    ]]);
    $writer->write($zenConsumer);
    $writer->write($zenProvider);
    $zenBuilt = $rebuilder->rebuild($gameId, $zenConsumerId, [
        ['game_id' => $gameId, 'file_id' => $zenProviderId],
    ]);
    $zenRows = $reader->snapshot($gameId, $zenConsumerId)['sections'][Uedb5DependencyRebuilder::SECTION] ?? [];
    $check($zenBuilt['dependency_schema'] === Uedb5DependencyRebuilder::ZEN_SCHEMA, 'v5_rebuilder_uses_zen_dependency_schema');
    $check(count($zenRows) === 1
        && ($zenRows[0]['outcome'] ?? null) === 'unresolved'
        && ($zenRows[0]['reason_code'] ?? null) === 'package_identity_runtime_context_required',
        'v5_rebuilder_persists_zen_canonical_outcome');
    $check(($zenRows[0]['selected_provider_file_id'] ?? null) === null
        && ($zenRows[0]['required_package_id'] ?? null) === $providerPackageId
        && ($zenRows[0]['effective_import_package_id'] ?? null) === $providerPackageId
        && ($zenRows[0]['provider_lookup_package_id'] ?? null) === $providerPackageId
        && ($zenRows[0]['required_object_identity'] ?? null) === $publicHash,
        'v5_rebuilder_preserves_zen_package_hash_identity');

    $rebuilder->rebuild($gameId, $zenConsumerId, [[
        'game_id' => $gameId,
        'file_id' => null,
        'package_id' => $providerPackageId,
        'selection_status' => 'ambiguous',
        'candidate_file_ids' => [$zenProviderId, 39998],
    ]]);
    $ambiguousZenRows = $reader->snapshot($gameId, $zenConsumerId)['sections'][Uedb5DependencyRebuilder::SECTION] ?? [];
    $check(count($ambiguousZenRows) === 1
        && ($ambiguousZenRows[0]['outcome'] ?? null) === 'unresolved'
        && ($ambiguousZenRows[0]['reason_code'] ?? null) === 'provider_environment_ambiguous'
        && ($ambiguousZenRows[0]['resolver_detail']['candidate_file_ids'] ?? []) === [$zenProviderId,39998],
        'v5_rebuilder_records_zen_provider_environment_ambiguity');

    $redirectConsumerId = 30005;
    $redirectTargetPackageId = '1020304050607080';
    $redirectConsumer = $zenSnapshot($redirectConsumerId, '0011223344556677', [[
        'type' => 'PackageImport',
        'dependency_class' => 'hard',
        'provider_package_id' => $providerPackageId,
        'provider_public_export_hash' => $publicHash,
    ]], []);
    $redirectConsumer['sections']['package_redirects'] = [[
        'source_package_id' => $providerPackageId,
        'target_package_id' => $redirectTargetPackageId,
        'source_package_name' => ['text' => '/Game/RedirectSource'],
    ]];
    $writer->write($redirectConsumer);
    $rebuilder->rebuild($gameId, $redirectConsumerId, [[
        'game_id' => $gameId,
        'file_id' => null,
        'package_id' => $redirectTargetPackageId,
        'selection_status' => 'ambiguous',
        'candidate_file_ids' => [39996, 39997],
    ]]);
    $redirectAmbiguousRows = $reader->snapshot($gameId, $redirectConsumerId)['sections'][Uedb5DependencyRebuilder::SECTION] ?? [];
    $check(count($redirectAmbiguousRows) === 1
        && ($redirectAmbiguousRows[0]['required_package_id'] ?? null) === $providerPackageId
        && ($redirectAmbiguousRows[0]['effective_import_package_id'] ?? null) === $providerPackageId
        && ($redirectAmbiguousRows[0]['provider_lookup_package_id'] ?? null) === $providerPackageId
        && ($redirectAmbiguousRows[0]['outcome'] ?? null) === 'unresolved'
        && ($redirectAmbiguousRows[0]['reason_code'] ?? null) === 'package_identity_runtime_context_required',
        'v5_rebuilder_does_not_apply_container_redirect_before_pre_store_runtime_context');
    $rebuilder->rebuild($gameId, $consumerId, []);
    $check(hash_file('sha256', $v4Path) === $v4Hash, 'v5_dependency_rebuild_does_not_touch_v4_container');

    $rebuilderSource = file_get_contents($root . '/src/Infrastructure/Metadata/Uedb5DependencyRebuilder.php');
    $check(is_string($rebuilderSource)
        && !str_contains($rebuilderSource, 'use PDO')
        && !str_contains($rebuilderSource, 'BlockedCompressedMetadata')
        && !str_contains($rebuilderSource, 'ue_file_metadata'),
        'v5_dependency_rebuilder_has_no_sql_or_v4_publication_path');

    $allowed = ['resolved', 'package_only', 'common', 'missing', 'unresolved'];
    $allCanonical = true;
    foreach (array_merge($missingRows, $zenRows) as $row) {
        $allCanonical = $allCanonical && in_array((string)($row['outcome'] ?? ''), $allowed, true);
    }
    $check($allCanonical, 'v5_dependency_results_use_only_frozen_outcomes');
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

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
