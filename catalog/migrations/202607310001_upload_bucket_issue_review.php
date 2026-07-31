<?php
declare(strict_types=1);

return [
    'version' => '202607310001',
    'description' => 'Persist Upload Bucket browser and transfer failures for later review and resolution.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_upload_bucket_issues',
            'CREATE TABLE ue_upload_bucket_issues ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'issue_key CHAR(64) NOT NULL,'
            . 'source_kind VARCHAR(32) NOT NULL DEFAULT "upload_bucket_v2",'
            . 'upload_session_id VARCHAR(64) NOT NULL DEFAULT "",'
            . 'relative_path TEXT NOT NULL,'
            . 'original_name VARCHAR(255) NOT NULL,'
            . 'file_size_text VARCHAR(32) NOT NULL DEFAULT "",'
            . 'stage VARCHAR(64) NOT NULL,'
            . 'error_message TEXT NOT NULL,'
            . 'status VARCHAR(16) NOT NULL DEFAULT "open",'
            . 'occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,'
            . 'first_seen_at DATETIME NOT NULL,'
            . 'last_seen_at DATETIME NOT NULL,'
            . 'resolved_at DATETIME NULL,'
            . 'resolved_by BIGINT UNSIGNED NULL,'
            . 'resolution_note VARCHAR(500) NULL,'
            . 'created_by BIGINT UNSIGNED NULL,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_ue_upload_bucket_issues_key (issue_key),'
            . 'KEY idx_ue_upload_bucket_issues_status_seen (status,last_seen_at,id),'
            . 'KEY idx_ue_upload_bucket_issues_session (upload_session_id,id),'
            . 'KEY idx_ue_upload_bucket_issues_stage (stage,id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
