SET @db_name := DATABASE();

CREATE TABLE IF NOT EXISTS ue_engines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  engine_key VARCHAR(32) NOT NULL,
  engine_name VARCHAR(190) NOT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_engines_key (engine_key),
  KEY idx_ue_engines_active_sort (is_active, sort_order, engine_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ue_engines(engine_key, engine_name, sort_order, notes, is_active) VALUES
('UE1', 'Unreal Engine 1', 10, 'Legacy Unreal package generation used by Unreal and Unreal Tournament era packages.', 1),
('UE2', 'Unreal Engine 2', 20, 'Unreal Engine 2 package generation used by Unreal II, UT2003 and UT2004 era packages.', 1),
('UE3', 'Unreal Engine 3', 30, 'Unreal Engine 3 package generation used by UT3/UPK era packages.', 1),
('UE4', 'Unreal Engine 4', 40, 'Unreal Engine 4 package generation.', 1),
('UE5', 'Unreal Engine 5', 50, 'Unreal Engine 5 package generation.', 1)
ON DUPLICATE KEY UPDATE
  engine_name=VALUES(engine_name),
  sort_order=VALUES(sort_order),
  is_active=VALUES(is_active);

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_game_profiles' AND INDEX_NAME='idx_ue_game_profiles_engine'),
  'SELECT 1',
  'ALTER TABLE ue_game_profiles ADD INDEX idx_ue_game_profiles_engine (engine_key)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
