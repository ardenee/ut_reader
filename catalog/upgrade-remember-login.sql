-- Adds persistent admin login tokens for the "Keep me logged in" option.
-- Safe to run on an existing catalog before or after deploying the remember-login code.

CREATE TABLE IF NOT EXISTS ue_remember_tokens (
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
