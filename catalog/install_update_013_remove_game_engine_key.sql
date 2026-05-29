-- Move engine ownership fully to ue_game_profiles.
-- Run after install_update_012_game_profiles.sql.

SET @db_name := DATABASE();

-- Safety copy for existing installs. This preserves legacy values before the column is dropped.
UPDATE ue_game_profiles p
JOIN ue_games g ON g.id = p.game_id
SET p.engine_key = g.engine_key
WHERE p.is_active = 1
  AND (p.engine_key IS NULL OR p.engine_key = '')
  AND EXISTS (
    SELECT 1
    FROM information_schema.COLUMNS c
    WHERE c.TABLE_SCHEMA = @db_name
      AND c.TABLE_NAME = 'ue_games'
      AND c.COLUMN_NAME = 'engine_key'
  );

-- MySQL/MariaDB compatible conditional DROP COLUMN.
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='ue_games' AND COLUMN_NAME='engine_key'),
  'ALTER TABLE ue_games DROP COLUMN engine_key',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Confirm games with missing active profiles after cleanup.
SELECT g.id, g.name, g.slug
FROM ue_games g
LEFT JOIN ue_game_profiles p ON p.game_id = g.id AND p.is_active = 1
WHERE p.id IS NULL
ORDER BY g.name;
