-- Durable background jobs for worker processes and future asynchronous work.
-- Uses MEDIUMTEXT JSON payloads for compatibility with MySQL and MariaDB hosts
-- that may not expose a native JSON column type.

CREATE TABLE IF NOT EXISTS ue_background_jobs (
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
  CONSTRAINT fk_ue_background_jobs_user
    FOREIGN KEY (created_by) REFERENCES ue_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
