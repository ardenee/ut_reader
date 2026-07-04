-- Profile-defined cross-engine compatibility. Rules are explicit per profile;
-- no automatic lower-engine compatibility is assumed.
SET @db_name := DATABASE();

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_game_profiles' AND COLUMN_NAME='compatibility_rules_json'),
  'SELECT 1',
  'ALTER TABLE ue_game_profiles ADD COLUMN compatibility_rules_json JSON NULL AFTER allowed_extensions_json'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_files' AND COLUMN_NAME='compatibility_status'),
  'SELECT 1',
  'ALTER TABLE ue_files ADD COLUMN compatibility_status VARCHAR(32) NOT NULL DEFAULT ''native'' AFTER detection_confidence'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_files' AND COLUMN_NAME='compatibility_label'),
  'SELECT 1',
  'ALTER TABLE ue_files ADD COLUMN compatibility_label VARCHAR(180) NULL AFTER compatibility_status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Known working starting point for UE2 games: permit only UE1 texture packages.
-- This is deliberately narrow. Add other formats only after a known-good file
-- has been tested in the target game.
UPDATE ue_game_profiles
SET compatibility_rules_json = JSON_ARRAY(
  JSON_OBJECT(
    'detected_engine', 'UE1',
    'reader_engine', 'UE1',
    'extensions', JSON_ARRAY('utx'),
    'package_version_min', 40,
    'package_version_max', 99,
    'label', 'Legacy UE1 texture package'
  )
)
WHERE engine_key='UE2'
  AND (compatibility_rules_json IS NULL OR JSON_LENGTH(compatibility_rules_json)=0);
