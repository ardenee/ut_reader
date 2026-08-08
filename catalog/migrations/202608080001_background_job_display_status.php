<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Materializes the administrator-visible background-job status as an indexed generated read model.
 * Why: Completed/failed filters and counters previously parsed result_json across job history on every live poll.
 * Role: Migration-only schema evolution; queue/result semantics remain unchanged.
 */
declare(strict_types=1);

return [
    'version' => '202608080001',
    'description' => 'Add indexed generated background-job display status.',
    'up' => static function (
        PDO $db,
        \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema
    ): void {
        if (!$schema->tableExists('ue_background_jobs')) {
            return;
        }

        if (!$schema->columnExists('ue_background_jobs', 'display_status')) {
            $db->exec(
                'ALTER TABLE ue_background_jobs ADD COLUMN display_status VARCHAR(120) '
                . 'GENERATED ALWAYS AS ('
                . 'CASE '
                . 'WHEN LOWER(status)<>"completed" THEN LOWER(status) '
                . 'WHEN LOWER(TRIM(COALESCE('
                . 'IF(JSON_VALID(result_json),JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.status")),NULL),""))) IN ("","completed") '
                . 'THEN "completed" '
                . 'WHEN LOWER(TRIM(COALESCE('
                . 'IF(JSON_VALID(result_json),JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.status")),NULL),"")))="verified" '
                . 'THEN "imported" '
                . 'ELSE LOWER(TRIM(COALESCE('
                . 'IF(JSON_VALID(result_json),JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.status")),NULL),""))) '
                . 'END'
                . ') STORED AFTER status'
            );
        }

        $schema->ensureIndex(
            'ue_background_jobs',
            'idx_ue_background_jobs_queue_display_id',
            'CREATE INDEX idx_ue_background_jobs_queue_display_id '
                . 'ON ue_background_jobs(queue_name,display_status,id)'
        );
        $schema->ensureIndex(
            'ue_background_jobs',
            'idx_ue_background_jobs_display_id',
            'CREATE INDEX idx_ue_background_jobs_display_id '
                . 'ON ue_background_jobs(display_status,id)'
        );
    },
];
