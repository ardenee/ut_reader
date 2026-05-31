CREATE TABLE IF NOT EXISTS ue_app_logs (
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
