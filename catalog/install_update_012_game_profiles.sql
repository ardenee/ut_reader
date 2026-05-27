CREATE TABLE IF NOT EXISTS ue_game_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id INT UNSIGNED NOT NULL,
  engine_key VARCHAR(32) NOT NULL,
  allowed_extensions_json JSON NOT NULL,
  package_version_min INT NULL,
  package_version_max INT NULL,
  licensee_version_min INT NULL,
  licensee_version_max INT NULL,
  confidence_policy ENUM('strict','normal','loose') NOT NULL DEFAULT 'normal',
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_game_profiles_game (game_id),
  KEY idx_ue_game_profiles_engine (engine_key),
  CONSTRAINT fk_ue_game_profiles_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db_name := DATABASE();

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_files' AND COLUMN_NAME='detected_engine_key'),
  'SELECT 1',
  'ALTER TABLE ue_files ADD COLUMN detected_engine_key VARCHAR(32) NULL AFTER extension'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_files' AND COLUMN_NAME='detected_package_version'),
  'SELECT 1',
  'ALTER TABLE ue_files ADD COLUMN detected_package_version INT NULL AFTER detected_engine_key'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_files' AND COLUMN_NAME='detected_licensee_version'),
  'SELECT 1',
  'ALTER TABLE ue_files ADD COLUMN detected_licensee_version INT NULL AFTER detected_package_version'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_files' AND COLUMN_NAME='detection_confidence'),
  'SELECT 1',
  'ALTER TABLE ue_files ADD COLUMN detection_confidence ENUM(''high'',''medium'',''low'',''mismatch'',''unknown'') NOT NULL DEFAULT ''unknown'' AFTER detected_licensee_version'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_files' AND COLUMN_NAME='detection_notes'),
  'SELECT 1',
  'ALTER TABLE ue_files ADD COLUMN detection_notes TEXT NULL AFTER detection_confidence'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO ue_game_profiles(game_id, engine_key, allowed_extensions_json, package_version_min, package_version_max, confidence_policy, notes)
SELECT id, 'UE1', JSON_ARRAY('u','unr','utx','umx','uax'), 60, 69, 'normal', 'UE1 era package profile. Exact version ranges can be refined from known-good samples.'
FROM ue_games WHERE slug IN ('unreal','ut99')
ON DUPLICATE KEY UPDATE engine_key=VALUES(engine_key), allowed_extensions_json=VALUES(allowed_extensions_json), package_version_min=VALUES(package_version_min), package_version_max=VALUES(package_version_max), notes=VALUES(notes);

INSERT INTO ue_game_profiles(game_id, engine_key, allowed_extensions_json, package_version_min, package_version_max, confidence_policy, notes)
SELECT id, 'UE2', JSON_ARRAY('u','un2','ut2','utx','usx','ukx','uax'), 100, 130, 'normal', 'UE2/UE2.5 package profile. Shared across Unreal II, UT2003 and UT2004; header can identify engine family but not always exact game.'
FROM ue_games WHERE slug IN ('unreal2','ut2003','ut2004')
ON DUPLICATE KEY UPDATE engine_key=VALUES(engine_key), allowed_extensions_json=VALUES(allowed_extensions_json), package_version_min=VALUES(package_version_min), package_version_max=VALUES(package_version_max), notes=VALUES(notes);

INSERT INTO ue_game_profiles(game_id, engine_key, allowed_extensions_json, package_version_min, package_version_max, confidence_policy, notes)
SELECT id, 'UE3', JSON_ARRAY('ut3','upk','u'), 512, 512, 'loose', 'UE3/UT3 package profile. Compressed packages may need LZO support.'
FROM ue_games WHERE slug IN ('ut3')
ON DUPLICATE KEY UPDATE engine_key=VALUES(engine_key), allowed_extensions_json=VALUES(allowed_extensions_json), package_version_min=VALUES(package_version_min), package_version_max=VALUES(package_version_max), notes=VALUES(notes);

INSERT INTO ue_game_profiles(game_id, engine_key, allowed_extensions_json, package_version_min, package_version_max, confidence_policy, notes)
SELECT id, 'UE4', JSON_ARRAY('uasset','umap'), NULL, NULL, 'loose', 'UE4/UT Alpha package profile. UE4 packages may be versioned or unversioned/custom-version based.'
FROM ue_games WHERE slug IN ('ut4')
ON DUPLICATE KEY UPDATE engine_key=VALUES(engine_key), allowed_extensions_json=VALUES(allowed_extensions_json), package_version_min=VALUES(package_version_min), package_version_max=VALUES(package_version_max), notes=VALUES(notes);
