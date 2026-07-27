<?php
declare(strict_types=1);

return [
    'version' => '202607270012',
    'description' => 'Add bounded aggregate telemetry for exact-count query durations.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_exact_count_telemetry',
            'CREATE TABLE ue_exact_count_telemetry ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'metric_key VARCHAR(120) NOT NULL,'
            . 'context_hash CHAR(64) NOT NULL,'
            . 'context_json TEXT NOT NULL,'
            . 'sample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'total_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'max_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_duration_us BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'slow_sample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_result_count BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_ue_exact_count_metric_context (metric_key,context_hash),'
            . 'KEY idx_ue_exact_count_last_seen (last_seen_at),'
            . 'KEY idx_ue_exact_count_max_duration (max_duration_us),'
            . 'KEY idx_ue_exact_count_metric (metric_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
