<?php
declare(strict_types=1);

return [
    'version' => '202607180005',
    'description' => 'Add background-job progress, cancellation, recovery and dead-letter metadata.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_background_jobs');

        $status = $schema->column('ue_background_jobs', 'status');
        if (is_array($status) && !str_contains(strtolower((string)$status['COLUMN_TYPE']), "'dead_letter'")) {
            $db->exec("ALTER TABLE ue_background_jobs MODIFY status ENUM('queued','running','completed','failed','dead_letter','cancelled') NOT NULL DEFAULT 'queued'");
        }

        $columns = [
            'progress_json' => 'ALTER TABLE ue_background_jobs ADD COLUMN progress_json MEDIUMTEXT NULL AFTER result_json',
            'progress_updated_at' => 'ALTER TABLE ue_background_jobs ADD COLUMN progress_updated_at DATETIME NULL AFTER progress_json',
            'last_heartbeat_at' => 'ALTER TABLE ue_background_jobs ADD COLUMN last_heartbeat_at DATETIME NULL AFTER lease_expires_at',
            'recovery_count' => 'ALTER TABLE ue_background_jobs ADD COLUMN recovery_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_heartbeat_at',
            'cancel_requested_at' => 'ALTER TABLE ue_background_jobs ADD COLUMN cancel_requested_at DATETIME NULL AFTER recovery_count',
            'cancel_requested_by' => 'ALTER TABLE ue_background_jobs ADD COLUMN cancel_requested_by INT UNSIGNED NULL AFTER cancel_requested_at',
            'cancel_reason' => 'ALTER TABLE ue_background_jobs ADD COLUMN cancel_reason VARCHAR(1000) NULL AFTER cancel_requested_by',
            'dead_lettered_at' => 'ALTER TABLE ue_background_jobs ADD COLUMN dead_lettered_at DATETIME NULL AFTER completed_at',
        ];
        foreach ($columns as $column => $sql) {
            $schema->ensureColumn('ue_background_jobs', $column, $sql);
        }

        $schema->ensureIndex(
            'ue_background_jobs',
            'idx_ue_background_jobs_cancel',
            'ALTER TABLE ue_background_jobs ADD KEY idx_ue_background_jobs_cancel (status, cancel_requested_at)'
        );
        $schema->ensureIndex(
            'ue_background_jobs',
            'idx_ue_background_jobs_dead_letter',
            'ALTER TABLE ue_background_jobs ADD KEY idx_ue_background_jobs_dead_letter (queue_name, status, dead_lettered_at)'
        );
        $schema->ensureIndex(
            'ue_background_jobs',
            'idx_ue_background_jobs_heartbeat',
            'ALTER TABLE ue_background_jobs ADD KEY idx_ue_background_jobs_heartbeat (queue_name, status, last_heartbeat_at)'
        );
    },
];
