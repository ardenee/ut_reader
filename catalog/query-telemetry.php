<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/FederationBaseGamePolicy.php';

use UnrealDb\Catalog\Application\Telemetry\CatalogExactCountBenchmark;
use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;
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

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Exact Count Telemetry')) {
        exit;
    }

    $schema = new SchemaInspector($db);
    $available = $schema->tableExists('ue_exact_count_telemetry');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('exact-count-telemetry');
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        if (!$available) {
            throw new RuntimeException('Apply migration 202607270012 before collecting exact-count telemetry.');
        }

        if ($action === 'run') {
            @set_time_limit(0);
            $samples = CatalogExactCountBenchmark::run($db);
            $_SESSION['exact_count_last_run'] = $samples;
            $_SESSION['exact_count_flash'] = 'Recorded ' . count($samples) . ' representative exact-count sample(s).';
        } elseif ($action === 'prune') {
            $days = max(1, min(3650, (int)($_POST['days'] ?? 90)));
            $statement = $db->prepare(
                'DELETE FROM ue_exact_count_telemetry WHERE last_seen_at<DATE_SUB(NOW(),INTERVAL ? DAY)'
            );
            $statement->execute([$days]);
            $_SESSION['exact_count_flash'] = 'Removed ' . $statement->rowCount() . ' telemetry context(s) older than ' . $days . ' day(s).';
        } elseif ($action === 'clear') {
            $removed = $db->exec('DELETE FROM ue_exact_count_telemetry');
            $_SESSION['exact_count_last_run'] = [];
            $_SESSION['exact_count_flash'] = 'Cleared ' . (int)$removed . ' exact-count telemetry context(s).';
        } else {
            throw new RuntimeException('Unknown exact-count telemetry action.');
        }

        header('Location: query-telemetry.php');
        exit;
    }

    $metricFilter = substr(strtolower(trim((string)($_GET['metric'] ?? ''))), 0, 120);
    $minimumMs = max(0, min(60000, (float)($_GET['minimum_ms'] ?? 0)));
    $where = [];
    $args = [];
    if ($metricFilter !== '') {
        $where[] = 'metric_key LIKE ?';
        $args[] = '%' . $metricFilter . '%';
    }
    if ($minimumMs > 0) {
        $where[] = '(total_duration_us/GREATEST(sample_count,1))/1000>=?';
        $args[] = $minimumMs;
    }
    $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

    $summary = $available ? catalog_one(
        $db,
        'SELECT COUNT(*) contexts,COALESCE(SUM(sample_count),0) samples,'
        . 'COALESCE(SUM(slow_sample_count),0) slow_samples,COALESCE(MAX(max_duration_us),0) maximum_us '
        . 'FROM ue_exact_count_telemetry'
    ) : null;
    $rows = $available ? catalog_all(
        $db,
        'SELECT metric_key,context_json,sample_count,total_duration_us,max_duration_us,last_duration_us,'
        . 'slow_sample_count,last_result_count,first_seen_at,last_seen_at,'
        . '(total_duration_us/GREATEST(sample_count,1))/1000 average_ms '
        . 'FROM ue_exact_count_telemetry' . $whereSql
        . ' ORDER BY average_ms DESC,max_duration_us DESC,last_seen_at DESC LIMIT 500',
        $args
    ) : [];
    $lastRun = is_array($_SESSION['exact_count_last_run'] ?? null)
        ? $_SESSION['exact_count_last_run']
        : [];

    catalog_head('Exact Count Telemetry');
    catalog_flash($_SESSION['exact_count_flash'] ?? null);
    unset($_SESSION['exact_count_flash']);
    catalog_page_header(
        'Exact Count Telemetry',
        'Measure exact total-count queries before deciding whether any displayed totals require caching or approximation.',
        ['Background Jobs' => 'background-jobs.php', 'Missing Dependencies' => 'missing.php']
    );

    if (!$available) {
        echo CatalogUi::alert(
            'warning',
            'Migration 202607270012 has not been applied. Run the migration before collecting samples.',
            'Telemetry table unavailable.'
        );
        catalog_foot();
        exit;
    }

    $contexts = (int)($summary['contexts'] ?? 0);
    $samples = (int)($summary['samples'] ?? 0);
    $slowSamples = (int)($summary['slow_samples'] ?? 0);
    $slowRate = $samples > 0 ? ($slowSamples * 100 / $samples) : 0;
    echo '<div class="grid">';
    catalog_stat_card('Metric contexts', $contexts);
    catalog_stat_card('Recorded samples', $samples);
    catalog_stat_card('Samples ≥100 ms', $slowSamples, number_format($slowRate, 1) . '% of samples', $slowSamples > 0 ? 'warning' : '');
    catalog_stat_card('Maximum observed', exact_count_ms($summary['maximum_us'] ?? 0));
    echo '</div>';

    echo '<div class="card"><h2>Collect representative samples</h2>';
    echo '<p>The benchmark runs read-only exact totals against the live catalogue. It does not alter package, dependency, job or federation data. The only writes are compact aggregate telemetry updates.</p>';
    echo '<form method="post" data-ui-loading-form><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('exact-count-telemetry')) . '">';
    echo '<button name="action" value="run">Run exact-count benchmark</button> ';
    echo '<span data-ui-loading-indicator>' . CatalogUi::loadingState('Running count queries…', true) . '</span></form></div>';

    echo '<div class="card"><h2>Filters</h2><form method="get">';
    echo '<label>Metric <input name="metric" value="' . catalog_h($metricFilter) . '" placeholder="missing, game_files, background_jobs"></label> ';
    echo '<label>Minimum average <input type="number" min="0" max="60000" step="1" name="minimum_ms" value="' . catalog_h((string)$minimumMs) . '"> ms</label> ';
    echo '<button>Apply</button> <a class="button secondary" href="query-telemetry.php">Clear filters</a></form></div>';

    echo '<div class="card"><h2>Aggregate results</h2>';
    echo '<p class="muted">A sample is marked slow at ' . exact_count_ms(CatalogExactCountTelemetry::SLOW_THRESHOLD_US) . '. Results are sorted by average duration.</p>';
    if ($rows === []) {
        echo '<p class="muted">No matching telemetry has been recorded. Run the benchmark to collect the first samples.</p>';
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

    echo '<details class="card"><summary><strong>Retention</strong></summary><div style="padding-top:12px">';
    echo '<form method="post" style="display:inline-block;margin-right:16px" onsubmit="return confirm(\'Delete stale exact-count telemetry?\')">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('exact-count-telemetry')) . '">';
    echo '<label>Delete contexts not sampled for <input type="number" name="days" min="1" max="3650" value="90"> days</label> ';
    echo '<button name="action" value="prune">Prune stale telemetry</button></form>';
    echo '<form method="post" style="display:inline-block" onsubmit="return confirm(\'Clear all exact-count telemetry?\')">';
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
