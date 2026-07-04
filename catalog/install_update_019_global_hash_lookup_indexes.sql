-- Supports exact MD5 and SHA1 global search lookups.
-- Apply after install_update_014_game_scoped_file_hashes.sql.

SET @db_name := DATABASE();

SET @sql := IF(
  EXISTS(
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=@db_name
      AND TABLE_NAME='ue_files'
      AND INDEX_NAME='idx_ue_files_md5_global'
  ),
  'SELECT 1',
  'ALTER TABLE ue_files ADD KEY idx_ue_files_md5_global (md5)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=@db_name
      AND TABLE_NAME='ue_files'
      AND INDEX_NAME='idx_ue_files_sha1_global'
  ),
  'SELECT 1',
  'ALTER TABLE ue_files ADD KEY idx_ue_files_sha1_global (sha1)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
