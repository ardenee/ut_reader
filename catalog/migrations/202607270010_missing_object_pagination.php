<?php
declare(strict_types=1);

return [
    'version' => '202607270010',
    'description' => 'Add bounded missing-object drill-down cursor indexes.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_dependencies');
        $schema->ensureIndex(
            'ue_dependencies',
            'idx_ue_deps_missing_package_cursor',
            'ALTER TABLE ue_dependencies ADD KEY idx_ue_deps_missing_package_cursor (required_package,status,file_id,id)'
        );
        $schema->ensureIndex(
            'ue_dependencies',
            'idx_ue_deps_missing_file_cursor',
            'ALTER TABLE ue_dependencies ADD KEY idx_ue_deps_missing_file_cursor (file_id,status,required_package,id)'
        );
    },
];
