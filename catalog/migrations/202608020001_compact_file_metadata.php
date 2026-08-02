<?php
declare(strict_types=1);

return [
    'version' => '202608020001',
    'description' => 'Add compressed per-file metadata registration and compact lookup tables.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_files');

        $schema->ensureTable(
            'ue_file_metadata',
            'CREATE TABLE ue_file_metadata ('
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'format_version SMALLINT UNSIGNED NOT NULL,'
            . 'codec TINYINT UNSIGNED NOT NULL,'
            . 'compressed_size BIGINT UNSIGNED NOT NULL,'
            . 'uncompressed_size BIGINT UNSIGNED NOT NULL,'
            . 'payload_sha256 BINARY(32) NOT NULL,'
            . 'name_count INT UNSIGNED NOT NULL,'
            . 'import_count INT UNSIGNED NOT NULL,'
            . 'export_count INT UNSIGNED NOT NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'PRIMARY KEY (file_id),'
            . 'CONSTRAINT fk_ue_file_metadata_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB'
        );

        $schema->ensureTable(
            'ue_terms',
            'CREATE TABLE ue_terms ('
            . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'value_hash BINARY(16) NOT NULL,'
            . 'value_length SMALLINT UNSIGNED NOT NULL,'
            . 'value_prefix VARBINARY(200) NOT NULL,'
            . 'is_overflow TINYINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_ue_terms_hash_length (value_hash,value_length)'
            . ') ENGINE=InnoDB'
        );

        $schema->ensureTable(
            'ue_export_lookup',
            'CREATE TABLE ue_export_lookup ('
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'export_index INT UNSIGNED NOT NULL,'
            . 'object_term_id INT UNSIGNED NOT NULL,'
            . 'class_term_id INT UNSIGNED NULL,'
            . 'path_hash BINARY(16) NOT NULL,'
            . 'PRIMARY KEY (file_id,export_index),'
            . 'KEY idx_ue_export_lookup_object (object_term_id,file_id),'
            . 'KEY idx_ue_export_lookup_path (path_hash,file_id)'
            . ') ENGINE=InnoDB'
        );

        $schema->ensureTable(
            'ue_dependency_links',
            'CREATE TABLE ue_dependency_links ('
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'import_index INT UNSIGNED NOT NULL,'
            . 'required_package_term_id INT UNSIGNED NOT NULL,'
            . 'required_path_hash BINARY(16) NOT NULL,'
            . 'resolved_file_id BIGINT UNSIGNED NULL,'
            . 'resolved_export_index INT UNSIGNED NULL,'
            . 'status TINYINT UNSIGNED NOT NULL,'
            . 'resolution_source TINYINT UNSIGNED NOT NULL,'
            . 'resolution_confidence TINYINT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY (file_id,import_index),'
            . 'KEY idx_ue_dependency_required (required_package_term_id,status),'
            . 'KEY idx_ue_dependency_resolved (resolved_file_id,resolved_export_index)'
            . ') ENGINE=InnoDB'
        );
    },
];
