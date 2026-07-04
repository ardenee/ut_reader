-- Supports the batched dependency resolver in catalog/lib/CatalogDependencyResolver.php.
-- Apply during a maintenance window on a large existing catalog because ALTER TABLE
-- may rebuild indexes depending on the installed MySQL/MariaDB version.

SET @db_name := DATABASE();

SET @sql := IF(
  EXISTS(
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=@db_name
      AND TABLE_NAME='ue_files'
      AND INDEX_NAME='idx_ue_files_game_package_uploaded'
  ),
  'SELECT 1',
  'ALTER TABLE ue_files ADD KEY idx_ue_files_game_package_uploaded (game_id, package_name, uploaded_at, id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=@db_name
      AND TABLE_NAME='ue_exports'
      AND INDEX_NAME='idx_ue_exports_full_path_file'
  ),
  'SELECT 1',
  'ALTER TABLE ue_exports ADD KEY idx_ue_exports_full_path_file (full_path(191), file_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
