<?php
declare(strict_types=1);

return [
    'version' => '202607270014',
    'description' => 'Add evidence-driven count caching, background-job search projection and bounded request performance aggregates.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_background_jobs');
        $schema->ensureIndex(
            'ue_background_jobs',
            'idx_ue_background_jobs_updated_id',
            'ALTER TABLE ue_background_jobs ADD KEY idx_ue_background_jobs_updated_id (updated_at,id)'
        );

        $schema->ensureTable(
            'ue_exact_count_cache',
            'CREATE TABLE ue_exact_count_cache ('
            . 'cache_key CHAR(64) NOT NULL,'
            . 'query_hash CHAR(64) NOT NULL,'
            . 'result_count BIGINT NOT NULL DEFAULT 0,'
            . 'expires_at DATETIME NOT NULL,'
            . 'generated_at DATETIME NOT NULL,'
            . 'hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_hit_at DATETIME NULL,'
            . 'PRIMARY KEY (cache_key),'
            . 'KEY idx_ue_exact_count_cache_query (query_hash),'
            . 'KEY idx_ue_exact_count_cache_expiry (expires_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $schema->ensureTable(
            'ue_background_job_search',
            'CREATE TABLE ue_background_job_search ('
            . 'job_id BIGINT UNSIGNED NOT NULL,'
            . 'queue_name VARCHAR(80) NOT NULL,'
            . 'job_type VARCHAR(120) NOT NULL,'
            . 'source_status VARCHAR(32) NOT NULL,'
            . 'search_text MEDIUMTEXT NOT NULL,'
            . 'source_updated_at DATETIME NOT NULL,'
            . 'PRIMARY KEY (job_id),'
            . 'KEY idx_ue_job_search_queue_job (queue_name,job_id),'
            . 'KEY idx_ue_job_search_status_job (source_status,job_id),'
            . 'KEY idx_ue_job_search_updated (source_updated_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $schema->ensureTable(
            'ue_request_performance',
            'CREATE TABLE ue_request_performance ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'route_key VARCHAR(190) NOT NULL,'
            . 'method VARCHAR(10) NOT NULL,'
            . 'sample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'total_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'total_sql_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'max_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'max_sql_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_sql_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'slow_sample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_query_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_seen_at DATETIME NOT NULL,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_ue_request_performance_route (route_key,method),'
            . 'KEY idx_ue_request_performance_slow (max_duration_us,max_sql_us),'
            . 'KEY idx_ue_request_performance_seen (last_seen_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $db->exec(
            'INSERT INTO ue_background_job_search(job_id,queue_name,job_type,source_status,search_text,source_updated_at) '
            . 'SELECT j.id,j.queue_name,j.job_type,j.status,'
            . 'LEFT(CONCAT_WS(" ",CAST(j.id AS CHAR),j.queue_name,j.job_type,COALESCE(j.concurrency_key,""),'
            . 'COALESCE(j.payload_json,""),COALESCE(j.last_error,""),COALESCE(j.result_json,"")),65535),j.updated_at '
            . 'FROM ue_background_jobs j '
            . 'ON DUPLICATE KEY UPDATE queue_name=VALUES(queue_name),job_type=VALUES(job_type),source_status=VALUES(source_status),'
            . 'search_text=VALUES(search_text),source_updated_at=VALUES(source_updated_at)'
        );

        $schema->ensureIndex(
            'ue_background_job_search',
            'ft_ue_job_search_text',
            'ALTER TABLE ue_background_job_search ADD FULLTEXT KEY ft_ue_job_search_text (search_text)'
        );
    },
];
