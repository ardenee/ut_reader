CREATE TABLE IF NOT EXISTS ue_games (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  engine_key VARCHAR(10) NOT NULL,
  description TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_games_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ue_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ue_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ue_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id INT UNSIGNED NOT NULL,
  package_name VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  relative_path VARCHAR(500) NOT NULL,
  extension VARCHAR(20) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  md5 CHAR(32) NOT NULL,
  sha1 CHAR(40) NOT NULL,
  package_guid VARCHAR(80) NULL,
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
  UNIQUE KEY uq_ue_files_md5 (md5),
  KEY idx_ue_files_game_package (game_id, package_name),
  KEY idx_ue_files_guid (package_guid),
  CONSTRAINT fk_ue_files_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_files_user FOREIGN KEY (uploaded_by) REFERENCES ue_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ue_names (
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

CREATE TABLE IF NOT EXISTS ue_imports (
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

CREATE TABLE IF NOT EXISTS ue_exports (
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
  KEY idx_ue_exports_object (object_name),
  CONSTRAINT fk_ue_exports_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ue_dependencies (
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
  KEY idx_ue_deps_required (required_package, required_object_path(191)),
  CONSTRAINT fk_ue_deps_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_deps_import FOREIGN KEY (import_id) REFERENCES ue_imports(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_deps_resolved_file FOREIGN KEY (resolved_file_id) REFERENCES ue_files(id) ON DELETE SET NULL,
  CONSTRAINT fk_ue_deps_resolved_export FOREIGN KEY (resolved_export_id) REFERENCES ue_exports(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ue_games (name, slug, engine_key, description) VALUES
('Unreal / Unreal Tournament', 'ut99', 'UE1', 'UE1-era packages such as .u, .unr, .utx, .umx and .uax'),
('Unreal Tournament 2003/2004', 'ut2004', 'UE2', 'UE2/UE2.5 package catalog'),
('Unreal Tournament 3', 'ut3', 'UE3', 'UE3 packages such as .ut3 and .upk'),
('Unreal Engine 4', 'ue4', 'UE4', 'UE4 .uasset and .umap packages');
