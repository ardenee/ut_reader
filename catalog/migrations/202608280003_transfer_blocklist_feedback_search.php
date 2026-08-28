<?php
/**
 * Add administrator transfer blocking, widen file feedback, and add exact Names search projection.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202608280003',
    'description' => 'Add transfer IP blocklist, 500-character file feedback and compact Names search lookup.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_transfer_blocked_ips',
            'CREATE TABLE ue_transfer_blocked_ips ('
            . 'ip_address VARBINARY(16) NOT NULL,'
            . 'note VARCHAR(500) NOT NULL DEFAULT "",'
            . 'created_by BIGINT UNSIGNED NULL,'
            . 'created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . 'updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),'
            . 'PRIMARY KEY (ip_address),'
            . 'KEY idx_ue_transfer_blocked_ips_created (created_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $schema->ensureTable(
            'ue_name_lookup',
            'CREATE TABLE ue_name_lookup ('
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'name_index INT UNSIGNED NOT NULL,'
            . 'name_term_id INT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY (file_id,name_index),'
            . 'KEY idx_ue_name_lookup_term (name_term_id,file_id),'
            . 'CONSTRAINT fk_ue_name_lookup_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $feedback = $db->query(
            'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_file_feedback" AND COLUMN_NAME="feedback_text" LIMIT 1'
        );
        $length = (int)($feedback->fetchColumn() ?: 0);
        if ($length > 0 && $length < 500) {
            $db->exec('ALTER TABLE ue_file_feedback MODIFY feedback_text VARCHAR(500) NOT NULL');
        }
    },
];
