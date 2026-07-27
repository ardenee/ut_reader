<?php
declare(strict_types=1);

function exact_count_telemetry_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$telemetry = file_get_contents(__DIR__ . '/../src/Infrastructure/Telemetry/CatalogExactCountTelemetry.php');
$benchmark = file_get_contents(__DIR__ . '/../src/Application/Telemetry/CatalogExactCountBenchmark.php');
$page = file_get_contents(__DIR__ . '/../query-telemetry.php');
$migration = file_get_contents(__DIR__ . '/../migrations/202607270012_exact_count_telemetry.php');
$navigation = file_get_contents(__DIR__ . '/../lib/CatalogNavigation.php');
foreach ([$telemetry, $benchmark, $page, $migration, $navigation] as $source) {
    exact_count_telemetry_expect(is_string($source), 'Exact-count telemetry source could not be read.');
}

foreach ([
    'hrtime(true)',
    'SLOW_THRESHOLD_US = 100000',
    'ON DUPLICATE KEY UPDATE',
    'sample_count=sample_count+1',
    'total_duration_us=total_duration_us+VALUES(total_duration_us)',
    'max_duration_us=GREATEST',
    'slow_sample_count=slow_sample_count+VALUES(slow_sample_count)',
    'context_hash',
    'strlen($contextJson) > 4000',
    'information_schema.tables',
] as $fragment) {
    exact_count_telemetry_expect(str_contains($telemetry, $fragment), 'Telemetry recorder is missing: ' . $fragment);
}
exact_count_telemetry_expect(
    !str_contains($telemetry, 'INSERT INTO ue_app_logs'),
    'Exact-count telemetry must not append one application-log row per sample.'
);

foreach ([
    "'game_files.total'",
    "'game_files.missing_filter'",
    "'missing.files'",
    "'missing.objects'",
    "'missing.packages'",
    "'missing.package_objects'",
    "'missing.package_files'",
    "'background_jobs.total'",
    "'federation.conflicts'",
    'CatalogJobDisplayStatus::filterCondition',
    'CatalogFederationConflictListService::count',
    'LIMIT 5',
] as $fragment) {
    exact_count_telemetry_expect(str_contains($benchmark, $fragment), 'Benchmark coverage is missing: ' . $fragment);
}
exact_count_telemetry_expect(
    !str_contains(strtoupper($benchmark), ' UPDATE ') && !str_contains(strtoupper($benchmark), ' DELETE '),
    'The benchmark must remain read-only outside the telemetry recorder.'
);

foreach ([
    "catalog_require_admin_page('Exact Count Telemetry')",
    "catalog_check_csrf('exact-count-telemetry')",
    'CatalogExactCountBenchmark::run($db)',
    "'DELETE FROM ue_exact_count_telemetry WHERE last_seen_at<DATE_SUB(NOW(),INTERVAL ? DAY)'",
    "DELETE FROM ue_exact_count_telemetry",
    'Samples ≥100 ms',
    'Run exact-count benchmark',
    'Most recent benchmark run',
] as $fragment) {
    exact_count_telemetry_expect(str_contains($page, $fragment), 'Telemetry page is missing: ' . $fragment);
}

foreach ([
    'ue_exact_count_telemetry',
    'uq_ue_exact_count_metric_context',
    'idx_ue_exact_count_last_seen',
    'idx_ue_exact_count_max_duration',
    'idx_ue_exact_count_metric',
] as $fragment) {
    exact_count_telemetry_expect(str_contains($migration, $fragment), 'Telemetry migration is missing: ' . $fragment);
}
exact_count_telemetry_expect(
    str_contains($navigation, "'Exact Count Telemetry' => \$root . 'query-telemetry.php'"),
    'Exact Count Telemetry is not present in the Maintenance navigation.'
);

fwrite(STDOUT, "Exact-count telemetry contract tests passed.\n");
