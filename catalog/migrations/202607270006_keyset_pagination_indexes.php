<?php
declare(strict_types=1);

return [
    'version' => '202607270006',
    'description' => 'Add stable game-scoped sort indexes for keyset pagination.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        foreach ([
            ['idx_ue_files_game_package_cursor', 'ALTER TABLE ue_files ADD KEY idx_ue_files_game_package_cursor (game_id,package_name,original_name,id)'],
            ['idx_ue_files_game_original_cursor', 'ALTER TABLE ue_files ADD KEY idx_ue_files_game_original_cursor (game_id,original_name,package_name,id)'],
            ['idx_ue_files_game_version_cursor', 'ALTER TABLE ue_files ADD KEY idx_ue_files_game_version_cursor (game_id,package_version,package_name,original_name,id)'],
            ['idx_ue_files_game_size_cursor', 'ALTER TABLE ue_files ADD KEY idx_ue_files_game_size_cursor (game_id,file_size,package_name,original_name,id)'],
            ['idx_ue_files_game_compression_cursor', 'ALTER TABLE ue_files ADD KEY idx_ue_files_game_compression_cursor (game_id,is_compressed,package_name,original_name,id)'],
            ['idx_ue_files_game_uploaded_cursor', 'ALTER TABLE ue_files ADD KEY idx_ue_files_game_uploaded_cursor (game_id,uploaded_at,package_name,original_name,id)'],
        ] as [$index, $sql]) {
            $schema->ensureIndex('ue_files', $index, $sql);
        }
    },
];
