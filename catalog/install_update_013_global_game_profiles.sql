SET @db_name := DATABASE();

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_game_profiles' AND COLUMN_NAME='profile_name'),
  'SELECT 1',
  'ALTER TABLE ue_game_profiles ADD COLUMN profile_name VARCHAR(190) NULL AFTER id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE ue_game_profiles p
JOIN ue_games g ON g.id=p.game_id
SET p.profile_name=CONCAT(g.name, ' Profile')
WHERE p.profile_name IS NULL OR p.profile_name='';

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_games' AND COLUMN_NAME='profile_id'),
  'SELECT 1',
  'ALTER TABLE ue_games ADD COLUMN profile_id BIGINT UNSIGNED NULL AFTER description'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE ue_games g
JOIN ue_game_profiles p ON p.game_id=g.id AND p.is_active=1
SET g.profile_id=p.id
WHERE g.profile_id IS NULL;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND CONSTRAINT_NAME='fk_ue_game_profiles_game'),
  'ALTER TABLE ue_game_profiles DROP FOREIGN KEY fk_ue_game_profiles_game',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_game_profiles' AND INDEX_NAME='uq_ue_game_profiles_game'),
  'ALTER TABLE ue_game_profiles DROP INDEX uq_ue_game_profiles_game',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE ue_game_profiles MODIFY game_id INT UNSIGNED NULL;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_game_profiles' AND INDEX_NAME='idx_ue_game_profiles_game_id'),
  'SELECT 1',
  'ALTER TABLE ue_game_profiles ADD INDEX idx_ue_game_profiles_game_id (game_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_game_profiles' AND INDEX_NAME='idx_ue_game_profiles_name'),
  'SELECT 1',
  'ALTER TABLE ue_game_profiles ADD INDEX idx_ue_game_profiles_name (profile_name)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_games' AND INDEX_NAME='idx_ue_games_profile_id'),
  'SELECT 1',
  'ALTER TABLE ue_games ADD INDEX idx_ue_games_profile_id (profile_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND CONSTRAINT_NAME='fk_ue_game_profiles_game'),
  'SELECT 1',
  'ALTER TABLE ue_game_profiles ADD CONSTRAINT fk_ue_game_profiles_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db_name AND CONSTRAINT_NAME='fk_ue_games_profile'),
  'SELECT 1',
  'ALTER TABLE ue_games ADD CONSTRAINT fk_ue_games_profile FOREIGN KEY (profile_id) REFERENCES ue_game_profiles(id) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
