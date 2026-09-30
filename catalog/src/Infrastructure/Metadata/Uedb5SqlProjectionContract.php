<?php
/**
 * Machine-readable SQL projection contract for UEDB5.
 *
 * SQL is an accelerator only. Source-shaped package identity remains in .uedb5.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

final class Uedb5SqlProjectionContract
{
    public const FORMAT_VERSION = 5;
    public const NAME_KEY_ALGORITHM = 'md5-fname-ci-v1';
    public const SEARCH_FINGERPRINT_ALGORITHM = 'sha256-fname-ci-v1';
    public const PATH_KEY_ALGORITHM = 'md5-fnamepath-ci-v1';

    public const PACKAGE_KEY_CLASSIC_NAME = 1;
    public const PACKAGE_KEY_ZEN_PACKAGE_ID = 2;
    public const OBJECT_KEY_NAME = 1;
    public const OBJECT_KEY_PUBLIC_EXPORT_HASH = 2;
    public const OBJECT_KIND_EXPORT = 1;
    public const OBJECT_KIND_CELL_EXPORT = 2;

    public const OUTCOME_MISSING = 0;
    public const OUTCOME_RESOLVED = 1;
    public const OUTCOME_PACKAGE_ONLY = 2;
    public const OUTCOME_COMMON = 3;
    public const OUTCOME_UNRESOLVED = 4;
    public const CLASS_HARD = 1;
    public const CLASS_OPTIONAL = 2;
    public const CLASS_SOFT = 3;
    public const CLASS_BUILD_COOK = 4;
    public const CLASS_SCRIPT = 5;
    public const CLASS_CELL_VERSE = 6;
    public const CLASS_LOAD_ORDER = 7;
    public const CLASS_RUNTIME_DERIVED = 8;

    /** @return array<string,int> */
    public static function outcomeCodes(): array
    {
        return [
            'missing' => self::OUTCOME_MISSING,
            'resolved' => self::OUTCOME_RESOLVED,
            'package_only' => self::OUTCOME_PACKAGE_ONLY,
            'common' => self::OUTCOME_COMMON,
            'unresolved' => self::OUTCOME_UNRESOLVED,
        ];
    }

    /** @return array<string,int> */
    public static function classificationCodes(): array
    {
        return [
            'hard' => self::CLASS_HARD,
            'optional' => self::CLASS_OPTIONAL,
            'soft' => self::CLASS_SOFT,            'build_cook' => self::CLASS_BUILD_COOK,
            'script' => self::CLASS_SCRIPT,
            'cell_verse' => self::CLASS_CELL_VERSE,
            'load_order' => self::CLASS_LOAD_ORDER,
            'runtime_derived' => self::CLASS_RUNTIME_DERIVED,
        ];
    }

    /**
     * Baseline V5 tables. These can coexist with live V4 projections.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function baselineTables(): array
    {
        return [
            'ue_uedb5_files' => [
                'cardinality' => 'one_per_file',
                'columns' => [
                    'file_id', 'game_id', 'format_version', 'codec',
                    'compressed_size', 'uncompressed_size', 'payload_sha256',
                    'block_count', 'package_family', 'source_policy',
                    'package_key_kind', 'package_key', 'package_name',
                    'created_at', 'updated_at',
                ],
                'indexes' => [
                    ['file_id'],
                    ['game_id', 'package_key_kind', 'package_key', 'file_id'],
                ],
            ],            'ue_uedb5_provider_keys' => [
                'cardinality' => 'one_per_primary_or_alias_provider_key',
                'columns' => [
                    'game_id', 'package_key_kind', 'package_key', 'file_id',
                    'source_kind', 'source_id',
                ],
                'indexes' => [
                    ['game_id', 'package_key_kind', 'package_key', 'file_id'],
                    ['file_id'],
                ],
            ],
            'ue_uedb5_search_keys' => [
                'cardinality' => 'one_per_distinct_normalized_search_text',
                'columns' => [
                    'key_hash', 'key_length', 'key_fingerprint', 'normalized_text',
                ],
                'indexes' => [
                    ['key_fingerprint'],
                    ['key_hash', 'key_length'],
                    ['normalized_text(191)'],
                ],
            ],
            'ue_uedb5_name_candidates' => [
                'cardinality' => 'one_per_file_and_distinct_normalized_fname',
                'columns' => [
                    'file_id', 'name_key_hash', 'name_key_length', 'first_name_index',
                ],
                'indexes' => [
                    ['name_key_hash', 'name_key_length', 'file_id'],
                    ['file_id'],
                ],
            ],            'ue_uedb5_object_candidates' => [
                'cardinality' => 'one_per_export_or_cell_export',
                'columns' => [
                    'file_id', 'object_kind', 'object_index',
                    'object_name_hash', 'object_name_length', 'public_export_hash',
                ],
                'indexes' => [
                    ['file_id', 'object_kind', 'object_index'],
                    ['object_name_hash', 'object_name_length', 'file_id', 'object_kind', 'object_index'],
                    ['file_id', 'object_kind', 'public_export_hash', 'object_index'],
                ],
            ],
            'ue_uedb5_dependency_edges' => [
                'cardinality' => 'one_per_persisted_dependency_result',
                'columns' => [
                    'file_id', 'source_kind', 'source_index', 'classification', 'outcome',
                    'required_package_key_kind', 'required_package_key',
                    'required_object_key_kind', 'required_object_key',
                    'resolved_file_id', 'resolved_object_kind', 'resolved_object_index',
                ],
                'indexes' => [
                    ['file_id', 'source_kind', 'source_index'],
                    ['required_package_key_kind', 'required_package_key', 'outcome', 'file_id'],
                    ['resolved_file_id', 'file_id'],
                ],
            ],            'ue_uedb5_dependency_packages' => [
                'cardinality' => 'one_per_file_and_required_package',
                'columns' => [
                    'game_id', 'file_id', 'package_key_kind', 'package_key',
                    'required_package_name', 'dependency_count', 'resolved_count',
                    'missing_count', 'package_only_count', 'common_count',
                    'unresolved_count', 'hard_missing_count', 'nonhard_missing_count',
                    'summary_outcome', 'provider_file_id', 'updated_at',
                ],
                'indexes' => [
                    ['file_id', 'package_key_kind', 'package_key'],
                    ['game_id', 'summary_outcome', 'package_key_kind', 'package_key', 'file_id'],
                    ['package_key_kind', 'package_key', 'game_id', 'summary_outcome', 'file_id'],
                    ['provider_file_id', 'file_id'],
                ],
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function optionalTables(): array
    {
        return [
            'ue_uedb5_object_path_candidates' => [
                'default_enabled' => false,
                'requires_measured_query_contract' => true,
                'cardinality' => 'one_per_indexed_object_only_when_enabled',
                'columns' => ['file_id', 'object_kind', 'object_index', 'path_hash_ci'],
                'indexes' => [
                    ['path_hash_ci', 'file_id', 'object_kind', 'object_index'],
                    ['file_id'],
                ],
            ],
        ];
    }
    /** @return array<string,array<string,string>> */
    public static function highCardinalityColumnTypes(): array
    {
        return [
            'ue_uedb5_search_keys' => [
                'key_hash' => 'BINARY(16)', 'key_length' => 'INT UNSIGNED',
                'key_fingerprint' => 'BINARY(32)', 'normalized_text' => 'MEDIUMBLOB',
            ],
            'ue_uedb5_name_candidates' => [
                'file_id' => 'BIGINT UNSIGNED', 'name_key_hash' => 'BINARY(16)',
                'name_key_length' => 'INT UNSIGNED', 'first_name_index' => 'INT UNSIGNED',
            ],
            'ue_uedb5_object_candidates' => [
                'file_id' => 'BIGINT UNSIGNED', 'object_kind' => 'TINYINT UNSIGNED',
                'object_index' => 'INT UNSIGNED', 'object_name_hash' => 'BINARY(16)',
                'object_name_length' => 'INT UNSIGNED', 'public_export_hash' => 'BINARY(8) NULL',
            ],
            'ue_uedb5_dependency_edges' => [
                'file_id' => 'BIGINT UNSIGNED', 'source_kind' => 'TINYINT UNSIGNED',
                'source_index' => 'INT UNSIGNED', 'classification' => 'TINYINT UNSIGNED',
                'outcome' => 'TINYINT UNSIGNED', 'required_package_key' => 'VARBINARY(16) NULL',
                'required_object_key' => 'VARBINARY(16) NULL', 'resolved_file_id' => 'BIGINT UNSIGNED NULL',
                'resolved_object_kind' => 'TINYINT UNSIGNED NULL', 'resolved_object_index' => 'INT UNSIGNED NULL',
            ],
        ];
    }

    /**
     * Source-shaped fields that must stay in UEDB5 and must not be copied into
     * baseline per-object SQL projections merely to avoid reading the file.
     *
     * @return list<string>
     */
    public static function forbiddenPerObjectSourceColumns(): array
    {
        return [
            'class_package', 'class_name', 'class_index', 'super_index',
            'template_index', 'outer_index', 'object_flags', 'package_flags',
            'serial_size', 'serial_offset', 'cooked_serial_size',
            'cooked_serial_offset', 'cooked_serial_layout_size',
            'preload_dependency_count', 'first_export_dependency',
            'serialization_before_serialization_dependencies',
            'create_before_serialization_dependencies',
            'serialization_before_create_dependencies',
            'create_before_create_dependencies',
            'script_serialization_start_offset', 'script_serialization_end_offset',
            'filter_flags', 'raw_type_and_id', 'value_u62',
        ];
    }

    /** @return list<string> */
    public static function rules(): array
    {
        return [
            'sql_is_candidate_accelerator_not_source_of_truth',
            'uedb5_hydrates_authoritative_verifyimport_identity',
            'v5_projection_tables_coexist_with_v4_until_cutover',
            'baseline_object_projection_contains_no_class_outer_flag_or_serialization_graph',
            'hash_collisions_may_add_candidates_but_must_never_authorize_a_match',
            'object_path_projection_is_optional_and_requires_measured_query_need',
            'new_projection_requires_query_index_cardinality_and_storage_justification',
            'no_auto_increment_term_id_in_v5_projection',
        ];
    }
}
