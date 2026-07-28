<?php
declare(strict_types=1);

return [
    'version' => '202607280001',
    'description' => 'Add sampled request CPU and memory tracing for public concurrency tuning.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_request_resource_performance',
            'CREATE TABLE ue_request_resource_performance ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'route_key VARCHAR(190) NOT NULL,'
            . 'method VARCHAR(10) NOT NULL,'
            . 'audience VARCHAR(16) NOT NULL,'
            . 'sample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'total_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'total_sql_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'total_cpu_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'max_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'max_sql_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'max_cpu_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'total_peak_memory_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'max_peak_memory_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_sql_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_cpu_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_peak_memory_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_memory_delta_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_query_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'slow_sample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_slowest_query_hash CHAR(64) NOT NULL DEFAULT "",'
            . 'last_seen_at DATETIME NOT NULL,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_ue_request_resource_route (route_key,method,audience),'
            . 'KEY idx_ue_request_resource_cpu (total_cpu_us,max_cpu_us),'
            . 'KEY idx_ue_request_resource_memory (max_peak_memory_bytes),'
            . 'KEY idx_ue_request_resource_seen (last_seen_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
