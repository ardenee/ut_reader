-- Supports game file-list dependency filters and per-page dependency summaries.
-- Apply during a maintenance window on a large catalog.

SET @db_name := DATABASE();

SET @sql := IF(
  EXISTS(
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=@db_name
      AND TABLE_NAME='ue_dependencies'
      AND INDEX_NAME='idx_ue_deps_file_status'
  ),
  'SELECT 1',
  'ALTER TABLE ue_dependencies ADD KEY idx_ue_deps_file_status (file_id, status)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
