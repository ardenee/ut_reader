<?php
declare(strict_types=1);

return [
    'version' => '202607270013',
    'description' => 'Add bounded EXPLAIN snapshots for representative exact-count queries.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_exact_count_query_plans',
            'CREATE TABLE ue_exact_count_query_plans ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'metric_key VARCHAR(120) NOT NULL,'
            . 'context_hash CHAR(64) NOT NULL,'
            . 'context_json TEXT NOT NULL,'
            . 'query_hash CHAR(64) NOT NULL,'
            . 'query_sql MEDIUMTEXT NOT NULL,'
            . 'plan_json MEDIUMTEXT NOT NULL,'
            . 'plan_step_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'estimated_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'full_scan_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'access_types VARCHAR(255) NULL,'
            . 'possible_keys TEXT NULL,'
            . 'selected_keys TEXT NULL,'
            . 'extra_flags TEXT NULL,'
            . 'assessment VARCHAR(32) NOT NULL DEFAULT "normal",'
            . 'recommendation TEXT NULL,'
            . 'error_message TEXT NULL,'
            . 'captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_ue_exact_plan_metric_context (metric_key,context_hash),'
            . 'KEY idx_ue_exact_plan_assessment (assessment,estimated_rows),'
            . 'KEY idx_ue_exact_plan_captured (captured_at),'
            . 'KEY idx_ue_exact_plan_metric (metric_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
