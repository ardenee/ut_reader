<?php
/**
 * Store short anonymous correction notes submitted from verified file pages.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202608260002',
    'description' => 'Add anonymous per-file feedback with submitter IP and submission timestamp.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_file_feedback',
            'CREATE TABLE ue_file_feedback ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'feedback_text VARCHAR(100) NOT NULL,'
            . 'submitter_ip VARBINARY(16) NULL,'
            . 'submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . 'PRIMARY KEY (id),'
            . 'KEY idx_ue_file_feedback_file_time (file_id,submitted_at,id),'
            . 'KEY idx_ue_file_feedback_time (submitted_at,id),'
            . 'KEY idx_ue_file_feedback_ip_time (submitter_ip,submitted_at,id),'
            . 'CONSTRAINT fk_ue_file_feedback_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
