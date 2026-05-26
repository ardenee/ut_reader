CREATE TABLE IF NOT EXISTS ue_federation_settings (
  setting_name VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ue_federation_peers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  peer_role ENUM('parent','child') NOT NULL,
  site_name VARCHAR(160) NOT NULL,
  site_url VARCHAR(1000) NOT NULL,
  peer_site_id CHAR(36) NOT NULL,
  peer_fingerprint VARCHAR(128) NOT NULL,
  shared_secret_hash VARCHAR(255) NULL,
  permissions_json JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_federation_peer_site_id (peer_site_id),
  KEY idx_ue_federation_peers_role (peer_role),
  KEY idx_ue_federation_peers_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ue_federation_nonces (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  peer_id INT UNSIGNED NULL,
  nonce VARCHAR(128) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_federation_nonce (nonce),
  KEY idx_ue_federation_nonces_peer (peer_id),
  KEY idx_ue_federation_nonces_created (created_at),
  CONSTRAINT fk_ue_federation_nonces_peer FOREIGN KEY (peer_id) REFERENCES ue_federation_peers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ue_federation_peer_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  peer_id INT UNSIGNED NOT NULL,
  game_id INT UNSIGNED NULL,
  remote_file_id BIGINT UNSIGNED NULL,
  package_name VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  extension VARCHAR(32) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  md5 CHAR(32) NULL,
  sha1 CHAR(40) NULL,
  package_guid VARCHAR(80) NULL,
  is_compressed TINYINT(1) NOT NULL DEFAULT 0,
  compression_flags INT UNSIGNED NOT NULL DEFAULT 0,
  import_count INT UNSIGNED NOT NULL DEFAULT 0,
  export_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_seen_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_federation_peer_file_guid (peer_id, package_guid, md5),
  KEY idx_ue_federation_peer_files_peer (peer_id),
  KEY idx_ue_federation_peer_files_guid (package_guid),
  KEY idx_ue_federation_peer_files_md5 (md5),
  CONSTRAINT fk_ue_federation_peer_files_peer FOREIGN KEY (peer_id) REFERENCES ue_federation_peers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_federation_peer_files_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ue_federation_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  peer_id INT UNSIGNED NOT NULL,
  direction ENUM('child_to_parent','parent_to_child') NOT NULL,
  status ENUM('draft','submitted','updated','approved','part_approved','denied','cancelled','downloading','completed','failed') NOT NULL DEFAULT 'draft',
  request_hash CHAR(64) NOT NULL,
  title VARCHAR(255) NULL,
  notes TEXT NULL,
  submitted_at TIMESTAMP NULL DEFAULT NULL,
  approved_at TIMESTAMP NULL DEFAULT NULL,
  approved_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ue_federation_requests_peer (peer_id),
  KEY idx_ue_federation_requests_status (status),
  KEY idx_ue_federation_requests_hash (request_hash),
  CONSTRAINT fk_ue_federation_requests_peer FOREIGN KEY (peer_id) REFERENCES ue_federation_peers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ue_federation_request_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id BIGINT UNSIGNED NOT NULL,
  required_package VARCHAR(255) NOT NULL,
  required_object_path VARCHAR(1000) NOT NULL,
  wanted_guid VARCHAR(80) NULL,
  wanted_md5 CHAR(32) NULL,
  local_file_id BIGINT UNSIGNED NULL,
  peer_file_id BIGINT UNSIGNED NULL,
  status ENUM('requested','approved','denied','queued','downloading','downloaded','imported','failed','skipped_already_have') NOT NULL DEFAULT 'requested',
  status_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ue_federation_request_items_request (request_id),
  KEY idx_ue_federation_request_items_status (status),
  CONSTRAINT fk_ue_federation_request_items_request FOREIGN KEY (request_id) REFERENCES ue_federation_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_federation_request_items_file FOREIGN KEY (local_file_id) REFERENCES ue_files(id) ON DELETE SET NULL,
  CONSTRAINT fk_ue_federation_request_items_peer_file FOREIGN KEY (peer_file_id) REFERENCES ue_federation_peer_files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ue_federation_transfer_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  peer_id INT UNSIGNED NOT NULL,
  request_item_id BIGINT UNSIGNED NULL,
  direction ENUM('upload_to_parent','download_from_parent','parent_pull_from_child') NOT NULL,
  remote_file_id BIGINT UNSIGNED NULL,
  local_file_id BIGINT UNSIGNED NULL,
  status ENUM('queued','running','downloaded','imported','failed','cancelled') NOT NULL DEFAULT 'queued',
  bytes_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
  bytes_done BIGINT UNSIGNED NOT NULL DEFAULT 0,
  speed_limit_kbps INT UNSIGNED NOT NULL DEFAULT 0,
  wait_after_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at TIMESTAMP NULL DEFAULT NULL,
  finished_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ue_federation_transfer_peer (peer_id),
  KEY idx_ue_federation_transfer_status (status),
  CONSTRAINT fk_ue_federation_transfer_peer FOREIGN KEY (peer_id) REFERENCES ue_federation_peers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_federation_transfer_item FOREIGN KEY (request_item_id) REFERENCES ue_federation_request_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_ue_federation_transfer_file FOREIGN KEY (local_file_id) REFERENCES ue_files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ue_federation_transfer_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  peer_id INT UNSIGNED NULL,
  transfer_job_id BIGINT UNSIGNED NULL,
  level ENUM('INFO','WARN','ERROR') NOT NULL DEFAULT 'INFO',
  event VARCHAR(120) NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ue_federation_logs_peer (peer_id),
  KEY idx_ue_federation_logs_job (transfer_job_id),
  KEY idx_ue_federation_logs_created (created_at),
  CONSTRAINT fk_ue_federation_logs_peer FOREIGN KEY (peer_id) REFERENCES ue_federation_peers(id) ON DELETE SET NULL,
  CONSTRAINT fk_ue_federation_logs_job FOREIGN KEY (transfer_job_id) REFERENCES ue_federation_transfer_jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ue_federation_settings(setting_name, setting_value) VALUES
('site_role', 'standalone'),
('site_name', ''),
('site_url', ''),
('site_id', ''),
('site_fingerprint', ''),
('parent_enabled', '0'),
('child_enabled', '0'),
('allow_parent_pull_from_child', '1'),
('allow_child_request_from_parent', '1'),
('max_download_kbps', '0'),
('max_upload_kbps', '0'),
('delay_between_downloads_seconds', '5'),
('delay_between_uploads_seconds', '5'),
('max_files_per_transfer_run', '1'),
('max_transfer_file_size_mb', '1024'),
('auto_import_downloads', '1'),
('require_https_for_remote_sites', '1'),
('api_nonce_ttl_seconds', '300'),
('transfer_token_ttl_seconds', '600'),
('log_retention_days', '90');
