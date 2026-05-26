ALTER TABLE ue_files
  ADD COLUMN is_compressed TINYINT(1) NOT NULL DEFAULT 0 AFTER package_guid,
  ADD COLUMN compression_flags INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_compressed;

CREATE INDEX idx_ue_files_game_guid ON ue_files (game_id, package_guid);
CREATE INDEX idx_ue_files_compressed ON ue_files (is_compressed);
