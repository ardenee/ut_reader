-- UnrealDB catalog schema
-- Canonical baseline for a new, empty MySQL 8+ or MariaDB database.
-- Do not import this over a populated catalog. Test a dedicated upgrade path first.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- Core catalog
-- =====================================================================

CREATE TABLE ue_games (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  description TEXT NULL,
  profile_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_games_slug (slug),
  KEY idx_ue_games_profile (profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_game_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  profile_name VARCHAR(160) NOT NULL,
  game_id INT UNSIGNED NULL,
  engine_key VARCHAR(32) NOT NULL,
  allowed_extensions_json JSON NOT NULL,
  compatibility_rules_json JSON NULL,
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
  UNIQUE KEY uq_ue_game_profiles_name (profile_name),
  KEY idx_ue_game_profiles_engine (engine_key),
  KEY idx_ue_game_profiles_legacy_game (game_id),
  CONSTRAINT fk_ue_game_profiles_legacy_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ue_games
  ADD CONSTRAINT fk_ue_games_profile FOREIGN KEY (profile_id) REFERENCES ue_game_profiles(id) ON DELETE SET NULL;

CREATE TABLE ue_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id INT UNSIGNED NOT NULL,
  package_name VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  relative_path VARCHAR(500) NOT NULL,
  extension VARCHAR(20) NOT NULL,
  detected_engine_key VARCHAR(32) NULL,
  detected_package_version INT NULL,
  detected_licensee_version INT NULL,
  detection_confidence ENUM('high','medium','low','mismatch','unknown') NOT NULL DEFAULT 'unknown',
  compatibility_status VARCHAR(32) NOT NULL DEFAULT 'native',
  compatibility_label VARCHAR(180) NULL,
  detection_notes TEXT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  md5 CHAR(32) NOT NULL,
  sha1 CHAR(40) NOT NULL,
  package_guid VARCHAR(80) NULL,
  is_compressed TINYINT(1) NOT NULL DEFAULT 0,
  compression_flags INT UNSIGNED NOT NULL DEFAULT 0,
  package_version INT NULL,
  licensee_version INT NULL,
  name_count INT UNSIGNED NOT NULL DEFAULT 0,
  import_count INT UNSIGNED NOT NULL DEFAULT 0,
  export_count INT UNSIGNED NOT NULL DEFAULT 0,
  scan_status ENUM('verified','duplicate','failed') NOT NULL DEFAULT 'verified',
  scan_notes MEDIUMTEXT NULL,
  uploaded_by INT UNSIGNED NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_files_game_md5 (game_id, md5),
  KEY idx_ue_files_game_package (game_id, package_name),
  KEY idx_ue_files_guid (package_guid),
  KEY idx_ue_files_game_guid (game_id, package_guid),
  KEY idx_ue_files_compressed (is_compressed),
  KEY idx_ue_files_game_package_uploaded (game_id, package_name, uploaded_at, id),
  KEY idx_ue_files_md5_global (md5),
  KEY idx_ue_files_sha1_global (sha1),
  CONSTRAINT fk_ue_files_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_files_user FOREIGN KEY (uploaded_by) REFERENCES ue_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_names (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id BIGINT UNSIGNED NOT NULL,
  name_index INT NOT NULL,
  name_text VARCHAR(500) NOT NULL,
  flags BIGINT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_names_file_index (file_id, name_index),
  KEY idx_ue_names_text (name_text(191)),
  CONSTRAINT fk_ue_names_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_imports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id BIGINT UNSIGNED NOT NULL,
  import_index INT NOT NULL,
  class_package VARCHAR(255) NULL,
  class_name VARCHAR(255) NULL,
  object_name VARCHAR(255) NOT NULL,
  outer_index INT NOT NULL DEFAULT 0,
  full_path VARCHAR(1000) NOT NULL,
  root_package VARCHAR(255) NOT NULL,
  relative_object_path VARCHAR(1000) NOT NULL,
  is_common TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_imports_file_index (file_id, import_index),
  KEY idx_ue_imports_root (root_package),
  KEY idx_ue_imports_full_path (full_path(191)),
  CONSTRAINT fk_ue_imports_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_exports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id BIGINT UNSIGNED NOT NULL,
  export_index INT NOT NULL,
  class_name VARCHAR(255) NULL,
  object_name VARCHAR(255) NOT NULL,
  outer_index INT NOT NULL DEFAULT 0,
  local_path VARCHAR(1000) NOT NULL,
  full_path VARCHAR(1000) NOT NULL,
  object_flags BIGINT NULL,
  serial_size BIGINT NULL,
  serial_offset BIGINT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_exports_file_index (file_id, export_index),
  KEY idx_ue_exports_full_path (full_path(191)),
  KEY idx_ue_exports_full_path_file (full_path(191), file_id),
  KEY idx_ue_exports_object (object_name),
  CONSTRAINT fk_ue_exports_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_dependencies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id BIGINT UNSIGNED NOT NULL,
  import_id BIGINT UNSIGNED NOT NULL,
  required_package VARCHAR(255) NOT NULL,
  required_object_path VARCHAR(1000) NOT NULL,
  resolved_file_id BIGINT UNSIGNED NULL,
  resolved_export_id BIGINT UNSIGNED NULL,
  status ENUM('resolved','missing','package_only','common') NOT NULL DEFAULT 'missing',
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_deps_import (import_id),
  KEY idx_ue_deps_file (file_id),
  KEY idx_ue_deps_status (status),
  KEY idx_ue_deps_file_status (file_id, status),
  KEY idx_ue_deps_required (required_package, required_object_path(191)),
  CONSTRAINT fk_ue_deps_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_deps_import FOREIGN KEY (import_id) REFERENCES ue_imports(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_deps_resolved_file FOREIGN KEY (resolved_file_id) REFERENCES ue_files(id) ON DELETE SET NULL,
  CONSTRAINT fk_ue_deps_resolved_export FOREIGN KEY (resolved_export_id) REFERENCES ue_exports(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_sources (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  source_type ENUM('local_path','http_mirror','redirect_server') NOT NULL DEFAULT 'local_path',
  base_path VARCHAR(1000) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ue_sources_game (game_id),
  KEY idx_ue_sources_type (source_type),
  CONSTRAINT fk_ue_sources_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_file_locations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id BIGINT UNSIGNED NOT NULL,
  source_id INT UNSIGNED NOT NULL,
  source_relative_path VARCHAR(1000) NOT NULL,
  exists_in_source TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_file_locations (file_id, source_id, source_relative_path(191)),
  KEY idx_ue_file_locations_source (source_id),
  CONSTRAINT fk_ue_file_locations_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_file_locations_source FOREIGN KEY (source_id) REFERENCES ue_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Federation
-- =====================================================================

CREATE TABLE ue_federation_settings (
  setting_name VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_federation_peers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  peer_role ENUM('parent','child') NOT NULL,
  site_name VARCHAR(160) NOT NULL,
  site_url VARCHAR(1000) NOT NULL,
  peer_site_id CHAR(36) NOT NULL,
  peer_fingerprint VARCHAR(128) NOT NULL,
  shared_secret_hash VARCHAR(255) NULL,
  shared_secret_plain VARCHAR(128) NULL,
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

CREATE TABLE ue_federation_nonces (
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

CREATE TABLE ue_federation_peer_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  peer_id INT UNSIGNED NOT NULL,
  game_id INT UNSIGNED NULL,
  remote_game_name VARCHAR(160) NULL,
  remote_engine_key VARCHAR(32) NULL,
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
  priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  status ENUM('queued','running','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  dedupe_key VARCHAR(191) NULL,
  worker_id VARCHAR(120) NULL,
  lease_token VARCHAR(64) NULL,
  leased_at DATETIME NULL,
  lease_expires_at DATETIME NULL,
  last_error TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_background_jobs_active_dedupe (queue_name, dedupe_key),
  KEY idx_ue_background_jobs_claim (queue_name, status, available_at, priority, id),
  KEY idx_ue_background_jobs_lease (queue_name, status, lease_expires_at),
  KEY idx_ue_background_jobs_created (created_at),
  CONSTRAINT fk_ue_background_jobs_user FOREIGN KEY (created_by) REFERENCES ue_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Seed data
-- =====================================================================

INSERT INTO ue_games(name, slug, description) VALUES
('Unreal / Unreal Tournament', 'ut99', 'UE1-era packages such as .u, .unr, .utx, .umx and .uax'),
('Unreal Tournament 2003/2004', 'ut2004', 'UE2/UE2.5 package catalog'),
('Unreal Tournament 3', 'ut3', 'UE3 packages such as .ut3 and .upk'),
('Unreal Engine 4', 'ue4', 'UE4 .uasset and .umap packages');

INSERT INTO ue_game_profiles(profile_name, engine_key, allowed_extensions_json, package_version_min, package_version_max, confidence_policy, notes) VALUES
('UE1 standard package profile', 'UE1', JSON_ARRAY('u','unr','utx','umx','uax'), 60, 69, 'normal', 'UE1 era package profile. Exact ranges can be refined from known-good samples.'),
('UE2 / UE2.5 standard package profile', 'UE2', JSON_ARRAY('u','un2','ut2','utx','usx','ukx','uax','umx'), 100, 130, 'normal', 'UE2/UE2.5 profile. The header identifies family but does not always prove the exact game.'),
('UE3 standard package profile', 'UE3', JSON_ARRAY('ut3','upk','u'), 512, 512, 'loose', 'UE3/UT3 package profile. Compressed packages may need LZO support.'),
('UE4 standard package profile', 'UE4', JSON_ARRAY('uasset','umap'), NULL, NULL, 'loose', 'UE4 package profile. Versioned and unversioned packages require profile-aware parsing.');

UPDATE ue_games g JOIN ue_game_profiles p ON p.profile_name='UE1 standard package profile' SET g.profile_id=p.id WHERE g.slug='ut99';
UPDATE ue_games g JOIN ue_game_profiles p ON p.profile_name='UE2 / UE2.5 standard package profile' SET g.profile_id=p.id WHERE g.slug='ut2004';
UPDATE ue_games g JOIN ue_game_profiles p ON p.profile_name='UE3 standard package profile' SET g.profile_id=p.id WHERE g.slug='ut3';
UPDATE ue_games g JOIN ue_game_profiles p ON p.profile_name='UE4 standard package profile' SET g.profile_id=p.id WHERE g.slug='ue4';

UPDATE ue_game_profiles
SET compatibility_rules_json = JSON_ARRAY(JSON_OBJECT(
  'detected_engine', 'UE1',
  'reader_engine', 'UE1',
  'extensions', JSON_ARRAY('utx'),
  'package_version_min', 40,
  'package_version_max', 99,
  'label', 'Legacy UE1 texture package'
))
WHERE profile_name='UE2 / UE2.5 standard package profile';

INSERT INTO ue_federation_settings(setting_name, setting_value) VALUES
('site_role', 'standalone'), ('site_name', ''), ('site_url', ''), ('site_id', ''), ('site_fingerprint', ''),
('parent_enabled', '0'), ('child_enabled', '0'), ('allow_parent_pull_from_child', '1'), ('allow_child_request_from_parent', '1'),
('max_download_kbps', '0'), ('max_upload_kbps', '0'), ('delay_between_downloads_seconds', '5'), ('delay_between_uploads_seconds', '5'),
('max_files_per_transfer_run', '1'), ('max_transfer_file_size_mb', '1024'), ('auto_import_downloads', '1'),
('require_https_for_remote_sites', '1'), ('api_nonce_ttl_seconds', '300'), ('transfer_token_ttl_seconds', '600'),
('log_retention_days', '90'), ('join_requests_enabled', '1'), ('join_claim_token_ttl_seconds', '86400'),
('main_parent_url', 'https://utreader/catalog'), ('main_parent_join_request_id', ''), ('main_parent_join_request_token', ''),
('main_parent_join_status', 'none'), ('public_download_mode', 'local_direct'), ('external_mirror_auto_queue', '1'),
('external_mirror_expiry_days', '7'), ('external_mirror_require_admin_approval', '0'), ('external_mirror_max_file_size_mb', '1024');

INSERT INTO ue_external_download_providers(provider_key, provider_name, provider_class, is_active, config_json, max_file_size_mb, expiry_days, priority, notes)
VALUES ('manual', 'Manual external link', 'ManualProvider', 1, JSON_OBJECT(), 1024, 7, 10, 'Admin manually pastes externally hosted links.');

SET FOREIGN_KEY_CHECKS = 1;
