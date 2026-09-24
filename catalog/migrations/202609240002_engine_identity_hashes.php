<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202609240002',
    'description' => 'Add normalized engine identity and path hash projections for metadata format 4.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        // 202609240001 creates this table empty. A previous failed attempt at
        // this migration may already have added path_hash_ci, so keep this
        // repair idempotent and avoid touching the large historical projections.
        if (!$schema->columnExists('ue_legacy_export_identity_lookup', 'path_hash_ci')) {
            $db->exec(
                'ALTER TABLE ue_legacy_export_identity_lookup '
                . 'ADD COLUMN path_hash_ci BINARY(16) NULL AFTER identity_hash'
            );
        }
        if (!$schema->indexExists('ue_legacy_export_identity_lookup', 'idx_legacy_verify_path')) {
            $db->exec(
                'ALTER TABLE ue_legacy_export_identity_lookup '
                . 'ADD KEY idx_legacy_verify_path (file_id,path_hash_ci,export_index)'
            );
        }

        if (!$schema->tableExists('ue_export_path_lookup')) {
            $db->exec(
                'CREATE TABLE ue_export_path_lookup ('
                . 'file_id BIGINT UNSIGNED NOT NULL,'
                . 'export_index INT UNSIGNED NOT NULL,'
                . 'path_hash_ci BINARY(16) NOT NULL,'
                . 'local_path_term_id INT UNSIGNED NOT NULL,'
                . 'class_term_id INT UNSIGNED NULL,'
                . 'PRIMARY KEY (file_id,export_index),'
                . 'KEY idx_export_path_ci (path_hash_ci,file_id,export_index),'
                . 'KEY idx_export_path_file_hash (file_id,path_hash_ci,export_index)'
                . ') ENGINE=InnoDB'
            );
        }

        if (!$schema->tableExists('ue_dependency_identity_lookup')) {
            $db->exec(
                'CREATE TABLE ue_dependency_identity_lookup ('
                . 'file_id BIGINT UNSIGNED NOT NULL,'
                . 'import_index INT UNSIGNED NOT NULL,'
                . 'required_package_term_id INT UNSIGNED NOT NULL,'
                . 'verify_identity_hash BINARY(16) NULL,'
                . 'required_path_hash_ci BINARY(16) NOT NULL,'
                . 'PRIMARY KEY (file_id,import_index),'
                . 'KEY idx_dependency_verify_identity '
                . '(required_package_term_id,verify_identity_hash,file_id,import_index),'
                . 'KEY idx_dependency_path_ci '
                . '(required_package_term_id,required_path_hash_ci,file_id,import_index)'
                . ') ENGINE=InnoDB'
            );
        }
    },
];
