<?php
declare(strict_types=1);

return [
    'version' => '202607270001',
    'description' => 'Add game-scoped search and dependency-resolution indexes for large catalogues.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->ensureIndex(
            'ue_files',
            'idx_ue_files_game_status_package',
            'ALTER TABLE ue_files ADD KEY idx_ue_files_game_status_package (game_id,scan_status,package_name,id)'
        );
        $schema->ensureIndex(
            'ue_files',
            'idx_ue_files_game_status_original',
            'ALTER TABLE ue_files ADD KEY idx_ue_files_game_status_original (game_id,scan_status,original_name,id)'
        );
        $schema->ensureIndex(
            'ue_file_package_aliases',
            'idx_ue_file_alias_game_original',
            'ALTER TABLE ue_file_package_aliases ADD KEY idx_ue_file_alias_game_original (game_id,original_name)'
        );
        $schema->ensureIndex(
            'ue_imports',
            'idx_ue_imports_root_file',
            'ALTER TABLE ue_imports ADD KEY idx_ue_imports_root_file (root_package,file_id)'
        );
        $schema->ensureIndex(
            'ue_exports',
            'idx_ue_exports_file_local',
            'ALTER TABLE ue_exports ADD KEY idx_ue_exports_file_local (file_id,local_path(191))'
        );
        $schema->ensureIndex(
            'ue_dependencies',
            'idx_ue_deps_required_file',
            'ALTER TABLE ue_dependencies ADD KEY idx_ue_deps_required_file (required_package,file_id)'
        );
    },
];
