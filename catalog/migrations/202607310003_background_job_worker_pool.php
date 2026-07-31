<?php
declare(strict_types=1);

return [
    'version' => '202607310003',
    'description' => 'Enable a bounded detached worker pool and per-file Upload Bucket concurrency.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        if (!$schema->tableExists('ue_background_jobs')) {
            return;
        }

        $statement = $db->prepare(
            'UPDATE ue_background_jobs SET resource_class="bucket-processing",resource_limit=8,'
            . 'concurrency_key=CONCAT("bucket:file:",LEFT(SHA2(COALESCE('
            . 'NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.file_id")),""),'
            . 'NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.upload_id")),""),'
            . 'NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.staged_path")),""),'
            . 'NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.source_relative_path")),""),'
            . 'NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.original_name")),""),'
            . 'CONCAT("job-",id)),256),48)) '
            . 'WHERE status IN ("queued","running") AND job_type IN ('
            . '"catalog.prepare_bucket_redirect","catalog.process_bucket_upload","catalog.repair_unverified_metadata")'
        );
        $statement->execute();
    },
];
