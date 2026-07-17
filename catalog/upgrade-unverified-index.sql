-- UnrealDB: database-backed unverified staging
-- Run once on an existing catalogue before indexing the current queue.
-- The application also checks and applies these changes from the admin pages.

ALTER TABLE ue_files
  MODIFY game_id INT UNSIGNED NULL;

ALTER TABLE ue_files
  MODIFY scan_status ENUM('verified','unverified','duplicate','failed') NOT NULL DEFAULT 'verified';

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ue_files' AND COLUMN_NAME='source_relative_path')=0,'ALTER TABLE ue_files ADD COLUMN source_relative_path VARCHAR(1024) NULL AFTER original_name','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ue_files' AND COLUMN_NAME='unverified_queue_key')=0,'ALTER TABLE ue_files ADD COLUMN unverified_queue_key CHAR(64) NULL AFTER scan_status','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ue_files' AND COLUMN_NAME='unverified_queue_game_id')=0,'ALTER TABLE ue_files ADD COLUMN unverified_queue_game_id INT UNSIGNED NULL AFTER unverified_queue_key','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ue_files' AND COLUMN_NAME='unverified_queue_name')=0,'ALTER TABLE ue_files ADD COLUMN unverified_queue_name VARCHAR(255) NULL AFTER unverified_queue_game_id','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ue_files' AND COLUMN_NAME='unverified_reason')=0,'ALTER TABLE ue_files ADD COLUMN unverified_reason TEXT NULL AFTER unverified_queue_name','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ue_files' AND INDEX_NAME='uq_ue_files_unverified_queue_key')=0,'ALTER TABLE ue_files ADD UNIQUE KEY uq_ue_files_unverified_queue_key (unverified_queue_key)','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ue_files' AND INDEX_NAME='idx_ue_files_scan_status')=0,'ALTER TABLE ue_files ADD KEY idx_ue_files_scan_status (scan_status)','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ue_files' AND INDEX_NAME='idx_ue_files_unverified_queue')=0,'ALTER TABLE ue_files ADD KEY idx_ue_files_unverified_queue (unverified_queue_game_id,unverified_queue_name)','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
