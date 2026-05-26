ALTER TABLE ue_federation_join_requests
  ADD COLUMN request_token_hash CHAR(64) NULL AFTER claim_token_hash;

CREATE INDEX idx_ue_federation_join_request_token ON ue_federation_join_requests (request_token_hash);

INSERT IGNORE INTO ue_federation_settings(setting_name, setting_value) VALUES
('main_parent_url', 'https://utreader/catalog'),
('main_parent_join_request_id', ''),
('main_parent_join_request_token', ''),
('main_parent_join_status', 'none');
