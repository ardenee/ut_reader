<?php
declare(strict_types=1);

return [
    'version' => '202607310007',
    'description' => 'Record generated-package builds and public downloads for later reporting.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS ue_generated_package_audit ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'job_id BIGINT UNSIGNED NOT NULL,'
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'game_id BIGINT UNSIGNED NOT NULL,'
            . 'user_id BIGINT UNSIGNED NULL,'
            . 'request_ip VARBINARY(16) NULL,'
            . 'user_agent VARCHAR(500) NOT NULL DEFAULT "",'
            . 'package_format VARCHAR(32) NOT NULL,'
            . 'package_name VARCHAR(255) NOT NULL,'
            . 'package_version VARCHAR(80) NOT NULL,'
            . 'include_dependencies TINYINT(1) NOT NULL DEFAULT 1,'
            . 'allow_incomplete TINYINT(1) NOT NULL DEFAULT 0,'
            . 'status VARCHAR(24) NOT NULL DEFAULT "queued",'
            . 'artifact_name VARCHAR(255) NULL,'
            . 'artifact_size BIGINT UNSIGNED NULL,'
            . 'artifact_sha256 BINARY(32) NULL,'
            . 'error_message VARCHAR(1000) NULL,'
            . 'queued_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . 'started_at DATETIME(6) NULL,'
            . 'completed_at DATETIME(6) NULL,'
            . 'updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_ue_generated_package_audit_job (job_id),'
            . 'KEY idx_ue_generated_package_audit_created (queued_at,id),'
            . 'KEY idx_ue_generated_package_audit_game (game_id,queued_at,id),'
            . 'KEY idx_ue_generated_package_audit_file (file_id,queued_at,id),'
            . 'KEY idx_ue_generated_package_audit_ip (request_ip,queued_at,id),'
            . 'KEY idx_ue_generated_package_audit_status (status,queued_at,id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->exec(
            'CREATE TABLE IF NOT EXISTS ue_download_audit ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'download_type VARCHAR(32) NOT NULL,'
            . 'file_id BIGINT UNSIGNED NULL,'
            . 'game_id BIGINT UNSIGNED NULL,'
            . 'job_id BIGINT UNSIGNED NULL,'
            . 'user_id BIGINT UNSIGNED NULL,'
            . 'ip_address VARBINARY(16) NULL,'
            . 'user_agent VARCHAR(500) NOT NULL DEFAULT "",'
            . 'download_name VARCHAR(255) NOT NULL DEFAULT "",'
            . 'package_format VARCHAR(32) NULL,'
            . 'artifact_size BIGINT UNSIGNED NULL,'
            . 'range_start BIGINT UNSIGNED NULL,'
            . 'range_end BIGINT UNSIGNED NULL,'
            . 'bytes_requested BIGINT UNSIGNED NULL,'
            . 'bytes_sent BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'status VARCHAR(24) NOT NULL DEFAULT "started",'
            . 'http_status SMALLINT UNSIGNED NOT NULL DEFAULT 200,'
            . 'error_message VARCHAR(1000) NULL,'
            . 'started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . 'completed_at DATETIME(6) NULL,'
            . 'PRIMARY KEY (id),'
            . 'KEY idx_ue_download_audit_created (started_at,id),'
            . 'KEY idx_ue_download_audit_type (download_type,started_at,id),'
            . 'KEY idx_ue_download_audit_ip (ip_address,started_at,id),'
            . 'KEY idx_ue_download_audit_file (file_id,started_at,id),'
            . 'KEY idx_ue_download_audit_job (job_id,started_at,id),'
            . 'KEY idx_ue_download_audit_status (status,started_at,id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
    'down' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $db->exec('DROP TABLE IF EXISTS ue_download_audit');
        $db->exec('DROP TABLE IF EXISTS ue_generated_package_audit');
    },
];
