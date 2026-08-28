-- consolidated schema assembly boundary 1
-- UnrealDB consolidated catalog schema
-- Consolidated migration baseline: 202608090002
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

-- Legacy row-per-object metadata tables are intentionally omitted from fresh installs.
-- Current compact metadata and lookup projections are defined in the consolidated
-- migration section below.

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


-- =====================================================================
-- Consolidated migration baseline through 202608090002
-- Historical migration files up to this version are represented below.
-- =====================================================================

-- Migration metadata retained for future incremental migrations.
CREATE TABLE ue_schema_migrations (
  version VARCHAR(32) NOT NULL,
  migration VARCHAR(190) NOT NULL,
  description VARCHAR(255) NOT NULL,
  checksum CHAR(64) NOT NULL,
  batch INT UNSIGNED NOT NULL,
  execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version),
  KEY idx_ue_schema_migrations_batch (batch),
  KEY idx_ue_schema_migrations_applied (applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202607180001: persistent administrator remember-login tokens.
CREATE TABLE ue_remember_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  selector CHAR(24) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_remember_tokens_selector (selector),
  KEY idx_ue_remember_tokens_user (user_id),
  KEY idx_ue_remember_tokens_expires (expires_at),
  CONSTRAINT fk_ue_remember_tokens_user FOREIGN KEY (user_id) REFERENCES ue_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202607180002: logical aliases for shared physical file identities.
CREATE TABLE ue_file_package_aliases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id BIGINT UNSIGNED NOT NULL,
  game_id INT UNSIGNED NOT NULL,
  package_name VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  package_guid VARCHAR(80) NULL,
  md5 CHAR(32) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_file_alias_file_package (file_id, package_name),
  KEY idx_ue_file_alias_game_package (game_id, package_name),
  KEY idx_ue_file_alias_file (file_id),
  KEY idx_ue_file_alias_game_guid_md5 (game_id, package_guid, md5),
  KEY idx_ue_file_alias_game_original (game_id, original_name),
  CONSTRAINT fk_ue_file_alias_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_file_alias_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202607180003: UE4 asset registry metadata.
CREATE TABLE ue_asset_registry_assets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id BIGINT UNSIGNED NOT NULL,
  object_path VARCHAR(1000) NOT NULL,
  package_name VARCHAR(255) NOT NULL,
  package_path VARCHAR(1000) NOT NULL,
  asset_name VARCHAR(255) NOT NULL,
  asset_class VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_ar_asset_file_object (file_id, object_path(191)),
  KEY idx_ue_ar_asset_package_name (package_name),
  KEY idx_ue_ar_asset_object_path (object_path(191)),
  KEY idx_ue_ar_asset_asset_name (asset_name),
  CONSTRAINT fk_ue_ar_asset_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_asset_registry_tags (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id BIGINT UNSIGNED NOT NULL,
  tag_name VARCHAR(255) NOT NULL,
  tag_value TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_ue_ar_tags_asset (asset_id),
  KEY idx_ue_ar_tags_name (tag_name),
  CONSTRAINT fk_ue_ar_tags_asset FOREIGN KEY (asset_id) REFERENCES ue_asset_registry_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_asset_registry_dependencies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id BIGINT UNSIGNED NOT NULL,
  source_asset_id BIGINT UNSIGNED NULL,
  dependency_object_path VARCHAR(1000) NOT NULL,
  dependency_type VARCHAR(64) NOT NULL DEFAULT 'unknown',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ue_ar_deps_file (file_id),
  KEY idx_ue_ar_deps_asset (source_asset_id),
  KEY idx_ue_ar_deps_object (dependency_object_path(191)),
  CONSTRAINT fk_ue_ar_deps_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_ar_deps_asset FOREIGN KEY (source_asset_id) REFERENCES ue_asset_registry_assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202607180004: database-backed unverified staging.
ALTER TABLE ue_files
  MODIFY game_id INT UNSIGNED NULL,
  MODIFY scan_status ENUM('verified','unverified','duplicate','failed') NOT NULL DEFAULT 'verified',
  ADD COLUMN source_relative_path VARCHAR(1024) NULL DEFAULT NULL AFTER original_name,
  ADD COLUMN unverified_queue_key CHAR(64) NULL AFTER scan_status,
  ADD COLUMN unverified_queue_game_id INT UNSIGNED NULL AFTER unverified_queue_key,
  ADD COLUMN unverified_queue_name VARCHAR(255) NULL AFTER unverified_queue_game_id,
  ADD COLUMN unverified_reason TEXT NULL AFTER unverified_queue_name,
  ADD UNIQUE KEY uq_ue_files_unverified_queue_key (unverified_queue_key),
  ADD KEY idx_ue_files_scan_status (scan_status),
  ADD KEY idx_ue_files_unverified_queue (unverified_queue_game_id, unverified_queue_name);

-- 202607180006: persisted job resource classes and concurrency keys.
ALTER TABLE ue_background_jobs
  ADD COLUMN resource_class VARCHAR(80) NOT NULL DEFAULT 'default' AFTER job_type,
  ADD COLUMN resource_limit SMALLINT UNSIGNED NOT NULL DEFAULT 4 AFTER resource_class,
  ADD COLUMN concurrency_key VARCHAR(191) NULL AFTER resource_limit,
  ADD KEY idx_ue_background_jobs_resource (queue_name, status, resource_class),
  ADD KEY idx_ue_background_jobs_concurrency (queue_name, status, concurrency_key);

-- 202607180007: federation signing keys.
ALTER TABLE ue_federation_peers
  ADD COLUMN signature_algorithm VARCHAR(32) NOT NULL DEFAULT 'hmac-sha256' AFTER shared_secret_plain,
  ADD COLUMN signing_public_key VARCHAR(128) NULL AFTER signature_algorithm,
  ADD COLUMN signing_key_id VARCHAR(64) NULL AFTER signing_public_key,
  ADD COLUMN signing_rotated_at TIMESTAMP NULL DEFAULT NULL AFTER signing_key_id,
  ADD COLUMN signing_revoked_at TIMESTAMP NULL DEFAULT NULL AFTER signing_rotated_at,
  ADD KEY idx_ue_federation_peers_signing_key (signing_key_id, signing_revoked_at);

-- 202607180008: administrator MFA.
ALTER TABLE ue_users
  ADD COLUMN mfa_totp_secret VARCHAR(512) NULL AFTER password_hash,
  ADD COLUMN mfa_recovery_codes_json JSON NULL AFTER mfa_totp_secret,
  ADD COLUMN mfa_enabled_at TIMESTAMP NULL DEFAULT NULL AFTER mfa_recovery_codes_json,
  ADD COLUMN mfa_last_used_step BIGINT NULL AFTER mfa_enabled_at,
  ADD KEY idx_ue_users_mfa_enabled (mfa_enabled_at);

-- 202607210001: retained PAK archives and extracted entries.
CREATE TABLE ue_pak_archives (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id INT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  relative_path VARCHAR(500) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  md5 CHAR(32) NOT NULL,
  sha1 CHAR(40) NOT NULL,
  sha256 CHAR(64) NOT NULL,
  pak_version INT NULL,
  mount_point VARCHAR(1000) NULL,
  footer_layout VARCHAR(32) NULL,
  index_offset BIGINT NULL,
  index_size BIGINT NULL,
  index_hash CHAR(40) NULL,
  entry_count INT UNSIGNED NOT NULL DEFAULT 0,
  extracted_count INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('processing','ready','failed') NOT NULL DEFAULT 'processing',
  scan_notes MEDIUMTEXT NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_pak_archives_game_sha256 (game_id, sha256),
  KEY idx_ue_pak_archives_game_name (game_id, original_name),
  KEY idx_ue_pak_archives_game_status (game_id, status),
  KEY idx_ue_pak_archives_md5 (md5),
  CONSTRAINT fk_ue_pak_archives_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_pak_archives_user FOREIGN KEY (uploaded_by) REFERENCES ue_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_pak_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pak_id BIGINT UNSIGNED NOT NULL,
  entry_index INT UNSIGNED NOT NULL,
  entry_path VARCHAR(1000) NOT NULL,
  entry_name VARCHAR(255) NOT NULL,
  extension VARCHAR(32) NOT NULL DEFAULT '',
  data_offset BIGINT NULL,
  stored_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  uncompressed_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  compression_method INT UNSIGNED NOT NULL DEFAULT 0,
  compression_block_size INT UNSIGNED NOT NULL DEFAULT 0,
  entry_hash CHAR(40) NULL,
  is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
  was_extracted TINYINT(1) NOT NULL DEFAULT 0,
  import_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  file_id BIGINT UNSIGNED NULL,
  import_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_pak_entries_index (pak_id, entry_index),
  KEY idx_ue_pak_entries_path (pak_id, entry_path(191)),
  KEY idx_ue_pak_entries_file (file_id),
  KEY idx_ue_pak_entries_status (pak_id, import_status),
  CONSTRAINT fk_ue_pak_entries_pak FOREIGN KEY (pak_id) REFERENCES ue_pak_archives(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_pak_entries_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202607210003: reusable UE5 profile and default game target.
INSERT INTO ue_game_profiles
  (profile_name, game_id, engine_key, allowed_extensions_json, package_version_min, package_version_max,
   licensee_version_min, licensee_version_max, confidence_policy, notes, is_active)
SELECT
  'UE5 container package profile', NULL, 'UE5', JSON_ARRAY('uasset','umap'), NULL, NULL,
  NULL, NULL, 'loose',
  'UE5 container-focused profile. PAK archives are retained and extracted through PAK Import when readable; loose package parsing remains version/profile dependent.',
  1
WHERE NOT EXISTS (
  SELECT 1 FROM ue_game_profiles WHERE profile_name='UE5 container package profile'
);

-- consolidated schema assembly boundary 4
INSERT INTO ue_games(name, slug, description, profile_id)
SELECT
  'Unreal Engine 5', 'ue5',
  'UE5 PAK container catalog and limited loose .uasset/.umap package analysis',
  p.id
FROM ue_game_profiles p
WHERE p.profile_name='UE5 container package profile'
  AND NOT EXISTS (SELECT 1 FROM ue_games WHERE slug='ue5')
LIMIT 1;

UPDATE ue_games g
JOIN ue_game_profiles p ON p.profile_name='UE5 container package profile'
SET g.profile_id=p.id
WHERE g.slug='ue5' AND g.profile_id IS NULL;

-- 202607230001: parent-controlled base-game policy.
ALTER TABLE ue_federation_peer_files
  ADD COLUMN is_base_game TINYINT(1) NOT NULL DEFAULT 0 AFTER package_guid,
  ADD KEY idx_ue_federation_peer_files_base_game (peer_id, is_base_game);

INSERT IGNORE INTO ue_federation_settings(setting_name, setting_value)
VALUES ('ignore_base_game_files', '1');

-- 202607270001 and 202607270006-011: catalogue and cursor indexes.
ALTER TABLE ue_files
  ADD KEY idx_ue_files_game_status_package (game_id, scan_status, package_name, id),
  ADD KEY idx_ue_files_game_status_original (game_id, scan_status, original_name, id),
  ADD KEY idx_ue_files_game_package_cursor (game_id, package_name, original_name, id),
  ADD KEY idx_ue_files_game_original_cursor (game_id, original_name, package_name, id),
  ADD KEY idx_ue_files_game_version_cursor (game_id, package_version, package_name, original_name, id),
  ADD KEY idx_ue_files_game_size_cursor (game_id, file_size, package_name, original_name, id),
  ADD KEY idx_ue_files_game_compression_cursor (game_id, is_compressed, package_name, original_name, id),
  ADD KEY idx_ue_files_game_uploaded_cursor (game_id, uploaded_at, package_name, original_name, id);

ALTER TABLE ue_federation_peer_files
  ADD KEY idx_ue_peer_files_inventory_cursor
    (peer_id, is_base_game, remote_game_name(120), package_name(120), original_name(120), id),
  ADD KEY idx_ue_peer_files_conflict_cursor
    (peer_id, is_base_game, package_name(120), original_name(120), id);

ALTER TABLE ue_federation_requests
  ADD KEY idx_ue_federation_requests_history (direction, status, created_at, id),
  ADD KEY idx_ue_federation_requests_peer_history (peer_id, direction, status, created_at, id);

ALTER TABLE ue_federation_request_items
  ADD KEY idx_ue_federation_request_items_history (request_id, updated_at, id);

ALTER TABLE ue_federation_transfer_jobs
  ADD KEY idx_ue_federation_transfer_history (status, created_at, id),
  ADD KEY idx_ue_federation_transfer_peer_history (peer_id, direction, created_at, id);

ALTER TABLE ue_federation_transfer_logs
  ADD KEY idx_ue_federation_logs_history (created_at, id),
  ADD KEY idx_ue_federation_logs_level_history (level, created_at, id),
  ADD KEY idx_ue_federation_logs_peer_history (peer_id, created_at, id);

ALTER TABLE ue_background_jobs
  ADD KEY idx_ue_background_jobs_queue_id (queue_name, id),
  ADD KEY idx_ue_background_jobs_queue_status_id (queue_name, status, id),
  ADD KEY idx_ue_background_jobs_updated_id (updated_at, id);

-- 202607270002: materialized primary and alias package providers.
CREATE TABLE ue_package_providers (
  source_kind ENUM('primary','alias') NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  game_id INT UNSIGNED NOT NULL,
  package_name VARCHAR(255) NOT NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  provider_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (source_kind, source_id),
  KEY idx_ue_package_providers_lookup (game_id, package_name, file_id),
  KEY idx_ue_package_providers_file (file_id),
  CONSTRAINT fk_ue_package_providers_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202607270004 and 202607270007: compact dependency package summaries.
CREATE TABLE ue_dependency_package_summaries (
  game_id INT UNSIGNED NOT NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  required_package VARCHAR(255) NOT NULL,
  example_required_object_path VARCHAR(1000) NULL,
  dependency_count INT UNSIGNED NOT NULL DEFAULT 0,
  resolved_count INT UNSIGNED NOT NULL DEFAULT 0,
  missing_count INT UNSIGNED NOT NULL DEFAULT 0,
  package_only_count INT UNSIGNED NOT NULL DEFAULT 0,
  common_count INT UNSIGNED NOT NULL DEFAULT 0,
  summary_status VARCHAR(16) NOT NULL DEFAULT 'mixed',
  provider_file_id BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (file_id, required_package),
  KEY idx_ue_dep_summary_game_status (game_id, summary_status, required_package, file_id),
  KEY idx_ue_dep_summary_package_game (required_package, game_id, summary_status, file_id),
  KEY idx_ue_dep_summary_provider (provider_file_id, file_id),
  KEY idx_ue_dep_summary_game_package_missing (game_id, required_package(191), missing_count, file_id),
  CONSTRAINT fk_ue_dep_summary_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_dep_summary_provider FOREIGN KEY (provider_file_id) REFERENCES ue_files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202607270005: official base-game files and cached per-game counters.
CREATE TABLE ue_base_game_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id INT UNSIGNED NOT NULL,
  package_guid VARCHAR(80) NOT NULL,
  package_name VARCHAR(255) NULL,
  original_name VARCHAR(255) NULL,
  source_file_id BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_base_game_files_game_guid (game_id, package_guid),
  KEY idx_ue_base_game_files_game (game_id),
  KEY idx_ue_base_game_files_guid (package_guid),
  KEY idx_ue_base_game_files_source_file (source_file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_game_catalog_stats (
  game_id INT UNSIGNED NOT NULL,
  file_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  verified_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  failed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  unverified_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  verified_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  dependency_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  missing_dependency_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  resolved_dependency_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  package_only_dependency_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  common_dependency_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  missing_package_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  missing_base_game_dependency_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (game_id),
  KEY idx_ue_game_catalog_stats_updated (updated_at),
  CONSTRAINT fk_ue_game_catalog_stats_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ue_game_catalog_stats(game_id)
SELECT id FROM ue_games;

-- 202607270008: cached source-file fingerprints.
CREATE TABLE ue_source_file_fingerprints (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id INT UNSIGNED NOT NULL,
  source_relative_path VARCHAR(1000) NOT NULL,
  path_hash CHAR(64) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  modified_at BIGINT NOT NULL DEFAULT 0,
  quick_fingerprint CHAR(64) NOT NULL,
  work_name VARCHAR(255) NOT NULL,
  is_redirect TINYINT(1) NOT NULL DEFAULT 0,
  content_md5 CHAR(32) NULL,
  content_sha1 CHAR(40) NULL,
  package_guid VARCHAR(80) NULL,
  matched_file_id BIGINT UNSIGNED NULL,
  match_method VARCHAR(16) NULL,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_source_fingerprint_path (source_id, path_hash),
  KEY idx_ue_source_fingerprint_match (matched_file_id),
  KEY idx_ue_source_fingerprint_seen (source_id, last_seen_at),
  CONSTRAINT fk_ue_source_fingerprint_source FOREIGN KEY (source_id) REFERENCES ue_sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_source_fingerprint_file FOREIGN KEY (matched_file_id) REFERENCES ue_files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202607270012: exact-count telemetry.
CREATE TABLE ue_exact_count_telemetry (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  metric_key VARCHAR(120) NOT NULL,
  context_hash CHAR(64) NOT NULL,
  context_json TEXT NOT NULL,
  sample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  max_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  slow_sample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_result_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_exact_count_metric_context (metric_key, context_hash),
  KEY idx_ue_exact_count_last_seen (last_seen_at),
  KEY idx_ue_exact_count_max_duration (max_duration_us),
  KEY idx_ue_exact_count_metric (metric_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202607270013: bounded EXPLAIN snapshots.
CREATE TABLE ue_exact_count_query_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  metric_key VARCHAR(120) NOT NULL,
  context_hash CHAR(64) NOT NULL,
  context_json TEXT NOT NULL,
  query_hash CHAR(64) NOT NULL,
  query_sql MEDIUMTEXT NOT NULL,
  plan_json MEDIUMTEXT NOT NULL,
  plan_step_count INT UNSIGNED NOT NULL DEFAULT 0,
  estimated_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
  full_scan_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
  access_types VARCHAR(255) NULL,
  possible_keys TEXT NULL,
  selected_keys TEXT NULL,
  extra_flags TEXT NULL,
  assessment VARCHAR(32) NOT NULL DEFAULT 'normal',
  recommendation TEXT NULL,
  error_message TEXT NULL,
  captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_exact_plan_metric_context (metric_key, context_hash),
  KEY idx_ue_exact_plan_assessment (assessment, estimated_rows),
  KEY idx_ue_exact_plan_captured (captured_at),
  KEY idx_ue_exact_plan_metric (metric_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202607270014: count cache, job search and request aggregates.
CREATE TABLE ue_exact_count_cache (
  cache_key CHAR(64) NOT NULL,
  query_hash CHAR(64) NOT NULL,
  result_count BIGINT NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  generated_at DATETIME NOT NULL,
  hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_hit_at DATETIME NULL,
  PRIMARY KEY (cache_key),
  KEY idx_ue_exact_count_cache_query (query_hash),
  KEY idx_ue_exact_count_cache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_background_job_search (
  job_id BIGINT UNSIGNED NOT NULL,
  queue_name VARCHAR(80) NOT NULL,
  job_type VARCHAR(120) NOT NULL,
  source_status VARCHAR(32) NOT NULL,
  search_text MEDIUMTEXT NOT NULL,
  source_updated_at DATETIME NOT NULL,
  PRIMARY KEY (job_id),
  KEY idx_ue_job_search_queue_job (queue_name, job_id),
  KEY idx_ue_job_search_status_job (source_status, job_id),
  KEY idx_ue_job_search_updated (source_updated_at),
  FULLTEXT KEY ft_ue_job_search_text (search_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_request_performance (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  route_key VARCHAR(190) NOT NULL,
  method VARCHAR(10) NOT NULL,
  sample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_sql_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  max_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  max_sql_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
-- consolidated schema assembly boundary 5
  last_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_sql_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  slow_sample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_query_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_request_performance_route (route_key, method),
  KEY idx_ue_request_performance_slow (max_duration_us, max_sql_us),
  KEY idx_ue_request_performance_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202607280001: sampled request CPU and memory tracing.
CREATE TABLE ue_request_resource_performance (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  route_key VARCHAR(190) NOT NULL,
  method VARCHAR(10) NOT NULL,
  audience VARCHAR(16) NOT NULL,
  sample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_sql_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_cpu_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  max_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  max_sql_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  max_cpu_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_peak_memory_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  max_peak_memory_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_sql_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_cpu_us BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_peak_memory_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_memory_delta_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_query_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  slow_sample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_slowest_query_hash CHAR(64) NOT NULL DEFAULT '',
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_request_resource_route (route_key, method, audience),
  KEY idx_ue_request_resource_cpu (total_cpu_us, max_cpu_us),
  KEY idx_ue_request_resource_memory (max_peak_memory_bytes),
  KEY idx_ue_request_resource_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202607310001: persistent Upload Bucket issues.
CREATE TABLE ue_upload_bucket_issues (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  issue_key CHAR(64) NOT NULL,
  source_kind VARCHAR(32) NOT NULL DEFAULT 'upload_bucket_v2',
  upload_session_id VARCHAR(64) NOT NULL DEFAULT '',
  relative_path TEXT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  file_size_text VARCHAR(32) NOT NULL DEFAULT '',
  stage VARCHAR(64) NOT NULL,
  error_message TEXT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'open',
  occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  resolved_by BIGINT UNSIGNED NULL,
  resolution_note VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_upload_bucket_issues_key (issue_key),
  KEY idx_ue_upload_bucket_issues_status_seen (status, last_seen_at, id),
  KEY idx_ue_upload_bucket_issues_session (upload_session_id, id),
  KEY idx_ue_upload_bucket_issues_stage (stage, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202607310002: central persistent error log.
CREATE TABLE ue_system_errors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  error_key CHAR(64) NOT NULL,
  source_kind VARCHAR(32) NOT NULL,
  severity VARCHAR(16) NOT NULL DEFAULT 'error',
  error_type VARCHAR(120) NOT NULL,
  message TEXT NOT NULL,
  route VARCHAR(500) NOT NULL DEFAULT '',
  request_method VARCHAR(12) NOT NULL DEFAULT '',
  http_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  source_file VARCHAR(1000) NOT NULL DEFAULT '',
  source_line INT UNSIGNED NOT NULL DEFAULT 0,
  trace_text MEDIUMTEXT NULL,
  context_json MEDIUMTEXT NULL,
  request_id VARCHAR(64) NOT NULL DEFAULT '',
  user_id BIGINT UNSIGNED NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'open',
  occurrence_count BIGINT UNSIGNED NOT NULL DEFAULT 1,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  resolved_by BIGINT UNSIGNED NULL,
  resolution_note VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_system_errors_key (error_key),
  KEY idx_ue_system_errors_status_seen (status, last_seen_at, id),
  KEY idx_ue_system_errors_source_seen (source_kind, last_seen_at, id),
  KEY idx_ue_system_errors_severity_seen (severity, last_seen_at, id),
  KEY idx_ue_system_errors_request (request_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202607310004-005: public access, feedback, SMTP and abuse controls.
INSERT IGNORE INTO ue_federation_settings(setting_name, setting_value) VALUES
('site_development_mode', '1'),
('site_development_title', 'UnrealDB is under active development'),
('site_development_message', 'Not every function is available yet. The site is public so visitors can explore the verified-file catalog and see what will be possible soon.'),
('feedback_enabled', '0'),
('feedback_recipient', 'info@unrealdb.com'),
('feedback_max_requests', '5'),
('feedback_window_seconds', '3600'),
('smtp_enabled', '0'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_encryption', 'starttls'),
('smtp_username', ''),
('smtp_password', ''),
('smtp_from_email', 'info@unrealdb.com'),
('smtp_from_name', 'UnrealDB'),
('smtp_timeout_seconds', '20'),
('public_download_max_files', '10'),
('public_download_window_seconds', '3600'),
('public_package_max_builds', '10'),
('public_package_window_seconds', '3600'),
('public_download_speed_kbps', '0'),
('public_block_crawlers', '1'),
('public_burst_max_requests', '30'),
('public_burst_window_seconds', '10'),
('public_burst_block_seconds', '600');

-- 202607310006: exact background-job claim index.
ALTER TABLE ue_background_jobs
  DROP INDEX idx_ue_background_jobs_claim,
  ADD INDEX idx_ue_background_jobs_claim
    (queue_name, status, cancel_requested_at, priority, available_at, id);

-- 202607310007: generated-package and download audit.
CREATE TABLE ue_generated_package_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id BIGINT UNSIGNED NOT NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  request_ip VARBINARY(16) NULL,
  user_agent VARCHAR(500) NOT NULL DEFAULT '',
  package_format VARCHAR(32) NOT NULL,
  package_name VARCHAR(255) NOT NULL,
  package_version VARCHAR(80) NOT NULL,
  include_dependencies TINYINT(1) NOT NULL DEFAULT 1,
  allow_incomplete TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'queued',
  artifact_name VARCHAR(255) NULL,
  artifact_size BIGINT UNSIGNED NULL,
  artifact_sha256 BINARY(32) NULL,
  error_message VARCHAR(1000) NULL,
  queued_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  started_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_generated_package_audit_job (job_id),
  KEY idx_ue_generated_package_audit_created (queued_at, id),
  KEY idx_ue_generated_package_audit_game (game_id, queued_at, id),
  KEY idx_ue_generated_package_audit_file (file_id, queued_at, id),
  KEY idx_ue_generated_package_audit_ip (request_ip, queued_at, id),
  KEY idx_ue_generated_package_audit_status (status, queued_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ue_download_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  download_type VARCHAR(32) NOT NULL,
  file_id BIGINT UNSIGNED NULL,
  game_id BIGINT UNSIGNED NULL,
  job_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  ip_address VARBINARY(16) NULL,
  user_agent VARCHAR(500) NOT NULL DEFAULT '',
  download_name VARCHAR(255) NOT NULL DEFAULT '',
  package_format VARCHAR(32) NULL,
  artifact_size BIGINT UNSIGNED NULL,
  range_start BIGINT UNSIGNED NULL,
  range_end BIGINT UNSIGNED NULL,
  bytes_requested BIGINT UNSIGNED NULL,
  bytes_sent BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'started',
  http_status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  error_message VARCHAR(1000) NULL,
  started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  completed_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  KEY idx_ue_download_audit_created (started_at, id),
  KEY idx_ue_download_audit_type (download_type, started_at, id),
  KEY idx_ue_download_audit_ip (ip_address, started_at, id),
  KEY idx_ue_download_audit_file (file_id, started_at, id),
  KEY idx_ue_download_audit_job (job_id, started_at, id),
  KEY idx_ue_download_audit_status (status, started_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202608020001-004 and 202608030001: compressed per-file metadata and compact lookups.
CREATE TABLE ue_file_metadata (
  file_id BIGINT UNSIGNED NOT NULL,
  format_version SMALLINT UNSIGNED NOT NULL,
  codec TINYINT UNSIGNED NOT NULL,
  compressed_size BIGINT UNSIGNED NOT NULL,
  uncompressed_size BIGINT UNSIGNED NOT NULL,
  payload_sha256 BINARY(32) NOT NULL,
  name_count INT UNSIGNED NOT NULL,
  import_count INT UNSIGNED NOT NULL,
  export_count INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (file_id),
  CONSTRAINT fk_ue_file_metadata_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ue_terms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  value_hash BINARY(16) NOT NULL,
  value_length SMALLINT UNSIGNED NOT NULL,
  value_prefix MEDIUMBLOB NOT NULL,
  is_overflow TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_terms_hash_length (value_hash, value_length),
  KEY idx_ue_terms_value_prefix (value_prefix(100))
) ENGINE=InnoDB;

CREATE TABLE ue_export_lookup (
  file_id BIGINT UNSIGNED NOT NULL,
  export_index INT UNSIGNED NOT NULL,
  object_term_id INT UNSIGNED NOT NULL,
  class_term_id INT UNSIGNED NULL,
  path_hash BINARY(16) NOT NULL,
  local_path_term_id INT UNSIGNED NULL,
  PRIMARY KEY (file_id, export_index),
  KEY idx_ue_export_lookup_object (object_term_id, file_id),
  KEY idx_ue_export_lookup_path (path_hash, file_id),
  KEY idx_ue_export_lookup_local_path (local_path_term_id, file_id, export_index)
) ENGINE=InnoDB;

CREATE TABLE ue_dependency_links (
  file_id BIGINT UNSIGNED NOT NULL,
  import_index INT UNSIGNED NOT NULL,
  required_package_term_id INT UNSIGNED NOT NULL,
  required_path_hash BINARY(16) NOT NULL,
  required_object_term_id INT UNSIGNED NULL,
  import_class_package_term_id INT UNSIGNED NULL,
  import_class_name_term_id INT UNSIGNED NULL,
  import_object_term_id INT UNSIGNED NULL,
  resolved_file_id BIGINT UNSIGNED NULL,
  resolved_export_index INT UNSIGNED NULL,
  status TINYINT UNSIGNED NOT NULL,
  resolution_source TINYINT UNSIGNED NOT NULL,
  resolution_source_term_id INT UNSIGNED NULL,
  resolution_confidence TINYINT UNSIGNED NOT NULL,
  resolution_confidence_term_id INT UNSIGNED NULL,
  PRIMARY KEY (file_id, import_index),
  KEY idx_ue_dependency_required (required_package_term_id, status),
  KEY idx_ue_dependency_resolved (resolved_file_id, resolved_export_index),
  KEY idx_ue_dependency_source_term (resolution_source_term_id, file_id),
  KEY idx_ue_dependency_confidence_term (resolution_confidence_term_id, file_id),
  KEY idx_ue_dependency_file_status (file_id, status, required_package_term_id, import_index),
  KEY idx_ue_dependency_object_term (required_object_term_id, status, file_id),
  KEY idx_ue_dependency_import_object (import_object_term_id, file_id, import_index)
) ENGINE=InnoDB;

-- 202608060001: administrator-controlled background-job resource limits.
CREATE TABLE ue_job_resource_limits (
  resource_class VARCHAR(80) NOT NULL,
  limit_value SMALLINT UNSIGNED NOT NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (resource_class),
  KEY idx_ue_job_resource_limits_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ue_job_resource_limits (resource_class,limit_value) VALUES
('dependency-heavy',1),
('search-heavy',1),
('import-heavy',8),
('archive-import-heavy',1),
('bucket-processing',8),
('storage-heavy',1),
('package-heavy',1),
('housekeeping',2),
('default',4);

-- 202608080001: indexed generated background-job display status.
ALTER TABLE ue_background_jobs ADD COLUMN display_status VARCHAR(120)
GENERATED ALWAYS AS (
  CASE
    WHEN LOWER(status)<>"completed" THEN LOWER(status)
    WHEN LOWER(TRIM(COALESCE(IF(JSON_VALID(result_json),JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.status")),NULL),""))) IN ("","completed") THEN "completed"
    WHEN LOWER(TRIM(COALESCE(IF(JSON_VALID(result_json),JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.status")),NULL),"")))="verified" THEN "imported"
    ELSE LOWER(TRIM(COALESCE(IF(JSON_VALID(result_json),JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.status")),NULL),"")))
  END
) STORED AFTER status;

CREATE INDEX idx_ue_background_jobs_queue_display_id
  ON ue_background_jobs(queue_name,display_status,id);
CREATE INDEX idx_ue_background_jobs_display_id
  ON ue_background_jobs(display_status,id);

-- 202608090001: compressed staging metadata for unverified packages.
CREATE TABLE ue_unverified_metadata (
  file_id BIGINT UNSIGNED NOT NULL,
  format_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
  codec VARCHAR(32) NOT NULL DEFAULT "gzip-json",
  name_count INT UNSIGNED NOT NULL DEFAULT 0,
  import_count INT UNSIGNED NOT NULL DEFAULT 0,
  export_count INT UNSIGNED NOT NULL DEFAULT 0,
  uncompressed_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  compressed_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  payload LONGBLOB NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (file_id),
  CONSTRAINT fk_ue_unverified_metadata_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202608130001: administrator-configurable program upload limits.
CREATE TABLE ue_program_settings (
  setting_key VARCHAR(80) NOT NULL,
  setting_value VARCHAR(255) NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202608280001: public contribution quarantine and reservation ledger.
CREATE TABLE ue_public_uploads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  upload_token CHAR(64) NOT NULL,
  client_key CHAR(64) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  relative_path VARCHAR(1000) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  client_md5 CHAR(32) NULL,
  client_sha1 CHAR(40) NULL,
  client_guid VARCHAR(80) NULL,
  active_identity_key CHAR(64) NULL,
  server_md5 CHAR(32) NULL,
  server_sha1 CHAR(40) NULL,
  server_guid VARCHAR(80) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'reserved',
  submitter_ip VARBINARY(16) NULL,
  user_agent VARCHAR(500) NOT NULL DEFAULT '',
  received_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  next_chunk_index INT UNSIGNED NOT NULL DEFAULT 0,
  quarantine_relative_path VARCHAR(1000) NULL,
  background_job_id BIGINT UNSIGNED NULL,
  unverified_file_id BIGINT UNSIGNED NULL,
  result_message VARCHAR(1000) NULL,
  reservation_expires_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  completed_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_public_uploads_token (upload_token),
  UNIQUE KEY uq_ue_public_uploads_active_identity (active_identity_key),
  KEY idx_ue_public_uploads_identity (client_md5,client_sha1,file_size),
  KEY idx_ue_public_uploads_guid (client_guid),
  KEY idx_ue_public_uploads_ip_time (submitter_ip,created_at,id),
  KEY idx_ue_public_uploads_status_expiry (status,reservation_expires_at,id),
  KEY idx_ue_public_uploads_job (background_job_id),
  KEY idx_ue_public_uploads_unverified (unverified_file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;