ALTER TABLE ue_federation_transfer_jobs
  ADD COLUMN incoming_path VARCHAR(1000) NULL AFTER local_file_id,
  ADD COLUMN downloaded_md5 CHAR(32) NULL AFTER incoming_path,
  ADD COLUMN downloaded_sha1 CHAR(40) NULL AFTER downloaded_md5;

CREATE INDEX idx_ue_federation_transfer_incoming ON ue_federation_transfer_jobs (incoming_path(191));
