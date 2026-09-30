<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader;

$failures = [];
$checks = [];
$check = static function (bool $ok, string $name) use (&$failures, &$checks): void {
    $checks[$name] = $ok;
    if (!$ok) { $failures[] = $name; }
};

$providerId = 'FEDCBA9876543210';
$publicHash = '1122334455667788';
$cellHash = '8877665544332211';
$packageImport = static fn(string $classification = 'hard', ?string $hash = null): array => [
    'type' => 'PackageImport',
    'dependency_class' => $classification,
    'provider_package_id' => $providerId,
    'provider_public_export_hash' => $hash ?? $publicHash,
];
$scriptImport = [
    'type' => 'ScriptImport',
    'dependency_class' => 'script',
    'script_import_hash' => '0000000000001234',
];
$snapshot = static function (string $packageId, array $imports, array $exports, array $cellImports = [], array $cellExports = [], array $soft = [], array $bundleEntries = []): array {
    return [
        'package_family' => Uedb5ZenPackageReader::PACKAGE_FAMILY,
        'source_policy' => Uedb5ZenPackageReader::SOURCE_POLICY,
        'sections' => [
            'package_summary' => [['package_id' => $packageId]],
            'imports' => array_values($imports),
            'exports' => array_values($exports),
            'cell_imports' => array_values($cellImports),
            'cell_exports' => array_values($cellExports),
            'soft_package_references' => array_values($soft),
            'dependency_bundle_entries' => array_values($bundleEntries),
        ],
    ];
};
$cellImport = $packageImport('cell_verse', $cellHash);
$provider = $snapshot($providerId, [], [[
    'index' => 0,
    'public_export_hash' => $publicHash,
    'filter_flags' => 0,
    'object_name' => ['text' => 'TargetObject'],
]], [], [[
    'index' => 0,
    'public_export_hash' => $cellHash,
    'cpp_class_info' => ['text' => 'VerseCell'],
]]);
$consumer = $snapshot('0123456789ABCDEF', [
    $packageImport(),
    $scriptImport,
], [], [
    $cellImport,
], [], [[
    'package_id' => $providerId,
    'dependency_class' => 'soft',
]], [
    ['kind' => 'Export', 'local_export_index' => 0, 'dependency_class' => 'load_order'],
    ['kind' => 'Import', 'combined_import_index' => 0, 'object_index' => $packageImport(), 'dependency_class' => 'load_order'],
    ['kind' => 'Import', 'combined_import_index' => 2, 'object_index' => $cellImport, 'dependency_class' => 'load_order'],
]);
$resolved = Uedb5Ue5ZenDependencyResolver::resolve($consumer, [[
    'provider_id' => 91,
    'snapshot' => $provider,
]]);
$find = static function (array $rows, string $section, int $index): array {
    foreach ($rows as $row) {
        if (($row['source_section'] ?? null) === $section && (int)($row['source_index'] ?? -1) === $index) {
            return $row;
        }
    }
    throw new RuntimeException('Expected resolver row was not emitted.');
};
$hard = $find($resolved, 'imports', 0);
$script = $find($resolved, 'imports', 1);
$cell = $find($resolved, 'cell_imports', 0);
$soft = $find($resolved, 'soft_package_references', 0);
$loadExport = $find($resolved, 'dependency_bundle_entries', 0);
$loadImport = $find($resolved, 'dependency_bundle_entries', 1);
$loadCell = $find($resolved, 'dependency_bundle_entries', 2);

$check($hard['outcome'] === 'resolved' && $hard['selected_provider_file_id'] === 91, 'zen_dependency_package_import_resolved_by_id_hash');
$check($hard['selected_provider_object']['export_index'] === 0, 'zen_dependency_selected_export_provenance');
$check($script['outcome'] === 'unresolved' && $script['dependency_class'] === 'script', 'zen_dependency_script_import_is_unresolved');
$check($cell['outcome'] === 'resolved' && $cell['dependency_class'] === 'cell_verse' && $cell['hard'] === true, 'zen_dependency_cell_import_classified');
$check(($cell['selected_provider_object']['cell_export_index'] ?? null) === 0, 'zen_dependency_cell_import_uses_cell_export_map');
$check($soft['outcome'] === 'package_only' && $soft['hard'] === false, 'zen_dependency_soft_known_is_package_only');
$check($loadExport['outcome'] === 'resolved' && $loadExport['dependency_class'] === 'load_order', 'zen_dependency_local_load_order_resolved');
$check($loadImport['outcome'] === 'resolved' && $loadImport['dependency_class'] === 'load_order' && $loadImport['hard'] === false, 'zen_dependency_import_load_order_classified');
$check($loadCell['outcome'] === 'resolved' && ($loadCell['selected_provider_object']['cell_export_index'] ?? null) === 0, 'zen_dependency_cell_load_order_uses_combined_import_index');
$missing = Uedb5Ue5ZenDependencyResolver::resolve($consumer, []);
$check($find($missing, 'imports', 0)['outcome'] === 'missing', 'zen_dependency_missing_provider_is_missing');
$check($find($missing, 'soft_package_references', 0)['outcome'] === 'missing', 'zen_dependency_missing_soft_provider_is_nonhard_missing');

