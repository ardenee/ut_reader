<?php
/**
 * Add first-party access-matrix telemetry plus a full-site administrator IP blocklist.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202609080002',
    'description' => 'Add access matrix events, site IP blocklist and unblock feedback requests.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_access_events',
            'CREATE TABLE ue_access_events ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'event_type VARCHAR(32) NOT NULL,'
            . 'page_key VARCHAR(190) NOT NULL,'
            . 'request_path VARCHAR(500) NOT NULL,'
            . 'section_key VARCHAR(190) NULL,'
            . 'action_key VARCHAR(190) NULL,'
            . 'target_path VARCHAR(500) NULL,'
            . 'referrer_path VARCHAR(500) NULL,'
            . 'request_method VARCHAR(12) NOT NULL,'
            . 'request_id VARCHAR(64) NULL,'
            . 'ip_address VARBINARY(16) NULL,'
            . 'user_id BIGINT UNSIGNED NULL,'
            . 'session_hash BINARY(32) NULL,'
            . 'user_agent VARCHAR(500) NOT NULL DEFAULT "",'
            . 'occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . 'PRIMARY KEY (id),'
            . 'KEY idx_ue_access_events_time (occurred_at,id),'
            . 'KEY idx_ue_access_events_page (page_key,occurred_at,id),'
            . 'KEY idx_ue_access_events_type (event_type,occurred_at,id),'
            . 'KEY idx_ue_access_events_ip (ip_address,occurred_at,id),'
            . 'KEY idx_ue_access_events_user (user_id,occurred_at,id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $schema->ensureTable(
            'ue_site_blocked_ips',
            'CREATE TABLE ue_site_blocked_ips ('
            . 'ip_address VARBINARY(16) NOT NULL,'
            . 'note VARCHAR(500) NOT NULL DEFAULT "",'
            . 'created_by BIGINT UNSIGNED NULL,'
            . 'created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . 'updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),'
            . 'PRIMARY KEY (ip_address),'
            . 'KEY idx_ue_site_blocked_ips_updated (updated_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $schema->ensureTable(
            'ue_site_block_feedback',
            'CREATE TABLE ue_site_block_feedback ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'ip_address VARBINARY(16) NOT NULL,'
            . 'email VARCHAR(254) NULL,'
            . 'message VARCHAR(4000) NOT NULL,'
            . 'status VARCHAR(24) NOT NULL DEFAULT "open",'
            . 'resolution_note VARCHAR(500) NULL,'
            . 'created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . 'resolved_at DATETIME(6) NULL,'
            . 'PRIMARY KEY (id),'
            . 'KEY idx_ue_site_block_feedback_status (status,created_at,id),'
            . 'KEY idx_ue_site_block_feedback_ip (ip_address,created_at,id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
