<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;

$checks = [];
$failures = [];
$check = static function (bool $ok, string $name) use (&$checks, &$failures): void {
    $checks[$name] = $ok;
    if (!$ok) { $failures[] = $name; }
};
$read = static function (string $relative) use ($root): string {
    $bytes = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($bytes) ? $bytes : '';
};

$baseline = Uedb5SqlProjectionContract::baselineTables();
$optional = Uedb5SqlProjectionContract::optionalTables();
$expectedTables = [
    'ue_uedb5_files',
    'ue_uedb5_provider_keys',
    'ue_uedb5_search_keys',
    'ue_uedb5_name_candidates',
    'ue_uedb5_object_candidates',
    'ue_uedb5_dependency_edges',
    'ue_uedb5_dependency_packages',
];
$check(array_keys($baseline) === $expectedTables, 'v5_sql_baseline_table_set_is_frozen');
$check(
    array_all(array_keys($baseline), static fn(string $table): bool => str_starts_with($table, 'ue_uedb5_')),
    'v5_sql_tables_are_side_by_side_with_v4'
);
$check(
    Uedb5SqlProjectionContract::outcomeCodes() === [
        'missing' => 0, 'resolved' => 1, 'package_only' => 2, 'common' => 3, 'unresolved' => 4,
    ],
    'v5_sql_outcomes_match_frozen_uedb5_codes'
);
$check(
    array_keys(Uedb5SqlProjectionContract::classificationCodes()) === [
        'hard', 'optional', 'soft', 'build_cook', 'script', 'cell_verse', 'load_order', 'runtime_derived',
    ],
    'v5_sql_dependency_classifications_are_explicit'
);

$forbidden = array_fill_keys(Uedb5SqlProjectionContract::forbiddenPerObjectSourceColumns(), true);
$objectColumns = array_fill_keys((array)$baseline['ue_uedb5_object_candidates']['columns'], true);
$edgeColumns = array_fill_keys((array)$baseline['ue_uedb5_dependency_edges']['columns'], true);
$forbiddenObject = array_intersect_key($objectColumns, $forbidden);
$forbiddenEdge = array_intersect_key($edgeColumns, $forbidden);
$check($forbiddenObject === [], 'v5_object_candidates_do_not_duplicate_source_graph');
$check($forbiddenEdge === [], 'v5_dependency_edges_do_not_duplicate_source_graph');
$check(
    $objectColumns === array_fill_keys([
        'file_id', 'object_kind', 'object_index',
        'object_name_hash', 'object_name_length', 'public_export_hash',
    ], true),
    'v5_object_candidate_row_is_narrow_and_index_only'
);

$types = Uedb5SqlProjectionContract::highCardinalityColumnTypes();
$check(
    ($types['ue_uedb5_object_candidates']['object_name_hash'] ?? '') === 'BINARY(16)'
    && ($types['ue_uedb5_object_candidates']['public_export_hash'] ?? '') === 'BINARY(8) NULL',
    'v5_object_candidate_hash_widths_are_frozen'
);
$check(
    ($types['ue_uedb5_dependency_edges']['required_package_key'] ?? '') === 'VARBINARY(16) NULL'
    && ($types['ue_uedb5_dependency_edges']['required_object_key'] ?? '') === 'VARBINARY(16) NULL',
    'v5_dependency_edge_keys_stay_compact'
);
$nameContract = (array)$baseline['ue_uedb5_name_candidates'];
$check(
    (string)($nameContract['cardinality'] ?? '') === 'one_per_file_and_distinct_normalized_fname',
    'v5_fname_projection_deduplicates_within_file'
);
$check(
    !in_array('name_text', (array)$nameContract['columns'], true),
    'v5_fname_candidates_do_not_repeat_name_text'
);
$check(
    (string)($baseline['ue_uedb5_search_keys']['cardinality'] ?? '') === 'one_per_distinct_normalized_search_text',
    'v5_search_text_is_global_dictionary_not_per_object_copy'
);
$check(
    isset($optional['ue_uedb5_object_path_candidates'])
    && empty($optional['ue_uedb5_object_path_candidates']['default_enabled'])
    && !empty($optional['ue_uedb5_object_path_candidates']['requires_measured_query_contract']),
    'v5_object_path_projection_is_off_by_default'
);
$check(
    !array_key_exists('path_hash_ci', $objectColumns),
    'v5_baseline_object_rows_pay_no_path_index_storage_cost'
);

$summaryColumns = (array)$baseline['ue_uedb5_dependency_packages']['columns'];
$check(
    in_array('required_package_name', $summaryColumns, true)
    && in_array('hard_missing_count', $summaryColumns, true)
    && in_array('unresolved_count', $summaryColumns, true),
    'v5_dependency_package_summary_supports_missing_pages_without_edge_scan'
);
$check(
    in_array('required_package_key', (array)$baseline['ue_uedb5_dependency_edges']['columns'], true)
    && !in_array('required_package_name', (array)$baseline['ue_uedb5_dependency_edges']['columns'], true),
    'v5_dependency_edges_use_compact_package_keys_not_repeated_strings'
);
$v5Search = $read('src/Infrastructure/Search/Uedb5CatalogMetadataSearch.php');
$v5Resolver = $read('src/Infrastructure/Metadata/Uedb5ClassicDependencyResolver.php');
$check(
    str_contains($v5Search, 'rowsByPositions(')
    && str_contains($v5Resolver, 'normalizedTables(')
    && !str_contains($v5Search, 'ue_export_lookup'),
    'ut3_candidate_then_uedb_hydration_is_v5_model'
);
$check(
    !is_file($root . '/src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php')
    && !in_array('class_package_term_id', (array)$baseline['ue_uedb5_object_candidates']['columns'], true)
    && !in_array('class_name_term_id', (array)$baseline['ue_uedb5_object_candidates']['columns'], true)
    && !in_array('object_flags', (array)$baseline['ue_uedb5_object_candidates']['columns'], true)
    && !in_array('outer_index', (array)$baseline['ue_uedb5_object_candidates']['columns'], true),
    'v5_does_not_repeat_v4_export_path_identity_projection'
);
$v5RuntimeFiles = [
    $read('src/Infrastructure/Metadata/Uedb5MetadataReader.php'),
    $read('src/Infrastructure/Metadata/Uedb5MetadataSnapshotWriter.php'),
    $read('src/Infrastructure/Metadata/Uedb5DependencyRebuilder.php'),
];
$check(
    array_all($v5RuntimeFiles, static fn(string $source): bool => !str_contains($source, 'PDO')),
    'step4_contract_does_not_publish_v5_sql_yet'
);
$check(
    BlockedCompressedMetadataContainer::FORMAT_VERSION === 4,
    'production_runtime_remains_uedb4_during_projection_contract_step'
);

$rules = Uedb5SqlProjectionContract::rules();
$check(in_array('sql_is_candidate_accelerator_not_source_of_truth', $rules, true), 'v5_sql_authority_rule_is_explicit');
$check(in_array('new_projection_requires_query_index_cardinality_and_storage_justification', $rules, true), 'v5_sql_growth_gate_is_explicit');
$check(in_array('no_auto_increment_term_id_in_v5_projection', $rules, true), 'v5_sql_has_no_auto_increment_term_identity');

echo json_encode(['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
