<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders Exact Count Telemetry and stores only session-local last-run display data.
 * Why: Benchmark/plan actions, retention mutations, schema checks and telemetry SQL now belong to a diagnostics service.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Diagnostics\CatalogExactCountTelemetryAdminService;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogExactCountTelemetry;

catalog_start_session();

function exact_count_ms(int|float|string|null $microseconds): string
{
    return number_format(max(0, (float)$microseconds) / 1000, 3) . ' ms';
}

function exact_count_tone(float $averageMs, float $maximumMs, float $slowRate): array
{
    if ($averageMs >= 250 || $maximumMs >= 1000 || $slowRate >= 50) {
        return ['Investigate', 'danger'];
    }
    if ($averageMs >= 100 || $maximumMs >= 500 || $slowRate > 0) {
        return ['Watch', 'warning'];
    }
    return ['Normal', 'success'];
}

function exact_plan_badge(string $assessment): string
{
    return match (strtolower(trim($assessment))) {
        'investigate' => CatalogUi::badge('Investigate', 'danger'),
        'watch' => CatalogUi::badge('Watch', 'warning'),
        'error' => CatalogUi::badge('EXPLAIN error', 'danger'),
        default => CatalogUi::badge('Normal', 'success'),
    };
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Exact Count Telemetry')) {
        exit;
    }

    $service = new CatalogExactCountTelemetryAdminService($db);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('exact-count-telemetry');
        $result = $service->handleAction(
            strtolower(trim((string)($_POST['action'] ?? ''))),
            (int)($_POST['days'] ?? 90)
        );
        if ($result['clear_last']) {
            $_SESSION['exact_count_last_run'] = [];
            $_SESSION['exact_count_last_plans'] = [];
        }
        if ($result['last_run'] !== []) {
            $_SESSION['exact_count_last_run'] = $result['last_run'];
        }
        if ($result['last_plans'] !== []) {
            $_SESSION['exact_count_last_plans'] = $result['last_plans'];
        }
        $_SESSION['exact_count_flash'] = $result['flash'];
        header('Location: query-telemetry.php');
        exit;
    }

    $metricFilter = substr(strtolower(trim((string)($_GET['metric'] ?? ''))), 0, 120);
    $minimumMs = max(0, min(60000, (float)($_GET['minimum_ms'] ?? 0)));
    $snapshot = $service->snapshot($metricFilter, $minimumMs);
    $telemetryAvailable = $snapshot['availability']['telemetry'];
    $plansAvailable = $snapshot['availability']['plans'];
    $summary = $snapshot['summary'];
    $rows = $snapshot['rows'];
    $planSummary = $snapshot['plan_summary'];
    $planRows = $snapshot['plan_rows'];

    $lastRun = is_array($_SESSION['exact_count_last_run'] ?? null)
        ? $_SESSION['exact_count_last_run']
        : [];
    $lastPlans = is_array($_SESSION['exact_count_last_plans'] ?? null)
        ? $_SESSION['exact_count_last_plans']
        : [];

    catalog_head('Exact Count Telemetry');
    catalog_flash($_SESSION['exact_count_flash'] ?? null);
    unset($_SESSION['exact_count_flash']);
    catalog_page_header(
        'Exact Count Telemetry',
        'Measure exact totals and capture their live EXPLAIN plans before changing indexes or caching displayed counts.',
        ['Background Jobs' => 'background-jobs.php', 'Missing Dependencies' => 'missing.php']
    );

    if (!$telemetryAvailable || !$plansAvailable) {
        $missing = [];
        if (!$telemetryAvailable) {
            $missing[] = '202607270012';
        }
        if (!$plansAvailable) {
            $missing[] = '202607270013';
        }
        echo CatalogUi::alert(
            'warning',
            'Apply migration(s) ' . implode(', ', $missing) . ' before collecting all timing and EXPLAIN data.',
            'Telemetry schema incomplete.'
        );
    }

    $contexts = (int)($summary['contexts'] ?? 0);
    $samples = (int)($summary['samples'] ?? 0);
    $slowSamples = (int)($summary['slow_samples'] ?? 0);
    $slowRate = $samples > 0 ? ($slowSamples * 100 / $samples) : 0;
    echo '<div class="grid">';
    catalog_stat_card('Timing contexts', $contexts);
    catalog_stat_card('Recorded samples', $samples);
    catalog_stat_card('Samples ≥100 ms', $slowSamples, number_format($slowRate, 1) . '% of samples', $slowSamples > 0 ? 'warning' : '');
    catalog_stat_card('Maximum observed', exact_count_ms($summary['maximum_us'] ?? 0));
    catalog_stat_card('Plan contexts', (int)($planSummary['contexts'] ?? 0));
    catalog_stat_card('Plans to investigate', (int)($planSummary['investigate_total'] ?? 0), '', (int)($planSummary['investigate_total'] ?? 0) > 0 ? 'warning' : '');
    echo '</div>';

    echo '<div class="card"><h2>Collect evidence</h2>';
    echo '<p>The timing benchmark runs the representative read-only count queries. EXPLAIN capture asks MySQL for their access plans without executing the count result. Neither action changes package, dependency, job or federation data.</p>';
    echo '<div class="button-row">';
    echo '<form method="post" data-ui-loading-form><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('exact-count-telemetry')) . '">';
    echo '<button name="action" value="run"' . (!$telemetryAvailable ? ' disabled' : '') . '>Run exact-count benchmark</button> ';
    echo '<span data-ui-loading-indicator>' . CatalogUi::loadingState('Running count queries…', true) . '</span></form>';
    echo '<form method="post" data-ui-loading-form><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('exact-count-telemetry')) . '">';
    echo '<button name="action" value="capture_plans"' . (!$plansAvailable ? ' disabled' : '') . '>Capture EXPLAIN plans</button> ';
    echo '<span data-ui-loading-indicator>' . CatalogUi::loadingState('Capturing query plans…', true) . '</span></form>';
    echo '</div></div>';

    echo '<div class="card"><h2>Filters</h2><form method="get">';
    echo '<label>Metric <input name="metric" value="' . catalog_h($metricFilter) . '" placeholder="missing, game_files, background_jobs"></label> ';
    echo '<label>Minimum average <input type="number" min="0" max="60000" step="1" name="minimum_ms" value="' . catalog_h((string)$minimumMs) . '"> ms</label> ';
    echo '<button>Apply</button> <a class="button secondary" href="query-telemetry.php">Clear filters</a></form></div>';

    echo '<div class="card"><h2>Timing results</h2>';
    echo '<p class="muted">A sample is marked slow at ' . exact_count_ms(CatalogExactCountTelemetry::SLOW_THRESHOLD_US) . '. Results are sorted by average duration.</p>';
    if ($rows === []) {
        echo '<p class="muted">No matching timing telemetry has been recorded.</p>';
    } else {
        echo '<div class="table-wrap"><table><tr><th>Metric / context</th><th>Samples</th><th>Average</th><th>Maximum</th><th>Latest</th><th>Slow</th><th>Last total</th><th>Last seen</th><th>Assessment</th></tr>';
        foreach ($rows as $row) {
            $sampleCount = max(1, (int)$row['sample_count']);
            $averageMs = (float)$row['average_ms'];
            $maximumMs = (float)$row['max_duration_us'] / 1000;
            $rowSlowRate = (int)$row['slow_sample_count'] * 100 / $sampleCount;
            [$label, $tone] = exact_count_tone($averageMs, $maximumMs, $rowSlowRate);
            $context = json_decode((string)$row['context_json'], true);
            $contextText = is_array($context)
                ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string)$row['context_json'];
            echo '<tr><td><strong class="mono">' . catalog_h($row['metric_key']) . '</strong><br><span class="muted small mono path">' . catalog_h($contextText) . '</span></td>';
            echo '<td>' . (int)$row['sample_count'] . '</td>';
            echo '<td class="nowrap">' . catalog_h(number_format($averageMs, 3) . ' ms') . '</td>';
            echo '<td class="nowrap">' . catalog_h(exact_count_ms($row['max_duration_us'])) . '</td>';
            echo '<td class="nowrap">' . catalog_h(exact_count_ms($row['last_duration_us'])) . '</td>';
            echo '<td>' . (int)$row['slow_sample_count'] . '<br><span class="muted small">' . number_format($rowSlowRate, 1) . '%</span></td>';
            echo '<td>' . number_format((int)$row['last_result_count']) . '</td>';
            echo '<td class="nowrap">' . catalog_h($row['last_seen_at']) . '</td>';
            echo '<td>' . CatalogUi::badge($label, $tone) . '</td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div>';

    echo '<div class="card"><h2>EXPLAIN plans</h2>';
    echo '<p class="muted">Plan warnings are evidence to inspect, not automatic index instructions. A schema change should require both a concerning plan and repeated timing samples of at least 100 ms.</p>';
    if ($planRows === []) {
        echo '<p class="muted">No matching query plans have been captured.</p>';
    } else {
        echo '<div class="table-wrap"><table><tr><th>Metric / context</th><th>Plan</th><th>Estimated rows</th><th>Full-scan rows</th><th>Selected keys</th><th>Extra</th><th>Timing evidence</th><th>Recommendation</th><th>Captured</th></tr>';
        foreach ($planRows as $row) {
            $context = json_decode((string)$row['context_json'], true);
            $contextText = is_array($context)
                ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string)$row['context_json'];
            $averageMs = isset($row['average_ms']) ? (float)$row['average_ms'] : 0.0;
            $timingText = (int)($row['timing_samples'] ?? 0) > 0
                ? number_format($averageMs, 3) . ' ms avg / ' . (int)$row['timing_samples'] . ' sample(s)'
                : 'No matching timing sample';
            echo '<tr><td><strong class="mono">' . catalog_h($row['metric_key']) . '</strong><br><span class="muted small mono path">' . catalog_h($contextText) . '</span>';
            echo '<details><summary class="small">SQL / raw plan</summary><pre class="mono small">' . catalog_h($row['query_sql']) . "\n\n" . catalog_h($row['plan_json']) . '</pre></details></td>';
            echo '<td>' . exact_plan_badge((string)$row['assessment']) . '<br><span class="muted small">' . catalog_h($row['access_types']) . '</span>';
            if (trim((string)$row['error_message']) !== '') {
                echo '<br><span class="small">' . catalog_h($row['error_message']) . '</span>';
            }
            echo '</td>';
            echo '<td>' . number_format((int)$row['estimated_rows']) . '</td>';
            echo '<td>' . number_format((int)$row['full_scan_rows']) . '</td>';
            echo '<td class="mono small path">' . catalog_h($row['selected_keys'] !== '' ? $row['selected_keys'] : 'none') . '</td>';
            echo '<td class="small path">' . catalog_h($row['extra_flags'] !== '' ? $row['extra_flags'] : 'none') . '</td>';
            echo '<td class="nowrap">' . catalog_h($timingText) . '</td>';
            echo '<td class="small path">' . catalog_h($row['recommendation']) . '</td>';
            echo '<td class="nowrap">' . catalog_h($row['captured_at']) . '</td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div>';

    if ($lastRun !== []) {
        echo '<div class="card"><h2>Most recent benchmark run</h2><div class="table-wrap"><table><tr><th>Metric</th><th>Context</th><th>Result</th><th>Duration</th><th>Recorded</th></tr>';
        foreach ($lastRun as $sample) {
            $contextText = json_encode($sample['context'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo '<tr><td class="mono">' . catalog_h($sample['metric_key'] ?? '') . '</td>';
            echo '<td class="mono small path">' . catalog_h($contextText) . '</td>';
            echo '<td>' . number_format((int)($sample['result'] ?? 0)) . '</td>';
            echo '<td class="nowrap">' . catalog_h(number_format((float)($sample['duration_ms'] ?? 0), 3) . ' ms') . '</td>';
            echo '<td>' . (!empty($sample['recorded']) ? 'yes' : 'no') . '</td></tr>';
        }
        echo '</table></div></div>';
    }

    if ($lastPlans !== []) {
        echo '<div class="card"><h2>Most recent plan capture</h2><p>Captured ' . count($lastPlans) . ' representative plan(s); '
            . count(array_filter($lastPlans, static fn(array $row): bool => (string)($row['assessment'] ?? '') === 'investigate'))
            . ' require investigation and '
            . count(array_filter($lastPlans, static fn(array $row): bool => (string)($row['assessment'] ?? '') === 'error'))
            . ' returned an EXPLAIN error.</p></div>';
    }

    echo '<details class="card"><summary><strong>Retention</strong></summary><div style="padding-top:12px">';
    echo '<form method="post" style="display:inline-block;margin-right:16px" onsubmit="return confirm(\'Delete stale exact-count timing and plan telemetry?\')">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('exact-count-telemetry')) . '">';
    echo '<label>Delete contexts not captured for <input type="number" name="days" min="1" max="3650" value="90"> days</label> ';
    echo '<button name="action" value="prune">Prune stale telemetry</button></form>';
    echo '<form method="post" style="display:inline-block" onsubmit="return confirm(\'Clear all exact-count timing and plan telemetry?\')">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('exact-count-telemetry')) . '">';
    echo '<button class="danger" name="action" value="clear">Clear all telemetry</button></form></div></details>';

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB exact-count telemetry][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Exact Count Telemetry Error');
    }
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'Exact-count telemetry failed.');
    catalog_foot();
}
