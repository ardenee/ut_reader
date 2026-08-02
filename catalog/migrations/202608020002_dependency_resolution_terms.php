<?php
declare(strict_types=1);

return [
    'version' => '202608020002',
    'description' => 'Preserve exact dependency resolution source and confidence labels through compact term references.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_dependency_links');
        $schema->requireTable('ue_terms');

        $schema->ensureColumn(
            'ue_dependency_links',
            'resolution_source_term_id',
            'ALTER TABLE ue_dependency_links '
            . 'ADD COLUMN resolution_source_term_id INT UNSIGNED NULL AFTER resolution_source'
        );
        $schema->ensureColumn(
            'ue_dependency_links',
            'resolution_confidence_term_id',
            'ALTER TABLE ue_dependency_links '
            . 'ADD COLUMN resolution_confidence_term_id INT UNSIGNED NULL AFTER resolution_confidence'
        );
        $schema->ensureIndex(
            'ue_dependency_links',
            'idx_ue_dependency_source_term',
            'CREATE INDEX idx_ue_dependency_source_term '
            . 'ON ue_dependency_links (resolution_source_term_id,file_id)'
        );
        $schema->ensureIndex(
            'ue_dependency_links',
            'idx_ue_dependency_confidence_term',
            'CREATE INDEX idx_ue_dependency_confidence_term '
            . 'ON ue_dependency_links (resolution_confidence_term_id,file_id)'
        );
    },
];
