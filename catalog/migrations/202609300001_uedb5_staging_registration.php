<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202609300001',
    'description' => 'Add side-by-side UEDB5 staging registration and minimal projection schema.',
    'up' => static function (PDO $db, SchemaInspector $schema): void {
        // Prepare the live registration table for a later atomic V5 cutover without
        // changing any current V4 rows or readers during this migration.
        $schema->ensureColumn(
            'ue_file_metadata',
            'block_count',
            'ALTER TABLE ue_file_metadata ADD COLUMN block_count INT UNSIGNED NULL AFTER export_count'
        );
        $schema->ensureColumn(
            'ue_file_metadata',
            'package_family',
            'ALTER TABLE ue_file_metadata ADD COLUMN package_family VARCHAR(32) NULL AFTER block_count'
        );
        $schema->ensureColumn(
            'ue_file_metadata',
            'source_policy',
            'ALTER TABLE ue_file_metadata ADD COLUMN source_policy VARCHAR(96) NULL AFTER package_family'
        );
        $schema->ensureColumn(
            'ue_file_metadata',
            'section_counts_json',
            'ALTER TABLE ue_file_metadata ADD COLUMN section_counts_json JSON NULL AFTER source_policy'
        );

        $schema->ensureTable(
            'ue_uedb5_files',
            'CREATE TABLE ue_uedb5_files ('
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'game_id INT UNSIGNED NOT NULL,'
            . 'format_version SMALLINT UNSIGNED NOT NULL,'
            . 'codec TINYINT UNSIGNED NOT NULL,'
            . 'compressed_size BIGINT UNSIGNED NOT NULL,'
            . 'uncompressed_size BIGINT UNSIGNED NOT NULL,'
            . 'payload_sha256 BINARY(32) NOT NULL,'
            . 'block_count INT UNSIGNED NOT NULL,'
            . 'package_family VARCHAR(32) NOT NULL,'
            . 'source_policy VARCHAR(96) NOT NULL,'
            . 'package_key_kind TINYINT UNSIGNED NOT NULL,'
            . 'package_key VARBINARY(16) NOT NULL,'
            . 'package_name VARCHAR(512) NOT NULL DEFAULT "",'
            . 'section_counts_json JSON NOT NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'PRIMARY KEY (file_id),'
            . 'KEY idx_ue_uedb5_files_package (game_id,package_key_kind,package_key,file_id),'
            . 'CONSTRAINT fk_ue_uedb5_files_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $schema->ensureTable(
            'ue_uedb5_provider_keys',
            'CREATE TABLE ue_uedb5_provider_keys ('
            . 'source_kind TINYINT UNSIGNED NOT NULL,'
            . 'source_id BIGINT UNSIGNED NOT NULL,'
            . 'game_id INT UNSIGNED NOT NULL,'
            . 'package_key_kind TINYINT UNSIGNED NOT NULL,'
            . 'package_key VARBINARY(16) NOT NULL,'
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY (source_kind,source_id),'
            . 'KEY idx_ue_uedb5_provider_lookup (game_id,package_key_kind,package_key,file_id),'
            . 'KEY idx_ue_uedb5_provider_file (file_id),'
            . 'CONSTRAINT fk_ue_uedb5_provider_file FOREIGN KEY (file_id) REFERENCES ue_uedb5_files(file_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $schema->ensureTable(
            'ue_uedb5_search_keys',
            'CREATE TABLE ue_uedb5_search_keys ('
            . 'key_hash BINARY(16) NOT NULL,'
            . 'key_length INT UNSIGNED NOT NULL,'
            . 'key_fingerprint BINARY(32) NOT NULL,'
            . 'normalized_text MEDIUMBLOB NOT NULL,'
            . 'PRIMARY KEY (key_fingerprint),'
            . 'KEY idx_ue_uedb5_search_hash (key_hash,key_length),'
            . 'KEY idx_ue_uedb5_search_prefix (normalized_text(191))'
            . ') ENGINE=InnoDB'
        );

        $schema->ensureTable(
            'ue_uedb5_name_candidates',
            'CREATE TABLE ue_uedb5_name_candidates ('
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'name_key_hash BINARY(16) NOT NULL,'
            . 'name_key_length INT UNSIGNED NOT NULL,'
            . 'name_key_fingerprint BINARY(32) NOT NULL,'
            . 'first_name_index INT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY (file_id,name_key_fingerprint),'
            . 'KEY idx_ue_uedb5_name_lookup (name_key_hash,name_key_length,name_key_fingerprint,file_id),'
            . 'CONSTRAINT fk_ue_uedb5_name_file FOREIGN KEY (file_id) REFERENCES ue_uedb5_files(file_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB'
        );

        $schema->ensureTable(
            'ue_uedb5_object_candidates',
            'CREATE TABLE ue_uedb5_object_candidates ('
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'object_kind TINYINT UNSIGNED NOT NULL,'
            . 'object_index INT UNSIGNED NOT NULL,'
            . 'object_name_hash BINARY(16) NOT NULL,'
            . 'object_name_length INT UNSIGNED NOT NULL,'
            . 'public_export_hash BINARY(8) NULL,'
            . 'PRIMARY KEY (file_id,object_kind,object_index),'
            . 'KEY idx_ue_uedb5_object_name (object_name_hash,object_name_length,file_id,object_kind,object_index),'
            . 'KEY idx_ue_uedb5_public_export (file_id,object_kind,public_export_hash,object_index),'
            . 'CONSTRAINT fk_ue_uedb5_object_file FOREIGN KEY (file_id) REFERENCES ue_uedb5_files(file_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB'
        );

        $schema->ensureTable(
            'ue_uedb5_dependency_edges',
            'CREATE TABLE ue_uedb5_dependency_edges ('
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'source_kind TINYINT UNSIGNED NOT NULL,'
            . 'source_index INT UNSIGNED NOT NULL,'
            . 'classification TINYINT UNSIGNED NOT NULL,'
            . 'outcome TINYINT UNSIGNED NOT NULL,'
            . 'required_package_key_kind TINYINT UNSIGNED NULL,'
            . 'required_package_key VARBINARY(16) NULL,'
            . 'required_object_key_kind TINYINT UNSIGNED NULL,'
            . 'required_object_key VARBINARY(16) NULL,'
            . 'resolved_file_id BIGINT UNSIGNED NULL,'
            . 'resolved_object_kind TINYINT UNSIGNED NULL,'
            . 'resolved_object_index INT UNSIGNED NULL,'
            . 'PRIMARY KEY (file_id,source_kind,source_index),'
            . 'KEY idx_ue_uedb5_dep_required (required_package_key_kind,required_package_key,outcome,file_id),'
            . 'KEY idx_ue_uedb5_dep_resolved (resolved_file_id,file_id),'
            . 'CONSTRAINT fk_ue_uedb5_dep_file FOREIGN KEY (file_id) REFERENCES ue_uedb5_files(file_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_ue_uedb5_dep_resolved_file FOREIGN KEY (resolved_file_id) REFERENCES ue_uedb5_files(file_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB'
        );

        $schema->ensureTable(
            'ue_uedb5_dependency_packages',
            'CREATE TABLE ue_uedb5_dependency_packages ('
            . 'game_id INT UNSIGNED NOT NULL,'
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'package_key_kind TINYINT UNSIGNED NOT NULL,'
            . 'package_key VARBINARY(16) NOT NULL,'
            . 'required_package_name VARCHAR(512) NOT NULL DEFAULT "",'
            . 'dependency_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'resolved_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'missing_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'package_only_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'common_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'unresolved_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'hard_missing_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'nonhard_missing_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'summary_outcome TINYINT UNSIGNED NOT NULL,'
            . 'provider_file_id BIGINT UNSIGNED NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'PRIMARY KEY (file_id,package_key_kind,package_key),'
            . 'KEY idx_ue_uedb5_dep_pkg_status (game_id,summary_outcome,package_key_kind,package_key,file_id),'
            . 'KEY idx_ue_uedb5_dep_pkg_lookup (package_key_kind,package_key,game_id,summary_outcome,file_id),'
            . 'KEY idx_ue_uedb5_dep_pkg_provider (provider_file_id,file_id),'
            . 'CONSTRAINT fk_ue_uedb5_dep_pkg_file FOREIGN KEY (file_id) REFERENCES ue_uedb5_files(file_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_ue_uedb5_dep_pkg_provider FOREIGN KEY (provider_file_id) REFERENCES ue_uedb5_files(file_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
