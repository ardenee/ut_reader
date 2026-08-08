<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Adds exact indexes required by parallel resource-aware background-job claiming.
 * Why: Per-resource and concurrency-key admission checks must remain indexed after removing the queue-wide claim mutex.
 * Role: Migration-only schema evolution; no queue policy changes.
 */
declare(strict_types=1);

return [
    'version' => '202608080002',
    'description' => 'Add resource-class and concurrency-key indexes for parallel job claiming.',
    'up' => static function (
        PDO $db,
        \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema
    ): void {
        if (!$schema->tableExists('ue_background_jobs')) {
            return;
        }
        $schema->ensureIndex(
            'ue_background_jobs',
            'idx_ue_background_jobs_running_resource',
            'CREATE INDEX idx_ue_background_jobs_running_resource '
                . 'ON ue_background_jobs(queue_name,status,resource_class,id)'
        );
        $schema->ensureIndex(
            'ue_background_jobs',
            'idx_ue_background_jobs_running_concurrency',
            'CREATE INDEX idx_ue_background_jobs_running_concurrency '
                . 'ON ue_background_jobs(queue_name,status,concurrency_key,id)'
        );
    },
];
