<?php
declare(strict_types=1);

return [
    'version' => '202607210001',
    'description' => 'Store original Unreal PAK archives and link their entries to extracted catalog files.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_pak_archives',
            "CREATE TABLE ue_pak_archives ("
            . "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,"
            . "game_id INT UNSIGNED NOT NULL,"
            . "original_name VARCHAR(255) NOT NULL,"
            . "stored_name VARCHAR(255) NOT NULL,"
            . "relative_path VARCHAR(500) NOT NULL,"
            . "file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,"
            . "md5 CHAR(32) NOT NULL,"
            . "sha1 CHAR(40) NOT NULL,"
            . "sha256 CHAR(64) NOT NULL,"
            . "pak_version INT NULL,"
            . "mount_point VARCHAR(1000) NULL,"
            . "footer_layout VARCHAR(32) NULL,"
            . "index_offset BIGINT NULL,"
            . "index_size BIGINT NULL,"
            . "index_hash CHAR(40) NULL,"
            . "entry_count INT UNSIGNED NOT NULL DEFAULT 0,"
            . "extracted_count INT UNSIGNED NOT NULL DEFAULT 0,"
            . "skipped_count INT UNSIGNED NOT NULL DEFAULT 0,"
            . "status ENUM('processing','ready','failed') NOT NULL DEFAULT 'processing',"
            . "scan_notes MEDIUMTEXT NULL,"
            . "uploaded_by INT UNSIGNED NULL,"
            . "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,"
            . "updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,"
            . "PRIMARY KEY (id),"
            . "UNIQUE KEY uq_ue_pak_archives_game_sha256 (game_id,sha256),"
            . "KEY idx_ue_pak_archives_game_name (game_id,original_name),"
            . "KEY idx_ue_pak_archives_game_status (game_id,status),"
            . "KEY idx_ue_pak_archives_md5 (md5),"
            . "CONSTRAINT fk_ue_pak_archives_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE CASCADE,"
            . "CONSTRAINT fk_ue_pak_archives_user FOREIGN KEY (uploaded_by) REFERENCES ue_users(id) ON DELETE SET NULL"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $schema->ensureTable(
            'ue_pak_entries',
            "CREATE TABLE ue_pak_entries ("
            . "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,"
            . "pak_id BIGINT UNSIGNED NOT NULL,"
            . "entry_index INT UNSIGNED NOT NULL,"
            . "entry_path VARCHAR(1000) NOT NULL,"
            . "entry_name VARCHAR(255) NOT NULL,"
            . "extension VARCHAR(32) NOT NULL DEFAULT '',"
            . "data_offset BIGINT NULL,"
            . "stored_size BIGINT UNSIGNED NOT NULL DEFAULT 0,"
            . "uncompressed_size BIGINT UNSIGNED NOT NULL DEFAULT 0,"
            . "compression_method INT UNSIGNED NOT NULL DEFAULT 0,"
            . "compression_block_size INT UNSIGNED NOT NULL DEFAULT 0,"
            . "entry_hash CHAR(40) NULL,"
            . "is_encrypted TINYINT(1) NOT NULL DEFAULT 0,"
            . "was_extracted TINYINT(1) NOT NULL DEFAULT 0,"
            . "import_status VARCHAR(32) NOT NULL DEFAULT 'pending',"
            . "file_id BIGINT UNSIGNED NULL,"
            . "import_message TEXT NULL,"
            . "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,"
            . "updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,"
            . "PRIMARY KEY (id),"
            . "UNIQUE KEY uq_ue_pak_entries_index (pak_id,entry_index),"
            . "KEY idx_ue_pak_entries_path (pak_id,entry_path(191)),"
            . "KEY idx_ue_pak_entries_file (file_id),"
            . "KEY idx_ue_pak_entries_status (pak_id,import_status),"
            . "CONSTRAINT fk_ue_pak_entries_pak FOREIGN KEY (pak_id) REFERENCES ue_pak_archives(id) ON DELETE CASCADE,"
            . "CONSTRAINT fk_ue_pak_entries_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE SET NULL"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    },
];
