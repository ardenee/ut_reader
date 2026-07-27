<?php
declare(strict_types=1);

return [
    'version' => '202607270005',
    'description' => 'Cache compact per-game file and dependency counters for common catalogue pages.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_games');
        $schema->requireTable('ue_files');
        $schema->requireTable('ue_dependency_package_summaries');

        // Older installations could create this table lazily through the admin
        // page. Ensure the cache backfill has the same authoritative source.
        $schema->ensureTable(
            'ue_base_game_files',
            'CREATE TABLE ue_base_game_files ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'game_id INT UNSIGNED NOT NULL,'
            . 'package_guid VARCHAR(80) NOT NULL,'
            . 'package_name VARCHAR(255) NULL,'
            . 'original_name VARCHAR(255) NULL,'
            . 'source_file_id BIGINT UNSIGNED NULL,'
            . 'notes TEXT NULL,'
            . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_ue_base_game_files_game_guid (game_id,package_guid),'
            . 'KEY idx_ue_base_game_files_game (game_id),'
            . 'KEY idx_ue_base_game_files_guid (package_guid),'
            . 'KEY idx_ue_base_game_files_source_file (source_file_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $schema->ensureTable(
            'ue_game_catalog_stats',
            'CREATE TABLE ue_game_catalog_stats ('
            . 'game_id INT UNSIGNED NOT NULL,'
            . 'file_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'verified_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'failed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'duplicate_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'unverified_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'total_size BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'verified_size BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'dependency_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'missing_dependency_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'resolved_dependency_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'package_only_dependency_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'common_dependency_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'missing_package_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'missing_base_game_dependency_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (game_id),'
            . 'KEY idx_ue_game_catalog_stats_updated (updated_at),'
            . 'CONSTRAINT fk_ue_game_catalog_stats_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $schema->ensureIndex(
            'ue_game_catalog_stats',
            'idx_ue_game_catalog_stats_updated',
            'ALTER TABLE ue_game_catalog_stats ADD KEY idx_ue_game_catalog_stats_updated(updated_at)'
        );

        (new \UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats($db))->rebuildAll();
    },
];
