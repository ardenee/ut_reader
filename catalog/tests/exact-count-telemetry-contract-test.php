<?php
declare(strict_types=1);

function exact_count_telemetry_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$telemetry = file_get_contents(__DIR__ . '/../src/Infrastructure/Telemetry/CatalogExactCountTelemetry.php');
$queryCatalog = file_get_contents(__DIR__ . '/../src/Application/Telemetry/CatalogExactCountQueryCatalog.php');
$benchmark = file_get_contents(__DIR__ . '/../src/Application/Telemetry/CatalogExactCountBenchmark.php');
$planCapture = file_get_contents(__DIR__ . '/../src/Infrastructure/Telemetry/CatalogExactCountPlanCapture.php');
$conflicts = file_get_contents(__DIR__ . '/../src/Application/Federation/CatalogFederationConflictListService.php');
$page = file_get_contents(__DIR__ . '/../query-telemetry.php');
$telemetryMigration = file_get_contents(__DIR__ . '/../migrations/202607270012_exact_count_telemetry.php');
$planMigration = file_get_contents(__DIR__ . '/../migrations/202607270013_exact_count_query_plans.php');
$navigation = file_get_contents(__DIR__ . '/../lib/CatalogNavigation.php');
foreach ([$telemetry, $queryCatalog, $benchmark, $planCapture, $conflicts, $page, $telemetryMigration, $planMigration, $navigation] as $source) {
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
    'CatalogFederationConflictListService::countQuery',
    'LIMIT 5',
] as $fragment) {
    exact_count_telemetry_expect(str_contains($queryCatalog, $fragment), 'Shared count query catalog is missing: ' . $fragment);
}
exact_count_telemetry_expect(
    str_contains($benchmark, 'CatalogExactCountQueryCatalog::definitions($db)')
        && str_contains($benchmark, 'CatalogExactCountTelemetry::sample('),
    'Timing benchmark does not use the shared query catalog.'
);
exact_count_telemetry_expect(
    !str_contains(strtoupper($queryCatalog), ' UPDATE ')
        && !str_contains(strtoupper($queryCatalog), ' DELETE ')
        && !str_contains(strtoupper($benchmark), ' UPDATE ')
        && !str_contains(strtoupper($benchmark), ' DELETE '),
    'The benchmark query catalog must remain read-only outside telemetry persistence.'
);
exact_count_telemetry_expect(
    str_contains($conflicts, 'public static function countQuery(')
        && str_contains($conflicts, '$query = self::countQuery('),
    'Federation conflict timing and EXPLAIN do not share the authoritative count SQL.'
);

foreach ([
    "preg_match('/^SELECT\\b/i', \$sql)",
    "\$db->prepare('EXPLAIN ' . \$sql)",
    'full_scan_rows',
    'Using temporary',
    'Using filesort',
    "'assessment' => \$assessment",
    'repeated timings of at least 100 ms',
    'ON DUPLICATE KEY UPDATE',
    'ue_exact_count_query_plans',
] as $fragment) {
    exact_count_telemetry_expect(str_contains($planCapture, $fragment), 'EXPLAIN capture is missing: ' . $fragment);
}
exact_count_telemetry_expect(
    !str_contains(strtoupper($planCapture), 'ALTER TABLE')
        && !str_contains(strtoupper($planCapture), 'CREATE INDEX')
        && !str_contains(strtoupper($planCapture), 'ADD KEY'),
    'EXPLAIN capture must not automatically change indexes.'
);

foreach ([
    "catalog_require_admin_page('Exact Count Telemetry')",
    "catalog_check_csrf('exact-count-telemetry')",
    'CatalogExactCountBenchmark::run($db)',
    'CatalogExactCountPlanCapture::capture(',
    'CatalogExactCountQueryCatalog::definitions($db)',
    'Capture EXPLAIN plans',
    'EXPLAIN plans',
    'A schema change should require both a concerning plan and repeated timing samples of at least 100 ms.',
    "'DELETE FROM ue_exact_count_telemetry WHERE last_seen_at<DATE_SUB(NOW(),INTERVAL ? DAY)'",
    "'DELETE FROM ue_exact_count_query_plans WHERE captured_at<DATE_SUB(NOW(),INTERVAL ? DAY)'",
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
    exact_count_telemetry_expect(str_contains($telemetryMigration, $fragment), 'Telemetry migration is missing: ' . $fragment);
}
foreach ([
    'ue_exact_count_query_plans',
    'uq_ue_exact_plan_metric_context',
    'idx_ue_exact_plan_assessment',
    'idx_ue_exact_plan_captured',
    'idx_ue_exact_plan_metric',
] as $fragment) {
    exact_count_telemetry_expect(str_contains($planMigration, $fragment), 'Query-plan migration is missing: ' . $fragment);
}
exact_count_telemetry_expect(
    str_contains($navigation, "'Exact Count Telemetry' => \$root . 'query-telemetry.php'"),
    'Exact Count Telemetry is not present in the Maintenance navigation.'
);

fwrite(STDOUT, "Exact-count telemetry and EXPLAIN contract tests passed.\n");
