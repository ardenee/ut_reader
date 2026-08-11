<?php
/**
 * Cache exact per-game dependency evidence for unverified packages.
 */
declare(strict_types=1);

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202608110001',
    'description' => 'Cache exact unverified package game-match evidence for background refresh and fast page reads.',
    'up' => static function (PDO $db, SchemaInspector $schema): void {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS ue_unverified_game_match_cache ('
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'cache_version INT UNSIGNED NOT NULL DEFAULT 1,'
            . 'status VARCHAR(16) NOT NULL DEFAULT "pending",'
            . 'matches_json LONGTEXT NULL,'
            . 'match_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'exact_compatible_game_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_error VARCHAR(1000) NULL,'
            . 'calculated_at DATETIME NULL,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (file_id),'
            . 'KEY idx_ue_unverified_game_match_cache_status (status, updated_at),'
            . 'KEY idx_ue_unverified_game_match_cache_calculated (calculated_at),'
            . 'CONSTRAINT fk_ue_unverified_game_match_cache_file '
            . 'FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
