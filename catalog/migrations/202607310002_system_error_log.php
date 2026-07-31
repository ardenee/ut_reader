<?php
declare(strict_types=1);

return [
    'version' => '202607310002',
    'description' => 'Add a central persistent error log for PHP, API and browser failures.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_system_errors',
            'CREATE TABLE ue_system_errors ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'error_key CHAR(64) NOT NULL,'
            . 'source_kind VARCHAR(32) NOT NULL,'
            . 'severity VARCHAR(16) NOT NULL DEFAULT "error",'
            . 'error_type VARCHAR(120) NOT NULL,'
            . 'message TEXT NOT NULL,'
            . 'route VARCHAR(500) NOT NULL DEFAULT "",'
            . 'request_method VARCHAR(12) NOT NULL DEFAULT "",'
            . 'http_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'source_file VARCHAR(1000) NOT NULL DEFAULT "",'
            . 'source_line INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'trace_text MEDIUMTEXT NULL,'
            . 'context_json MEDIUMTEXT NULL,'
            . 'request_id VARCHAR(64) NOT NULL DEFAULT "",'
            . 'user_id BIGINT UNSIGNED NULL,'
            . 'status VARCHAR(16) NOT NULL DEFAULT "open",'
            . 'occurrence_count BIGINT UNSIGNED NOT NULL DEFAULT 1,'
            . 'first_seen_at DATETIME NOT NULL,'
            . 'last_seen_at DATETIME NOT NULL,'
            . 'resolved_at DATETIME NULL,'
            . 'resolved_by BIGINT UNSIGNED NULL,'
            . 'resolution_note VARCHAR(500) NULL,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_ue_system_errors_key (error_key),'
            . 'KEY idx_ue_system_errors_status_seen (status,last_seen_at,id),'
            . 'KEY idx_ue_system_errors_source_seen (source_kind,last_seen_at,id),'
            . 'KEY idx_ue_system_errors_severity_seen (severity,last_seen_at,id),'
            . 'KEY idx_ue_system_errors_request (request_id,id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
