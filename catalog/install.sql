-- consolidated schema assembly boundary 1
__UNREALDB_INSTALL_PART_1__
-- consolidated schema assembly boundary 2
__UNREALDB_INSTALL_PART_2__
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
-- Consolidated migration baseline through 202608030001
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

-- 202607180003: dependency resolution metadata and UE4 asset registry.
ALTER TABLE ue_dependencies
  ADD COLUMN resolution_source VARCHAR(64) NOT NULL DEFAULT 'unknown' AFTER status,
  ADD COLUMN resolution_confidence VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER resolution_source,
  ADD KEY idx_ue_deps_resolution_source (resolution_source),
  ADD KEY idx_ue_deps_resolution_confidence (resolution_confidence);

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
__UNREALDB_INSTALL_PART_4__
-- consolidated schema assembly boundary 5
__UNREALDB_INSTALL_PART_5__
