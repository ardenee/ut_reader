<?php
declare(strict_types=1);

return [
    'version' => '202608060001',
    'description' => 'Add administrator-controlled job resource limits and apply them to the active queue.',
    'up' => static function (
        PDO $db,
        \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema
    ): void {
        if (!$schema->tableExists('ue_job_resource_limits')) {
            $db->exec(
                'CREATE TABLE ue_job_resource_limits ('
                . 'resource_class VARCHAR(80) NOT NULL,'
                . 'limit_value SMALLINT UNSIGNED NOT NULL,'
                . 'updated_by INT UNSIGNED NULL,'
                . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY (resource_class),'
                . 'KEY idx_ue_job_resource_limits_updated_by (updated_by)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        $seed = $db->prepare(
            'INSERT INTO ue_job_resource_limits (resource_class,limit_value) VALUES (?,?) '
            . 'ON DUPLICATE KEY UPDATE resource_class=VALUES(resource_class)'
        );
        foreach ([
            'dependency-heavy' => 1,
            'search-heavy' => 1,
            'import-heavy' => 8,
            'archive-import-heavy' => 1,
            'bucket-processing' => 8,
            'storage-heavy' => 1,
            'package-heavy' => 1,
            'housekeeping' => 2,
            'default' => 4,
        ] as $resourceClass => $limit) {
            $seed->execute([$resourceClass, $limit]);
        }

        if (!$schema->tableExists('ue_background_jobs')) {
            return;
        }

        // Full PAK and game-backup imports intentionally remain separate from
        // ordinary per-file imports so their limits can be tuned independently.
        $db->exec(
            'UPDATE ue_background_jobs SET resource_class="archive-import-heavy",resource_limit=1 '
            . 'WHERE status IN ("queued","running") '
            . 'AND job_type IN ("catalog.import_staged_pak","catalog.import_game_backup")'
        );

        // Make every current queued/running row use the saved class limit now;
        // the normal enqueue path uses the same table for future jobs.
        $db->exec(
            'UPDATE ue_background_jobs j JOIN ue_job_resource_limits l ON l.resource_class=j.resource_class '
            . 'SET j.resource_limit=l.limit_value '
            . 'WHERE j.status IN ("queued","running")'
        );
    },
];
