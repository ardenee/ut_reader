<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202609250001',
    'description' => 'Add exact UE3 export identity fields to the existing v4 path projection.',
    'up' => static function (PDO $db, SchemaInspector $schema): void {
        $schema->ensureColumn(
            'ue_export_path_lookup',
            'class_package_term_id',
            'ALTER TABLE ue_export_path_lookup ADD COLUMN class_package_term_id INT UNSIGNED NULL AFTER class_term_id'
        );
        $schema->ensureColumn(
            'ue_export_path_lookup',
            'class_name_term_id',
            'ALTER TABLE ue_export_path_lookup ADD COLUMN class_name_term_id INT UNSIGNED NULL AFTER class_package_term_id'
        );
        $schema->ensureColumn(
            'ue_export_path_lookup',
            'object_flags',
            'ALTER TABLE ue_export_path_lookup ADD COLUMN object_flags BIGINT UNSIGNED NULL AFTER class_name_term_id'
        );
        $schema->ensureColumn(
            'ue_export_path_lookup',
            'outer_index',
            'ALTER TABLE ue_export_path_lookup ADD COLUMN outer_index INT NULL AFTER object_flags'
        );
    },
];
