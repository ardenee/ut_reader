-- UnrealDB dependency metadata upgrade
-- Safe to run on an existing catalog before rebuilding dependencies.
-- Uses information_schema + prepared statements instead of ADD COLUMN IF NOT EXISTS,
-- because that syntax is not available on every MySQL/MariaDB version used by hosts.

SET @column_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ue_dependencies'
    AND COLUMN_NAME = 'resolution_source'
);
SET @sql = IF(
  @column_exists = 0,
  "ALTER TABLE ue_dependencies ADD COLUMN resolution_source VARCHAR(64) NOT NULL DEFAULT 'unknown' AFTER status",
  "SELECT 'ue_dependencies.resolution_source already exists' AS message"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ue_dependencies'
    AND COLUMN_NAME = 'resolution_confidence'
);
SET @sql = IF(
  @column_exists = 0,
  "ALTER TABLE ue_dependencies ADD COLUMN resolution_confidence VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER resolution_source",
  "SELECT 'ue_dependencies.resolution_confidence already exists' AS message"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ue_dependencies'
    AND INDEX_NAME = 'idx_ue_deps_resolution_source'
);
SET @sql = IF(
  @index_exists = 0,
  'CREATE INDEX idx_ue_deps_resolution_source ON ue_dependencies (resolution_source)',
  "SELECT 'idx_ue_deps_resolution_source already exists' AS message"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ue_dependencies'
    AND INDEX_NAME = 'idx_ue_deps_resolution_confidence'
);
SET @sql = IF(
  @index_exists = 0,
  'CREATE INDEX idx_ue_deps_resolution_confidence ON ue_dependencies (resolution_confidence)',
  "SELECT 'idx_ue_deps_resolution_confidence already exists' AS message"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ue_asset_registry_assets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id BIGINT UNSIGNED NOT NULL,
  object_path VARCHAR(1000) NOT NULL,
  package_name VARCHAR(255) NOT NULL,
  package_path VARCHAR(1000) NOT NULL,
  asset_name VARCHAR(255) NOT NULL,
  asset_class VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_ar_asset_file_object (file_id, object_path(191)),
  KEY idx_ue_ar_asset_package_name (package_name),
  KEY idx_ue_ar_asset_object_path (object_path(191)),
  KEY idx_ue_ar_asset_asset_name (asset_name),
  CONSTRAINT fk_ue_ar_asset_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ue_asset_registry_tags (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id BIGINT UNSIGNED NOT NULL,
  tag_name VARCHAR(255) NOT NULL,
  tag_value TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_ue_ar_tags_asset (asset_id),
  KEY idx_ue_ar_tags_name (tag_name),
  CONSTRAINT fk_ue_ar_tags_asset FOREIGN KEY (asset_id) REFERENCES ue_asset_registry_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ue_asset_registry_dependencies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id BIGINT UNSIGNED NOT NULL,
  source_asset_id BIGINT UNSIGNED NULL,
  dependency_object_path VARCHAR(1000) NOT NULL,
  dependency_type VARCHAR(64) NOT NULL DEFAULT 'unknown',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ue_ar_deps_file (file_id),
  KEY idx_ue_ar_deps_asset (source_asset_id),
  KEY idx_ue_ar_deps_object (dependency_object_path(191)),
  CONSTRAINT fk_ue_ar_deps_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_ar_deps_asset FOREIGN KEY (source_asset_id) REFERENCES ue_asset_registry_assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
