<?php
/**
 * Public contribution quarantine and upload reservation ledger.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202608280001',
    'description' => 'Add public contribution upload quarantine ledger and identity indexes.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_public_uploads',
            'CREATE TABLE ue_public_uploads ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'upload_token CHAR(64) NOT NULL,'
            . 'client_key CHAR(64) NOT NULL,'
            . 'original_name VARCHAR(255) NOT NULL,'
            . 'relative_path VARCHAR(1000) NOT NULL,'
            . 'file_size BIGINT UNSIGNED NOT NULL,'
            . 'client_md5 CHAR(32) NULL,'
            . 'client_sha1 CHAR(40) NULL,'
            . 'client_guid VARCHAR(80) NULL,'
            . 'active_identity_key CHAR(64) NULL,'
            . 'server_md5 CHAR(32) NULL,'
            . 'server_sha1 CHAR(40) NULL,'
            . 'server_guid VARCHAR(80) NULL,'
            . 'status VARCHAR(24) NOT NULL DEFAULT "reserved",'
            . 'submitter_ip VARBINARY(16) NULL,'
            . 'user_agent VARCHAR(500) NOT NULL DEFAULT "",'
            . 'received_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'next_chunk_index INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'quarantine_relative_path VARCHAR(1000) NULL,'
            . 'background_job_id BIGINT UNSIGNED NULL,'
            . 'unverified_file_id BIGINT UNSIGNED NULL,'
            . 'result_message VARCHAR(1000) NULL,'
            . 'reservation_expires_at DATETIME(6) NOT NULL,'
            . 'created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . 'updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),'
            . 'completed_at DATETIME(6) NULL,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_ue_public_uploads_token (upload_token),'
            . 'UNIQUE KEY uq_ue_public_uploads_active_identity (active_identity_key),'
            . 'KEY idx_ue_public_uploads_identity (client_md5,client_sha1,file_size),'
            . 'KEY idx_ue_public_uploads_guid (client_guid),'
            . 'KEY idx_ue_public_uploads_ip_time (submitter_ip,created_at,id),'
            . 'KEY idx_ue_public_uploads_status_expiry (status,reservation_expires_at,id),'
            . 'KEY idx_ue_public_uploads_job (background_job_id),'
            . 'KEY idx_ue_public_uploads_unverified (unverified_file_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
