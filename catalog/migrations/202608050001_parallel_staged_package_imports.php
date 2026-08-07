<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Applies the database migration for 202608050001 parallel staged package imports.
 * Why: It evolves an existing UnrealDB database between schema/application versions without putting migration SQL in
 *      page requests.
 * Role: Migration-only code executed by the catalog migration runner.
 * Audit: Historical migrations may become archival after `install.sql` fully represents the current schema, but do
 *        not delete until upgrade paths are intentionally retired.
 */
declare(strict_types=1);

return [
    'version' => '202608050001',
    'description' => 'Allow up to eight staged package imports to run concurrently with per-file locking.',
    'up' => static function (
        PDO $db,
        \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema
    ): void {
        if (!$schema->tableExists('ue_background_jobs')) {
            return;
        }

        $statement = $db->prepare(
            'UPDATE ue_background_jobs SET resource_class="import-heavy",resource_limit=8,'
            . 'concurrency_key=CONCAT("import:file:",LEFT(SHA2(COALESCE('
            . 'NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.file_id")),""),'
            . 'NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.sha256")),""),'
            . 'NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.staged_path")),""),'
            . 'NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.source_relative_path")),""),'
            . 'NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.original_name")),""),'
            . 'CONCAT("job-",id)),256),48)) '
            . 'WHERE status IN ("queued","running") AND job_type="catalog.import_staged_package"'
        );
        $statement->execute();
    },
];
