SET @db_name := DATABASE();

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_files' AND INDEX_NAME='uq_ue_files_md5'),
  'ALTER TABLE ue_files DROP INDEX uq_ue_files_md5',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_files' AND INDEX_NAME='uq_ue_files_game_md5'),
  'SELECT 1',
  'ALTER TABLE ue_files ADD UNIQUE KEY uq_ue_files_game_md5 (game_id, md5)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
