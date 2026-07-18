<?php
declare(strict_types=1);

return [
    'version' => '202607180006',
    'description' => 'Add persisted job resource classes and concurrency keys.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_background_jobs');
        $schema->ensureColumn(
            'ue_background_jobs',
            'resource_class',
            'ALTER TABLE ue_background_jobs ADD COLUMN resource_class VARCHAR(80) NOT NULL DEFAULT "default" AFTER job_type'
        );
        $schema->ensureColumn(
            'ue_background_jobs',
            'resource_limit',
            'ALTER TABLE ue_background_jobs ADD COLUMN resource_limit SMALLINT UNSIGNED NOT NULL DEFAULT 4 AFTER resource_class'
        );
        $schema->ensureColumn(
            'ue_background_jobs',
            'concurrency_key',
            'ALTER TABLE ue_background_jobs ADD COLUMN concurrency_key VARCHAR(191) NULL AFTER resource_limit'
        );

        $db->exec(
            'UPDATE ue_background_jobs SET resource_class="dependency-heavy", resource_limit=1 '
            . 'WHERE job_type IN ("catalog.rebuild_game_dependencies","catalog.rebuild_affected_dependencies")'
        );
        $db->exec(
            'UPDATE ue_background_jobs SET resource_class="housekeeping", resource_limit=2 '
            . 'WHERE job_type="catalog.prune_upload_progress"'
        );

        $schema->ensureIndex(
            'ue_background_jobs',
            'idx_ue_background_jobs_resource',
            'ALTER TABLE ue_background_jobs ADD KEY idx_ue_background_jobs_resource (queue_name, status, resource_class)'
        );
        $schema->ensureIndex(
            'ue_background_jobs',
            'idx_ue_background_jobs_concurrency',
            'ALTER TABLE ue_background_jobs ADD KEY idx_ue_background_jobs_concurrency (queue_name, status, concurrency_key)'
        );
    },
];
