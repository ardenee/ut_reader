-- consolidated schema assembly boundary 1
__UNREALDB_INSTALL_PART_1__
-- consolidated schema assembly boundary 2
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_federation_peer_file_guid (peer_id, package_guid, md5),
  KEY idx_ue_federation_peer_files_peer (peer_id),
  KEY idx_ue_federation_peer_files_guid (package_guid),
  KEY idx_ue_federation_peer_files_md5 (md5),
  KEY idx_ue_federation_peer_files_remote_file (peer_id, remote_file_id),
  KEY idx_ue_federation_peer_files_game_name (remote_game_name),
  CONSTRAINT fk_ue_federation_peer_files_peer FOREIGN KEY (peer_id) REFERENCES ue_federation_peers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_federation_peer_files_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_federation_requests (
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

CREATE TABLE ue_federation_request_items (
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

CREATE TABLE ue_federation_transfer_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  peer_id INT UNSIGNED NOT NULL,
  request_item_id BIGINT UNSIGNED NULL,
  remote_request_item_id BIGINT UNSIGNED NULL,
  direction ENUM('upload_to_parent','download_from_parent','parent_pull_from_child') NOT NULL,
  remote_file_id BIGINT UNSIGNED NULL,
  local_file_id BIGINT UNSIGNED NULL,
  incoming_path VARCHAR(1000) NULL,
  downloaded_md5 CHAR(32) NULL,
  downloaded_sha1 CHAR(40) NULL,
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
  KEY idx_ue_federation_transfer_incoming (incoming_path(191)),
  KEY idx_ue_federation_transfer_remote_item (remote_request_item_id),
  CONSTRAINT fk_ue_federation_transfer_peer FOREIGN KEY (peer_id) REFERENCES ue_federation_peers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_federation_transfer_item FOREIGN KEY (request_item_id) REFERENCES ue_federation_request_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_ue_federation_transfer_file FOREIGN KEY (local_file_id) REFERENCES ue_files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_federation_transfer_logs (
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

CREATE TABLE ue_federation_join_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status ENUM('pending','approved','denied','claimed','expired') NOT NULL DEFAULT 'pending',
  requested_role ENUM('child') NOT NULL DEFAULT 'child',
  site_name VARCHAR(160) NOT NULL,
  site_url VARCHAR(1000) NOT NULL,
  site_id CHAR(36) NOT NULL,
  site_fingerprint VARCHAR(128) NOT NULL,
  contact_name VARCHAR(160) NULL,
  contact_email VARCHAR(255) NULL,
  notes TEXT NULL,
  admin_notes TEXT NULL,
  claim_token_hash CHAR(64) NULL,
  request_token_hash CHAR(64) NULL,
  claim_expires_at TIMESTAMP NULL DEFAULT NULL,
  claimed_at TIMESTAMP NULL DEFAULT NULL,
  approved_at TIMESTAMP NULL DEFAULT NULL,
  approved_by INT UNSIGNED NULL,
  created_peer_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ue_federation_join_status (status),
  KEY idx_ue_federation_join_site_id (site_id),
  KEY idx_ue_federation_join_token (claim_token_hash),
  KEY idx_ue_federation_join_request_token (request_token_hash),
  CONSTRAINT fk_ue_federation_join_peer FOREIGN KEY (created_peer_id) REFERENCES ue_federation_peers(id) ON DELETE SET NULL,
  CONSTRAINT fk_ue_federation_join_user FOREIGN KEY (approved_by) REFERENCES ue_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- External downloads
-- =====================================================================

CREATE TABLE ue_external_download_providers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_key VARCHAR(80) NOT NULL,
  provider_name VARCHAR(160) NOT NULL,
  provider_class VARCHAR(160) NOT NULL DEFAULT 'ManualProvider',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  config_json JSON NULL,
  max_file_size_mb INT UNSIGNED NOT NULL DEFAULT 1024,
  expiry_days INT UNSIGNED NOT NULL DEFAULT 7,
  priority INT NOT NULL DEFAULT 100,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_external_provider_key (provider_key),
  KEY idx_ue_external_provider_active (is_active, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_external_download_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id BIGINT UNSIGNED NOT NULL,
  provider_id INT UNSIGNED NOT NULL,
  status ENUM('queued','uploading','active','expired','delete_queued','deleted','failed','broken') NOT NULL DEFAULT 'queued',
  external_url TEXT NULL,
  remote_file_id VARCHAR(255) NULL,
  delete_token_or_url TEXT NULL,
  uploaded_at TIMESTAMP NULL DEFAULT NULL,
  expires_at TIMESTAMP NULL DEFAULT NULL,
  last_requested_at TIMESTAMP NULL DEFAULT NULL,
  requested_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_checked_at TIMESTAMP NULL DEFAULT NULL,
  error_message TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ue_external_links_file_status (file_id, status),
  KEY idx_ue_external_links_provider_status (provider_id, status),
  KEY idx_ue_external_links_expiry (expires_at),
  CONSTRAINT fk_ue_external_links_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_external_links_provider FOREIGN KEY (provider_id) REFERENCES ue_external_download_providers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_external_links_user FOREIGN KEY (created_by) REFERENCES ue_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_external_mirror_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id BIGINT UNSIGNED NOT NULL,
  provider_id INT UNSIGNED NULL,
  link_id BIGINT UNSIGNED NULL,
  status ENUM('queued','waiting_admin','uploading','active','failed','cancelled','expired') NOT NULL DEFAULT 'queued',
  requested_by_ip VARCHAR(64) NULL,
  requested_by_user_id INT UNSIGNED NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  started_at TIMESTAMP NULL DEFAULT NULL,
  finished_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ue_external_jobs_file_status (file_id, status),
  KEY idx_ue_external_jobs_provider_status (provider_id, status),
  KEY idx_ue_external_jobs_link (link_id),
  CONSTRAINT fk_ue_external_jobs_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_external_jobs_provider FOREIGN KEY (provider_id) REFERENCES ue_external_download_providers(id) ON DELETE SET NULL,
  CONSTRAINT fk_ue_external_jobs_link FOREIGN KEY (link_id) REFERENCES ue_external_download_links(id) ON DELETE SET NULL,
  CONSTRAINT fk_ue_external_jobs_user FOREIGN KEY (requested_by_user_id) REFERENCES ue_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Diagnostics and maintenance
-- =====================================================================

CREATE TABLE ue_app_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  level ENUM('DEBUG','INFO','WARN','ERROR') NOT NULL DEFAULT 'INFO',
  event VARCHAR(120) NOT NULL,
  message TEXT NULL,
  context_json JSON NULL,
  request_uri VARCHAR(1000) NULL,
  remote_addr VARCHAR(64) NULL,
  user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ue_app_logs_level (level),
  KEY idx_ue_app_logs_event (event),
  KEY idx_ue_app_logs_created (created_at),
  KEY idx_ue_app_logs_user (user_id),
  CONSTRAINT fk_ue_app_logs_user FOREIGN KEY (user_id) REFERENCES ue_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_background_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue_name VARCHAR(80) NOT NULL DEFAULT 'catalog',
  job_type VARCHAR(120) NOT NULL,
  payload_json MEDIUMTEXT NOT NULL,
  result_json MEDIUMTEXT NULL,
  progress_json MEDIUMTEXT NULL,
  progress_updated_at DATETIME NULL,
  priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  status ENUM('queued','running','completed','failed','dead_letter','cancelled') NOT NULL DEFAULT 'queued',
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  dedupe_key VARCHAR(191) NULL,
  worker_id VARCHAR(120) NULL,
  lease_token VARCHAR(64) NULL,
  leased_at DATETIME NULL,
  lease_expires_at DATETIME NULL,
  last_heartbeat_at DATETIME NULL,
  recovery_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  cancel_requested_at DATETIME NULL,
  cancel_requested_by INT UNSIGNED NULL,
  cancel_reason VARCHAR(1000) NULL,
  last_error TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  dead_lettered_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_background_jobs_active_dedupe (queue_name, dedupe_key),
  KEY idx_ue_background_jobs_claim (queue_name, status, available_at, priority, id),
  KEY idx_ue_background_jobs_lease (queue_name, status, lease_expires_at),
  KEY idx_ue_background_jobs_cancel (status, cancel_requested_at),
  KEY idx_ue_background_jobs_dead_letter (queue_name, status, dead_lettered_at),
  KEY idx_ue_background_jobs_heartbeat (queue_name, status, last_heartbeat_at),
  KEY idx_ue_background_jobs_created (created_at),
  CONSTRAINT fk_ue_background_jobs_user FOREIGN KEY (created_by) REFERENCES ue_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- consolidated schema assembly boundary 3
__UNREALDB_INSTALL_PART_3__
-- consolidated schema assembly boundary 4
__UNREALDB_INSTALL_PART_4__
-- consolidated schema assembly boundary 5
__UNREALDB_INSTALL_PART_5__
