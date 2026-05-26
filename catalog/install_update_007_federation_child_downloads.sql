ALTER TABLE ue_federation_transfer_jobs
  ADD COLUMN remote_request_item_id BIGINT UNSIGNED NULL AFTER request_item_id;

CREATE INDEX idx_ue_federation_transfer_remote_item ON ue_federation_transfer_jobs (remote_request_item_id);
