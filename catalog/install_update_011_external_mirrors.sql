INSERT IGNORE INTO ue_federation_settings(setting_name, setting_value) VALUES
('public_download_mode', 'local_direct'),
('external_mirror_auto_queue', '1'),
('external_mirror_expiry_days', '7'),
('external_mirror_require_admin_approval', '0'),
('external_mirror_max_file_size_mb', '1024');

CREATE TABLE IF NOT EXISTS ue_external_download_providers (
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

CREATE TABLE IF NOT EXISTS ue_external_download_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id INT UNSIGNED NOT NULL,
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

CREATE TABLE IF NOT EXISTS ue_external_mirror_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id INT UNSIGNED NOT NULL,
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

INSERT IGNORE INTO ue_external_download_providers(provider_key, provider_name, provider_class, is_active, config_json, max_file_size_mb, expiry_days, priority, notes) VALUES
('manual', 'Manual external link', 'ManualProvider', 1, JSON_OBJECT(), 1024, 7, 10, 'Admin manually pastes externally hosted links.');