$wrongCellProvider = $snapshot($providerId, [], [[
    'public_export_hash' => $publicHash, 'filter_flags' => 0, 'object_name' => ['text' => 'TargetObject'],
], [
    'public_export_hash' => $cellHash, 'filter_flags' => 0, 'object_name' => ['text' => 'WrongOrdinaryObject'],
]]);
$wrongCell = Uedb5Ue5ZenDependencyResolver::resolve($consumer, [['provider_id' => 94, 'snapshot' => $wrongCellProvider]]);
$wrongCellRow = $find($wrongCell, 'cell_imports', 0);
$check($wrongCellRow['outcome'] === 'missing' && $wrongCellRow['reason_code'] === 'public_cell_export_hash_missing', 'zen_dependency_cell_import_rejects_ordinary_export_hash_match');

$bundleMismatchRejected = false;
$badBundleConsumer = $consumer;
$badBundleConsumer['sections']['dependency_bundle_entries'][1]['object_index'] = $scriptImport;
try {
    Uedb5Ue5ZenDependencyResolver::resolve($badBundleConsumer, [['provider_id' => 91, 'snapshot' => $provider]]);
} catch (RuntimeException $exception) {
    $bundleMismatchRejected = str_contains($exception->getMessage(), 'object_index does not match');
}
$check($bundleMismatchRejected, 'zen_dependency_bundle_object_index_must_match_combined_import');

$noObjectProvider = $snapshot($providerId, [], []);
$missingObject = Uedb5Ue5ZenDependencyResolver::resolve($consumer, [['provider_id' => 92, 'snapshot' => $noObjectProvider]]);
$check(
    $find($missingObject, 'imports', 0)['outcome'] === 'missing'
    && $find($missingObject, 'imports', 0)['reason_code'] === 'public_export_hash_missing',
    'zen_dependency_missing_public_hash_is_missing'
);

$filteredProvider = $snapshot($providerId, [], [[
    'index' => 0,
    'public_export_hash' => $publicHash,
    'filter_flags' => 1,
    'object_name' => ['text' => 'TargetObject'],
]]);
$filtered = Uedb5Ue5ZenDependencyResolver::resolve($consumer, [['provider_id' => 93, 'snapshot' => $filteredProvider]]);
$check(
    $find($filtered, 'imports', 0)['outcome'] === 'unresolved'
    && $find($filtered, 'imports', 0)['reason_code'] === 'export_filter_runtime_state_required',
    'zen_dependency_filtered_export_requires_runtime_state'
);

$duplicateRejected = false;
try {
    Uedb5Ue5ZenDependencyResolver::resolve($consumer, [
        ['provider_id' => 1, 'snapshot' => $provider],
        ['provider_id' => 2, 'snapshot' => $provider],
    ]);
} catch (RuntimeException $exception) {
    $duplicateRejected = str_contains($exception->getMessage(), 'exactly one selected physical provider');
}
$check($duplicateRejected, 'zen_dependency_requires_one_physical_provider_per_package_id');
$allowedOutcomes = ['resolved', 'package_only', 'common', 'missing', 'unresolved'];
$check(
    array_all($resolved, static fn(array $row): bool => in_array((string)$row['outcome'], $allowedOutcomes, true)),
    'zen_dependency_results_use_only_canonical_outcomes'
);
$check(
    array_all($resolved, static fn(array $row): bool => ($row['resolver_policy'] ?? null) === Uedb5Ue5ZenDependencyResolver::RESOLVER_POLICY),
    'zen_dependency_results_retain_resolver_policy'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
