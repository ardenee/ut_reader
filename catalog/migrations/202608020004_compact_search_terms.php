<?php
declare(strict_types=1);

return [
    'version' => '202608020004',
    'description' => 'Add compact Import object and Export path term references for search after legacy metadata removal.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_export_lookup');
        $schema->requireTable('ue_dependency_links');
        $schema->requireTable('ue_terms');

        $schema->ensureColumn(
            'ue_export_lookup',
            'local_path_term_id',
            'ALTER TABLE ue_export_lookup '
            . 'ADD COLUMN local_path_term_id INT UNSIGNED NULL AFTER path_hash'
        );
        $schema->ensureColumn(
            'ue_dependency_links',
            'import_object_term_id',
            'ALTER TABLE ue_dependency_links '
            . 'ADD COLUMN import_object_term_id INT UNSIGNED NULL AFTER import_class_name_term_id'
        );

        $schema->ensureIndex(
            'ue_export_lookup',
            'idx_ue_export_lookup_local_path',
            'CREATE INDEX idx_ue_export_lookup_local_path '
            . 'ON ue_export_lookup (local_path_term_id,file_id,export_index)'
        );
        $schema->ensureIndex(
            'ue_dependency_links',
            'idx_ue_dependency_import_object',
            'CREATE INDEX idx_ue_dependency_import_object '
            . 'ON ue_dependency_links (import_object_term_id,file_id,import_index)'
        );
        $schema->ensureIndex(
            'ue_terms',
            'idx_ue_terms_value_prefix',
            'CREATE INDEX idx_ue_terms_value_prefix ON ue_terms (value_prefix(100))'
        );
    },
];
