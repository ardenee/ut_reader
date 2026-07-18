<?php
declare(strict_types=1);

return [
    'version' => '202607180002',
    'description' => 'Add logical package aliases for shared physical file identities.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_file_package_aliases',
            "CREATE TABLE ue_file_package_aliases ("
            . "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,"
            . "file_id BIGINT UNSIGNED NOT NULL,"
            . "game_id INT UNSIGNED NOT NULL,"
            . "package_name VARCHAR(255) NOT NULL,"
            . "original_name VARCHAR(255) NOT NULL,"
            . "package_guid VARCHAR(80) NULL,"
            . "md5 CHAR(32) NOT NULL,"
            . "file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,"
            . "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,"
            . "PRIMARY KEY (id),"
            . "UNIQUE KEY uq_ue_file_alias_file_package (file_id, package_name),"
            . "KEY idx_ue_file_alias_game_package (game_id, package_name),"
            . "KEY idx_ue_file_alias_file (file_id),"
            . "KEY idx_ue_file_alias_game_guid_md5 (game_id, package_guid, md5),"
            . "CONSTRAINT fk_ue_file_alias_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE,"
            . "CONSTRAINT fk_ue_file_alias_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE CASCADE"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    },
];
