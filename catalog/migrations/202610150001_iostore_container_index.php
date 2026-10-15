<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
 'version'=>'202610150001',
 'description'=>'IoStore container sets and FPackageId member index for Package Viewer',
 'up'=>static function(PDO $db,SchemaInspector $inspector):void {
  $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ue_iostore_containers (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 game_id INT UNSIGNED NOT NULL,
 name VARCHAR(255) NOT NULL,
 utoc_sha256 CHAR(64) NOT NULL,
 utoc_relative_path VARCHAR(500) NOT NULL,
 container_id CHAR(16) NOT NULL,
 toc_version INT UNSIGNED NOT NULL,
 container_flags INT UNSIGNED NOT NULL,
 package_count INT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('indexing','indexed','ready','failed') NOT NULL DEFAULT 'indexing',
 error_text TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_iostore_game_toc (game_id,utoc_sha256),
 KEY idx_iostore_game_status (game_id,status),
 CONSTRAINT fk_iostore_game FOREIGN KEY(game_id) REFERENCES ue_games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
  $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ue_iostore_container_parts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 container_id BIGINT UNSIGNED NOT NULL,
 part_index INT UNSIGNED NOT NULL,
 relative_path VARCHAR(500) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 sha256 CHAR(64) NOT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_iostore_part (container_id,part_index),
 CONSTRAINT fk_iostore_part_container FOREIGN KEY(container_id)
 REFERENCES ue_iostore_containers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
  $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ue_iostore_packages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 container_id BIGINT UNSIGNED NOT NULL,
 toc_index INT UNSIGNED NOT NULL,
 package_id BINARY(8) NOT NULL,
 chunk_index INT UNSIGNED NOT NULL DEFAULT 0,
 logical_size BIGINT UNSIGNED NOT NULL,
 file_id BIGINT UNSIGNED NULL,
 publication_status ENUM('indexed','published','failed') NOT NULL DEFAULT 'indexed',
 PRIMARY KEY(id),
 UNIQUE KEY uq_iostore_chunk (container_id,toc_index),
 KEY idx_iostore_container_package (container_id,package_id),
 KEY idx_iostore_file (file_id),
 CONSTRAINT fk_iostore_package_container FOREIGN KEY(container_id)
 REFERENCES ue_iostore_containers(id) ON DELETE CASCADE,
 CONSTRAINT fk_iostore_package_file FOREIGN KEY(file_id)
 REFERENCES ue_files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
 }
];
