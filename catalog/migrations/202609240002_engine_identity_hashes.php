<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202609240002',
    'description' => 'Add normalized engine identity and path hashes to compact lookup projections.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        if (!$schema->columnExists('ue_legacy_export_identity_lookup', 'path_hash_ci')) {
            $db->exec(
                'ALTER TABLE ue_legacy_export_identity_lookup '
                . 'ADD COLUMN path_hash_ci BINARY(16) NULL AFTER identity_hash, '
                . 'ADD KEY idx_legacy_verify_path (file_id,path_hash_ci,export_index)'
            );
        }
        if (!$schema->columnExists('ue_export_lookup', 'path_hash_ci')) {
            $db->exec(
                'ALTER TABLE ue_export_lookup '
                . 'ADD COLUMN path_hash_ci BINARY(16) NULL AFTER path_hash, '
                . 'ADD KEY idx_ue_export_lookup_path_ci (path_hash_ci,file_id)'
            );
        }
        if (!$schema->columnExists('ue_dependency_links', 'verify_identity_hash')) {
            $db->exec(
                'ALTER TABLE ue_dependency_links '
                . 'ADD COLUMN verify_identity_hash BINARY(16) NULL AFTER required_path_hash, '
                . 'ADD COLUMN required_path_hash_ci BINARY(16) NULL AFTER verify_identity_hash, '
                . 'ADD KEY idx_ue_dependency_verify_identity '
                . '(required_package_term_id,verify_identity_hash,file_id), '
                . 'ADD KEY idx_ue_dependency_path_ci '
                . '(required_package_term_id,required_path_hash_ci,file_id)'
            );
        }
    },
];
