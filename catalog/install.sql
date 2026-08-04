-- consolidated schema assembly boundary 1
__UNREALDB_INSTALL_PART_1__
-- consolidated schema assembly boundary 2
__UNREALDB_INSTALL_PART_2__
-- consolidated schema assembly boundary 3
__UNREALDB_INSTALL_PART_3__
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

ALTER TABLE ue_imports
  ADD KEY idx_ue_imports_root_file (root_package, file_id);

ALTER TABLE ue_exports
  ADD KEY idx_ue_exports_file_local (file_id, local_path(191));

ALTER TABLE ue_dependencies
  ADD KEY idx_ue_deps_required_file (required_package, file_id),
  ADD KEY idx_ue_deps_missing_package_cursor (required_package, status, file_id, id),
  ADD KEY idx_ue_deps_missing_file_cursor (file_id, status, required_package, id);

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
__UNREALDB_INSTALL_PART_5__
