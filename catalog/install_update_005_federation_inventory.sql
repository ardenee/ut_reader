ALTER TABLE ue_federation_peers
  ADD COLUMN shared_secret_plain VARCHAR(128) NULL AFTER shared_secret_hash;

ALTER TABLE ue_federation_peer_files
  ADD COLUMN remote_game_name VARCHAR(160) NULL AFTER game_id,
  ADD COLUMN remote_engine_key VARCHAR(32) NULL AFTER remote_game_name;

CREATE INDEX idx_ue_federation_peer_files_remote_file ON ue_federation_peer_files (peer_id, remote_file_id);
CREATE INDEX idx_ue_federation_peer_files_game_name ON ue_federation_peer_files (remote_game_name);
