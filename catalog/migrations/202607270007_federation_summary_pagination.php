<?php
declare(strict_types=1);

return [
    'version' => '202607270007',
    'description' => 'Add representative dependency paths and federation cursor indexes.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_dependency_package_summaries');
        $schema->requireTable('ue_dependencies');
        $schema->requireTable('ue_federation_peer_files');

        $schema->ensureColumn(
            'ue_dependency_package_summaries',
            'example_required_object_path',
            'ALTER TABLE ue_dependency_package_summaries ADD COLUMN example_required_object_path VARCHAR(1000) NULL AFTER required_package'
        );

        $db->exec(
            'UPDATE ue_dependency_package_summaries s '
            . 'JOIN ('
            . 'SELECT file_id,required_package,MIN(NULLIF(required_object_path,"")) example_required_object_path '
            . 'FROM ue_dependencies '
            . 'WHERE required_package IS NOT NULL AND required_package<>"" '
            . 'GROUP BY file_id,required_package'
            . ') d ON d.file_id=s.file_id AND d.required_package=s.required_package '
            . 'SET s.example_required_object_path=d.example_required_object_path '
            . 'WHERE s.example_required_object_path IS NULL OR s.example_required_object_path=""'
        );

        $schema->ensureIndex(
            'ue_dependency_package_summaries',
            'idx_ue_dep_summary_game_package_missing',
            'ALTER TABLE ue_dependency_package_summaries ADD KEY idx_ue_dep_summary_game_package_missing(game_id,required_package(191),missing_count,file_id)'
        );
        $schema->ensureIndex(
            'ue_federation_peer_files',
            'idx_ue_peer_files_inventory_cursor',
            'ALTER TABLE ue_federation_peer_files ADD KEY idx_ue_peer_files_inventory_cursor(peer_id,is_base_game,remote_game_name(120),package_name(120),original_name(120),id)'
        );
    },
];
