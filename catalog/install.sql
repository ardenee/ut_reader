-- consolidated schema assembly boundary 1
__UNREALDB_INSTALL_PART_1__
-- consolidated schema assembly boundary 2
__UNREALDB_INSTALL_PART_2__
-- consolidated schema assembly boundary 3
__UNREALDB_INSTALL_PART_3__
-- consolidated schema assembly boundary 4
__UNREALDB_INSTALL_PART_4__
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

SET FOREIGN_KEY_CHECKS = 1;
