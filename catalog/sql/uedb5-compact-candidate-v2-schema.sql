-- UEDB5 compact candidate V2 schema. NOT automatically applied by install.sql.
-- Application cutover and backfill must be approved separately.
-- All three tables must be created before dual publishing can begin.
-- Keep old ue_uedb5_*_candidates until parity + rollback are certified.
CREATE TABLE ue_uedb5_compact_name_dictionary (
  name_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name_key_hash BINARY(16) NOT NULL,
  name_key_length INT UNSIGNED NOT NULL,
  name_key_fingerprint BINARY(32) NOT NULL,
  PRIMARY KEY (name_id),
  UNIQUE KEY uq_uedb5_compact_name_fingerprint (name_key_fingerprint),
  KEY idx_uedb5_compact_name_lookup
    (name_key_hash,name_key_length,name_key_fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ue_uedb5_compact_name_candidates (
  file_id BIGINT UNSIGNED NOT NULL,
  name_id BIGINT UNSIGNED NOT NULL,
  first_name_index INT UNSIGNED NOT NULL,
  PRIMARY KEY (file_id,name_id),
  KEY idx_uedb5_compact_names_lookup (name_id,file_id),
  CONSTRAINT fk_uedb5_compact_names_file
    FOREIGN KEY (file_id) REFERENCES ue_uedb5_files(file_id) ON DELETE CASCADE,
  CONSTRAINT fk_uedb5_compact_names_dict
    FOREIGN KEY (name_id) REFERENCES ue_uedb5_compact_name_dictionary(name_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ue_uedb5_compact_object_candidates (
  file_id BIGINT UNSIGNED NOT NULL,
  object_kind TINYINT UNSIGNED NOT NULL,
  object_index INT UNSIGNED NOT NULL,
  name_id BIGINT UNSIGNED NOT NULL,
  public_export_hash BINARY(8) NULL,
  PRIMARY KEY (file_id,object_kind,object_index),
  KEY idx_uedb5_compact_objects_lookup
    (name_id,file_id,object_kind,object_index),
  KEY idx_uedb5_compact_public_export
    (file_id,object_kind,public_export_hash,object_index),
  CONSTRAINT fk_uedb5_compact_objects_file
    FOREIGN KEY (file_id) REFERENCES ue_uedb5_files(file_id) ON DELETE CASCADE,
  CONSTRAINT fk_uedb5_compact_objects_dict
    FOREIGN KEY (name_id) REFERENCES ue_uedb5_compact_name_dictionary(name_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
