<?php
declare(strict_types=1);

return [
    'version' => '202608020003',
    'description' => 'Add compact dependency object and import-class term references for global detail pages.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_dependency_links');
        $schema->requireTable('ue_terms');

        $schema->ensureColumn(
            'ue_dependency_links',
            'required_object_term_id',
            'ALTER TABLE ue_dependency_links '
            . 'ADD COLUMN required_object_term_id INT UNSIGNED NULL AFTER required_path_hash'
        );
        $schema->ensureColumn(
            'ue_dependency_links',
            'import_class_package_term_id',
            'ALTER TABLE ue_dependency_links '
            . 'ADD COLUMN import_class_package_term_id INT UNSIGNED NULL AFTER required_object_term_id'
        );
        $schema->ensureColumn(
            'ue_dependency_links',
            'import_class_name_term_id',
            'ALTER TABLE ue_dependency_links '
            . 'ADD COLUMN import_class_name_term_id INT UNSIGNED NULL AFTER import_class_package_term_id'
        );

        $schema->ensureIndex(
            'ue_dependency_links',
            'idx_ue_dependency_file_status',
            'CREATE INDEX idx_ue_dependency_file_status '
            . 'ON ue_dependency_links (file_id,status,required_package_term_id,import_index)'
        );
        $schema->ensureIndex(
            'ue_dependency_links',
            'idx_ue_dependency_object_term',
            'CREATE INDEX idx_ue_dependency_object_term '
            . 'ON ue_dependency_links (required_object_term_id,status,file_id)'
        );
    },
];
