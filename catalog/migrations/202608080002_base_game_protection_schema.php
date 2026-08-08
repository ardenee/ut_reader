<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Ensures existing upgraded installations have the base-game protection table already present in install.sql.
 * Why: Normal HTTP/worker runtime must never create schema implicitly; DDL belongs to migrations/install only.
 * Role: Migration-only schema ownership for ue_base_game_files.
 */
declare(strict_types=1);

return [
    'version' => '202608080002',
    'description' => 'Ensure base-game protection schema is migration-owned.',
    'up' => static function (
        PDO $db,
        \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema
    ): void {
        if ($schema->tableExists('ue_base_game_files')) {
            return;
        }

        $db->exec(<<<'SQL'
CREATE TABLE ue_base_game_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id INT UNSIGNED NOT NULL,
  package_guid VARCHAR(80) NOT NULL,
  package_name VARCHAR(255) NULL,
  original_name VARCHAR(255) NULL,
  source_file_id BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_base_game_files_game_guid (game_id, package_guid),
  KEY idx_ue_base_game_files_game (game_id),
  KEY idx_ue_base_game_files_guid (package_guid),
  KEY idx_ue_base_game_files_source_file (source_file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    },
];
