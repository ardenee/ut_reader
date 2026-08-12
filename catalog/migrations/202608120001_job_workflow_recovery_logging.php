<?php
/**
 * Durable parent/child workflow identity and configurable background-job logging.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202608120001',
    'description' => 'Add resumable parent/child job workflow identity and configurable job-event logging policy.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        $schema->ensureColumn(
            'ue_background_jobs',
            'parent_job_id',
            'ALTER TABLE ue_background_jobs ADD COLUMN parent_job_id BIGINT UNSIGNED NULL AFTER id'
        );
        $schema->ensureColumn(
            'ue_background_jobs',
            'workflow_unit_key',
            'ALTER TABLE ue_background_jobs ADD COLUMN workflow_unit_key VARCHAR(191) NULL AFTER parent_job_id'
        );
        $schema->ensureIndex(
            'ue_background_jobs',
            'idx_ue_background_jobs_parent_status',
            'CREATE INDEX idx_ue_background_jobs_parent_status ON ue_background_jobs(parent_job_id,status,id)'
        );
        $schema->ensureIndex(
            'ue_background_jobs',
            'uq_ue_background_jobs_parent_unit',
            'CREATE UNIQUE INDEX uq_ue_background_jobs_parent_unit ON ue_background_jobs(parent_job_id,workflow_unit_key)'
        );

        $foreignKey = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME="ue_background_jobs" '
            . 'AND CONSTRAINT_NAME="fk_ue_background_jobs_parent"'
        );
        $foreignKey->execute();
        if ((int)$foreignKey->fetchColumn() === 0) {
            $db->exec(
                'ALTER TABLE ue_background_jobs ADD CONSTRAINT fk_ue_background_jobs_parent '
                . 'FOREIGN KEY (parent_job_id) REFERENCES ue_background_jobs(id) ON DELETE SET NULL'
            );
        }

        $schema->ensureTable(
            'ue_job_logging_settings',
            'CREATE TABLE ue_job_logging_settings ('
            . 'setting_key VARCHAR(64) NOT NULL,'
            . 'enabled TINYINT(1) NOT NULL DEFAULT 0,'
            . 'updated_by BIGINT UNSIGNED NULL,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (setting_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $seed = $db->prepare(
            'INSERT INTO ue_job_logging_settings(setting_key,enabled) VALUES (?,?) '
            . 'ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)'
        );
        foreach ([
            'worker_diagnostics' => 0,
            'event_progress' => 0,
            'event_success' => 0,
            'event_duplicate' => 0,
            'event_skipped' => 0,
            'event_cancelled' => 0,
            'event_errors' => 1,
        ] as $key => $enabled) {
            $seed->execute([$key, $enabled]);
        }
    },
];
