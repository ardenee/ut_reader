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
$snapshot = static function (
    string $packageId,
    array $imports,
    array $exports,
    array $cellImports = [],
    array $cellExports = [],
    array $soft = [],
    array $bundleEntries = [],
    array $redirects = [],
    array $localized = []
): array {
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
            'package_redirects' => array_values($redirects),
            'localized_packages' => array_values($localized),
        ],
    ];
};
$authoritativeContext = [
    'package_identity_context_authoritative' => true,
    'package_store_context_authoritative' => true,
    'package_redirects' => [],
    'localized_packages' => [],
];
$resolve = static function (array $consumer, array $providers, array $context = []) use ($authoritativeContext): array {
    // Existing tests historically used is_editor as a combined build/runtime shorthand.
    // Make the compile-time WITH_EDITOR state explicit while retaining separate runtime GIsEditor semantics.
    if (array_key_exists('is_editor', $context) && !array_key_exists('with_editor', $context)) {
        $context['with_editor'] = (bool)$context['is_editor'];
    }
    return Uedb5Ue5ZenDependencyResolver::resolve($consumer, $providers, $context + $authoritativeContext);
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
$resolved = $resolve($consumer, [[
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

$identityUnknown = Uedb5Ue5ZenDependencyResolver::resolve($consumer, [[
    'provider_id' => 91,
    'snapshot' => $provider,
]]);
$check(
    $find($identityUnknown, 'imports', 0)['outcome'] === 'unresolved'
        && $find($identityUnknown, 'imports', 0)['reason_code'] === 'package_identity_runtime_context_required',
    'zen_package_import_requires_authoritative_core_redirect_instancing_localization_context'
);
$mountUnknownWithoutLocalRows = Uedb5Ue5ZenDependencyResolver::resolve($consumer, [[
    'provider_id' => 91,
    'snapshot' => $provider,
]], [
    'package_identity_context_authoritative' => true,
]);
$check(
    $find($mountUnknownWithoutLocalRows, 'imports', 0)['outcome'] === 'unresolved'
        && $find($mountUnknownWithoutLocalRows, 'imports', 0)['reason_code'] === 'package_store_mount_context_required',
    'zen_absent_local_redirect_rows_do_not_prove_global_package_store_absence'
);

$missing = $resolve($consumer, []);
$check($find($missing, 'imports', 0)['outcome'] === 'missing', 'zen_dependency_missing_provider_is_missing');
$check($find($missing, 'soft_package_references', 0)['outcome'] === 'missing', 'zen_dependency_missing_soft_provider_is_nonhard_missing');


$redirectTargetId = 'A1A2A3A4A5A6A7A8';
$redirectProvider = $snapshot($redirectTargetId, [], [[
    'index' => 0,
    'public_export_hash' => $publicHash,
    'filter_flags' => 0,
    'object_name' => ['text' => 'RedirectTargetObject'],
]]);
$redirectRow = [
    'container_index' => 0,
    'source_package_id' => $providerId,
    'target_package_id' => $redirectTargetId,
    'source_package_name' => ['text' => '/Game/RedirectSource'],
];
$redirectConsumer = $snapshot(
    '0123456789ABCDEF',
    [$packageImport()],
    [], [], [], [], [],
    [$redirectRow]
);
$identityRewriteTarget = 'C1C2C3C4C5C6C7C8';
$identityRewrite = $resolve($consumer, [[
    'provider_id' => 96,
    'snapshot' => $redirectProvider,
]], [
    'package_identity_rewrites' => [[
        'source_package_id' => $providerId,
        'imported_package_id' => $identityRewriteTarget,
        'package_id_to_load' => $redirectTargetId,
        'reason' => 'test_core_redirect_instancing_localization_aggregate',
    ]],
]);
$identityRewriteRow = $find($identityRewrite, 'imports', 0);
$check(
    $identityRewriteRow['outcome'] === 'resolved'
        && $identityRewriteRow['required_package_id'] === $providerId
        && $identityRewriteRow['effective_import_package_id'] === $identityRewriteTarget
        && $identityRewriteRow['provider_lookup_package_id'] === $redirectTargetId
        && ($identityRewriteRow['package_identity_rewrite']['reason'] ?? null) === 'test_core_redirect_instancing_localization_aggregate',
    'zen_authoritative_pre_store_identity_rewrite_preserves_serialized_source_identity'
);
$redirectBaseline = Uedb5Ue5ZenDependencyResolver::resolve($redirectConsumer, [], [
    'package_identity_context_authoritative' => true,
]);
$redirectBaselineRow = $find($redirectBaseline, 'imports', 0);
$check(
    $redirectBaselineRow['required_package_id'] === $providerId
        && $redirectBaselineRow['provider_lookup_package_id'] === $providerId
        && $redirectBaselineRow['outcome'] === 'unresolved'
        && $redirectBaselineRow['reason_code'] === 'package_store_mount_context_required'
        && ($redirectBaselineRow['package_store_redirect_candidate']['target_package_id'] ?? null) === $redirectTargetId,
    'zen_single_container_redirect_is_evidence_not_global_winner'
);
$redirectContext = [
    'package_store_context_authoritative' => true,
    'package_redirects' => [$redirectRow],
    'localized_packages' => [],
    'is_editor' => false,
];
$redirectResolved = $resolve($redirectConsumer, [[
    'provider_id' => 96,
    'snapshot' => $redirectProvider,
]], $redirectContext);
$redirectResolvedRow = $find($redirectResolved, 'imports', 0);
$check(
    $redirectResolvedRow['outcome'] === 'resolved'
        && $redirectResolvedRow['required_package_id'] === $providerId
        && $redirectResolvedRow['provider_lookup_package_id'] === $redirectTargetId
        && $redirectResolvedRow['selected_provider_package_id'] === $redirectTargetId
        && ($redirectResolvedRow['package_store_redirect']['source_package_name'] ?? null) === '/Game/RedirectSource'
        && str_starts_with($redirectResolvedRow['reason_code'], 'package_store_redirect_'),
    'zen_package_store_redirect_resolves_public_hash_in_target_provider'
);

$secondRedirectTargetId = 'B1B2B3B4B5B6B7B8';
$firstRedirectWinsConsumer = $redirectConsumer;
$firstRedirectWinsConsumer['sections']['package_redirects'][] = [
    'container_index' => 1,
    'source_package_id' => $providerId,
    'target_package_id' => $secondRedirectTargetId,
    'source_package_name' => ['text' => '/Game/SecondRedirect'],
];
$firstRedirectWins = $resolve($firstRedirectWinsConsumer, [[
    'provider_id' => 97,
    'snapshot' => $redirectProvider,
]], [
    'package_store_context_authoritative' => true,
    'package_redirects' => $firstRedirectWinsConsumer['sections']['package_redirects'],
    'localized_packages' => [],
    'is_editor' => false,
]);
$check(
    $find($firstRedirectWins, 'imports', 0)['outcome'] === 'resolved'
        && $find($firstRedirectWins, 'imports', 0)['provider_lookup_package_id'] === $redirectTargetId,
    'zen_authoritative_package_store_redirect_first_effective_order_entry_wins'
);

$localizedRow = [
    'container_index' => 0,
    'source_package_id' => $providerId,
    'source_package_name' => ['text' => '/Game/LocalizedSource'],
];
$localizedConsumer = $snapshot(
    '0123456789ABCDEF',
    [$packageImport()],
    [], [], [], [], [],
    [], [$localizedRow]
);
$localizedSingleContainer = Uedb5Ue5ZenDependencyResolver::resolve($localizedConsumer, [[
    'provider_id' => 91,
    'snapshot' => $provider,
]], [
    'package_identity_context_authoritative' => true,
]);
$localizedSingleContainerRow = $find($localizedSingleContainer, 'imports', 0);
$check(
    $localizedSingleContainerRow['outcome'] === 'unresolved'
        && $localizedSingleContainerRow['reason_code'] === 'package_store_mount_context_required'
        && ($localizedSingleContainerRow['localized_package_candidate']['source_package_name'] ?? null) === '/Game/LocalizedSource',
    'zen_single_container_localization_is_evidence_not_global_winner'
);
$localizedUnknown = $resolve(
    $localizedConsumer,
    [['provider_id' => 91, 'snapshot' => $provider]],
    [
        'package_store_context_authoritative' => true,
        'package_redirects' => [],
        'localized_packages' => [$localizedRow],
    ]
);
$localizedUnknownRow = $find($localizedUnknown, 'imports', 0);
$check(
    $localizedUnknownRow['outcome'] === 'unresolved'
        && $localizedUnknownRow['reason_code'] === 'localized_package_runtime_culture_required'
        && ($localizedUnknownRow['localized_package']['source_package_name'] ?? null) === '/Game/LocalizedSource',
    'zen_authoritative_localization_still_requires_runtime_culture_outside_known_editor_context'
);
$localizedEditor = $resolve(
    $localizedConsumer,
    [['provider_id' => 91, 'snapshot' => $provider]],
    [
        'package_store_context_authoritative' => true,
        'package_redirects' => [],
        'localized_packages' => [$localizedRow],
        'is_editor' => true,
    ]
);
$check(
    $find($localizedEditor, 'imports', 0)['outcome'] === 'resolved',
    'zen_editor_context_skips_file_package_store_localization_redirect'
);

$redirectAndLocalizedConsumer = $redirectConsumer;
$redirectAndLocalizedConsumer['sections']['localized_packages'] = [$localizedRow];
$redirectAndLocalized = $resolve($redirectAndLocalizedConsumer, [[
    'provider_id' => 96,
    'snapshot' => $redirectProvider,
]], [
    'package_store_context_authoritative' => true,
    'package_redirects' => [$redirectRow],
    'localized_packages' => [$localizedRow],
    'is_editor' => false,
]);
$check(
    $find($redirectAndLocalized, 'imports', 0)['outcome'] === 'resolved'
        && $find($redirectAndLocalized, 'imports', 0)['provider_lookup_package_id'] === $redirectTargetId,
    'zen_explicit_package_store_redirect_precedes_localization_mapping'
);

$wrongCellProvider = $snapshot($providerId, [], [[
    'public_export_hash' => $publicHash, 'filter_flags' => 0, 'object_name' => ['text' => 'TargetObject'],
], [
    'public_export_hash' => $cellHash, 'filter_flags' => 0, 'object_name' => ['text' => 'WrongOrdinaryObject'],
]]);
$wrongCell = $resolve($consumer, [['provider_id' => 94, 'snapshot' => $wrongCellProvider]]);
$wrongCellRow = $find($wrongCell, 'cell_imports', 0);
$check($wrongCellRow['outcome'] === 'missing' && $wrongCellRow['reason_code'] === 'public_cell_export_hash_missing', 'zen_dependency_cell_import_rejects_ordinary_export_hash_match');

$bundleMismatchRejected = false;
$badBundleConsumer = $consumer;
$badBundleConsumer['sections']['dependency_bundle_entries'][1]['object_index'] = $scriptImport;
try {
    $resolve($badBundleConsumer, [['provider_id' => 91, 'snapshot' => $provider]]);
} catch (RuntimeException $exception) {
    $bundleMismatchRejected = str_contains($exception->getMessage(), 'object_index does not match');
}
$check($bundleMismatchRejected, 'zen_dependency_bundle_object_index_must_match_combined_import');

$noObjectProvider = $snapshot($providerId, [], []);
$missingObject = $resolve($consumer, [['provider_id' => 92, 'snapshot' => $noObjectProvider]]);
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
$filtered = $resolve($consumer, [['provider_id' => 93, 'snapshot' => $filteredProvider]]);
$check(
    $find($filtered, 'imports', 0)['outcome'] === 'unresolved'
    && $find($filtered, 'imports', 0)['reason_code'] === 'export_filter_runtime_state_required',
    'zen_dependency_filtered_export_requires_runtime_state'
);
$filteredEditor = $resolve($consumer, [['provider_id' => 93, 'snapshot' => $filteredProvider]], [
    'with_editor' => true,
    'is_editor' => true,
]);
$check(
    $find($filteredEditor, 'imports', 0)['outcome'] === 'resolved',
    'zen_with_editor_never_filters_export_flags'
);
$serverFilteredProvider = $snapshot($providerId, [], [[
    'index' => 0,
    'public_export_hash' => $publicHash,
    'filter_flags' => 2,
    'object_name' => ['text' => 'ServerFilteredObject'],
]]);
$serverFiltered = $resolve($consumer, [['provider_id' => 94, 'snapshot' => $serverFilteredProvider]], [
    'with_editor' => false,
    'ue_server' => true,
]);
$check(
    $find($serverFiltered, 'imports', 0)['outcome'] === 'unresolved'
        && $find($serverFiltered, 'imports', 0)['reason_code'] === 'export_filtered_for_runtime_build',
    'zen_server_build_applies_not_for_server_filter'
);

$duplicateHashProvider = $snapshot($providerId, [], [[
    'public_export_hash' => $publicHash, 'filter_flags' => 0, 'object_name' => ['text' => 'FirstTarget'],
], [
    'public_export_hash' => $publicHash, 'filter_flags' => 0, 'object_name' => ['text' => 'SecondTarget'],
]], [], [[
    'public_export_hash' => $cellHash, 'cpp_class_info' => ['text' => 'FirstCell'],
], [
    'public_export_hash' => $cellHash, 'cpp_class_info' => ['text' => 'SecondCell'],
]]);
$duplicateHash = $resolve($consumer, [['provider_id' => 95, 'snapshot' => $duplicateHashProvider]]);
$duplicateOrdinary = $find($duplicateHash, 'imports', 0);
$duplicateCell = $find($duplicateHash, 'cell_imports', 0);
$check(
    $duplicateOrdinary['outcome'] === 'unresolved'
        && $duplicateOrdinary['reason_code'] === 'public_export_hash_runtime_collision_ambiguous'
        && ($duplicateOrdinary['candidate_export_indices'] ?? null) === [0, 1],
    'zen_dependency_duplicate_public_hash_is_runtime_collision_ambiguous'
);
$check(
    $duplicateCell['outcome'] === 'unresolved'
        && $duplicateCell['reason_code'] === 'public_cell_export_hash_runtime_collision_ambiguous'
        && ($duplicateCell['candidate_cell_export_indices'] ?? null) === [0, 1],
    'zen_dependency_duplicate_cell_hash_is_runtime_collision_ambiguous'
);

$redirectorProvider = $snapshot($providerId, [], [[
    'index' => 0,
    'public_export_hash' => $publicHash,
    'filter_flags' => 0,
    'object_name' => ['text' => 'MovedObject'],
    'class_index' => [
        'type' => 'ScriptImport',
        'script_import_hash' => '1D39669A89BAECB6',
    ],
]]);
$redirectorUnknown = $resolve(
    $consumer,
    [['provider_id' => 98, 'snapshot' => $redirectorProvider]]
);
$check(
    $find($redirectorUnknown, 'imports', 0)['outcome'] === 'unresolved'
        && $find($redirectorUnknown, 'imports', 0)['reason_code'] === 'object_redirector_editor_build_context_required',
    'zen_object_redirector_requires_editor_runtime_context_when_build_mode_unknown'
);
$redirectorEditor = $resolve(
    $consumer,
    [['provider_id' => 98, 'snapshot' => $redirectorProvider]],
    ['is_editor' => true]
);
$check(
    $find($redirectorEditor, 'imports', 0)['outcome'] === 'unresolved'
        && $find($redirectorEditor, 'imports', 0)['reason_code'] === 'object_redirector_destination_runtime_payload_required',
    'zen_editor_object_redirector_requires_destination_payload'
);
$redirectorRuntime = $resolve(
    $consumer,
    [['provider_id' => 98, 'snapshot' => $redirectorProvider]],
    ['is_editor' => false]
);
$check(
    $find($redirectorRuntime, 'imports', 0)['outcome'] === 'resolved'
        && ($find($redirectorRuntime, 'imports', 0)['selected_provider_object']['export_index'] ?? null) === 0,
    'zen_noneditor_object_redirector_is_returned_without_destination_follow'
);

$optionalProvider = $provider;
$optionalProvider['sections']['exports'] = [];
$optionalProvider['sections']['optional_segment'] = [[
    'imports' => [],
    'exports' => [[
        'index' => 0,
        'public_export_hash' => $publicHash,
        'filter_flags' => 0,
        'object_name' => ['text' => 'OptionalTarget'],
    ]],
    'cell_imports' => [],
    'cell_exports' => [],
    'soft_package_references' => [],
    'dependency_bundle_entries' => [],
]];
$optionalProviderUnknown = $resolve(
    $consumer,
    [['provider_id' => 99, 'snapshot' => $optionalProvider]]
);
$check(
    $find($optionalProviderUnknown, 'imports', 0)['outcome'] === 'unresolved'
        && $find($optionalProviderUnknown, 'imports', 0)['reason_code'] === 'optional_segment_export_editor_build_context_required',
    'zen_optional_provider_export_requires_editor_build_context_when_unknown'
);
$optionalProviderEditor = $resolve(
    $consumer,
    [['provider_id' => 99, 'snapshot' => $optionalProvider]],
    ['is_editor' => true]
);
$check(
    $find($optionalProviderEditor, 'imports', 0)['outcome'] === 'resolved'
        && ($find($optionalProviderEditor, 'imports', 0)['selected_provider_object']['optional_segment_export_index'] ?? null) === 0,
    'zen_editor_import_searches_optional_provider_exports_after_main_exports'
);
$optionalProviderRuntime = $resolve(
    $consumer,
    [['provider_id' => 99, 'snapshot' => $optionalProvider]],
    ['is_editor' => false]
);
$check(
    $find($optionalProviderRuntime, 'imports', 0)['outcome'] === 'missing'
        && $find($optionalProviderRuntime, 'imports', 0)['reason_code'] === 'optional_segment_export_not_loaded_non_editor',
    'zen_noneditor_import_does_not_search_optional_provider_exports'
);

$optionalCellProvider = $provider;
$optionalCellProvider['sections']['cell_exports'] = [];
$optionalCellProvider['sections']['optional_segment'] = [[
    'imports' => [],
    'exports' => [],
    'cell_imports' => [],
    'cell_exports' => [[
        'index' => 0,
        'public_export_hash' => $cellHash,
        'cpp_class_info' => ['text' => 'OptionalVerseCell'],
    ]],
    'soft_package_references' => [],
    'dependency_bundle_entries' => [],
]];
$optionalCellEditor = $resolve(
    $consumer,
    [['provider_id' => 101, 'snapshot' => $optionalCellProvider]],
    ['is_editor' => true]
);
$check(
    $find($optionalCellEditor, 'cell_imports', 0)['outcome'] === 'resolved'
        && ($find($optionalCellEditor, 'cell_imports', 0)['selected_provider_object']['cell_export_index'] ?? null) === 0,
    'zen_editor_cell_import_searches_optional_provider_cell_exports'
);
$optionalCellRuntime = $resolve(
    $consumer,
    [['provider_id' => 101, 'snapshot' => $optionalCellProvider]],
    ['is_editor' => false]
);
$check(
    $find($optionalCellRuntime, 'cell_imports', 0)['outcome'] === 'missing'
        && $find($optionalCellRuntime, 'cell_imports', 0)['reason_code'] === 'optional_segment_export_not_loaded_non_editor',
    'zen_noneditor_cell_import_does_not_search_optional_provider_cell_exports'
);

$optionalConsumer = $consumer;
$optionalConsumer['sections']['imports'] = [];
$optionalConsumer['sections']['cell_imports'] = [];
$optionalConsumer['sections']['soft_package_references'] = [];
$optionalConsumer['sections']['dependency_bundle_entries'] = [];
$optionalConsumer['sections']['optional_segment'] = [[
    'imports' => [$packageImport('optional')],
    'exports' => [],
    'cell_imports' => [],
    'cell_exports' => [],
    'soft_package_references' => [],
    'dependency_bundle_entries' => [],
]];
$optionalConsumerUnknown = $resolve(
    $optionalConsumer,
    [['provider_id' => 91, 'snapshot' => $provider]]
);
$optionalUnknownRow = $find($optionalConsumerUnknown, 'optional_segment_imports', 0);
$check(
    $optionalUnknownRow['outcome'] === 'unresolved'
        && $optionalUnknownRow['reason_code'] === 'optional_segment_editor_build_context_required',
    'zen_optional_consumer_import_requires_editor_build_context_when_unknown'
);
$optionalConsumerEditor = $resolve(
    $optionalConsumer,
    [['provider_id' => 91, 'snapshot' => $provider]],
    ['is_editor' => true]
);
$check(
    $find($optionalConsumerEditor, 'optional_segment_imports', 0)['outcome'] === 'resolved',
    'zen_editor_resolves_optional_consumer_import_from_optional_header_identity'
);
$optionalConsumerRuntime = $resolve(
    $optionalConsumer,
    [['provider_id' => 91, 'snapshot' => $provider]],
    ['is_editor' => false]
);
$check(
    $find($optionalConsumerRuntime, 'optional_segment_imports', 0)['outcome'] === 'unresolved'
        && $find($optionalConsumerRuntime, 'optional_segment_imports', 0)['reason_code'] === 'optional_segment_not_loaded_non_editor',
    'zen_noneditor_optional_consumer_header_is_not_loaded'
);

$mainAndOptionalDuplicate = $provider;
$mainAndOptionalDuplicate['sections']['optional_segment'] = [[
    'imports' => [],
    'exports' => [[
        'index' => 0,
        'public_export_hash' => $publicHash,
        'filter_flags' => 0,
        'object_name' => ['text' => 'OptionalCollision'],
    ]],
    'cell_imports' => [],
    'cell_exports' => [],
    'soft_package_references' => [],
    'dependency_bundle_entries' => [],
]];
$mainOptionalCollision = $resolve(
    $consumer,
    [['provider_id' => 100, 'snapshot' => $mainAndOptionalDuplicate]],
    ['is_editor' => true]
);
$collisionRow = $find($mainOptionalCollision, 'imports', 0);
$check(
    $collisionRow['outcome'] === 'unresolved'
        && $collisionRow['reason_code'] === 'public_export_hash_runtime_collision_ambiguous'
        && ($collisionRow['candidate_provider_exports'] ?? null) === [
            ['segment'=>'main','index'=>0],
            ['segment'=>'optional','index'=>0],
        ],
    'zen_main_optional_duplicate_public_hash_is_runtime_collision_ambiguous'
);

$duplicateRejected = false;
try {
    $resolve($consumer, [
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
