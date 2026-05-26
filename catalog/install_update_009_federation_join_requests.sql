CREATE TABLE IF NOT EXISTS ue_federation_join_requests (
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
  CONSTRAINT fk_ue_federation_join_peer FOREIGN KEY (created_peer_id) REFERENCES ue_federation_peers(id) ON DELETE SET NULL,
  CONSTRAINT fk_ue_federation_join_user FOREIGN KEY (approved_by) REFERENCES ue_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ue_federation_settings(setting_name, setting_value) VALUES
('join_requests_enabled', '1'),
('join_claim_token_ttl_seconds', '86400');
