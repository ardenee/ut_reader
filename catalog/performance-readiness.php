<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

catalog_start_session();

/** @return array<string,mixed> */
function performance_readiness_row(PDO $db, string $sql, array $args = []): array
{
    try {
        return catalog_one($db, $sql, $args) ?? [];
    } catch (Throwable) {
        return [];
    }
}

/** @return list<array<string,mixed>> */
function performance_readiness_rows(PDO $db, string $sql, array $args = []): array
{
    try {
        return catalog_all($db, $sql, $args);
    } catch (Throwable) {
        return [];
    }
}

function performance_readiness_table_exists(PDO $db, string $table): bool
{
    $row = performance_readiness_row(
        $db,
        'SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
        [$table]
    );
    return (int)($row['c'] ?? 0) > 0;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Performance Readiness')) {
        exit;
    }

    $flash = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        catalog_check_csrf('performance-readiness');
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'sync_job_search') {
            $flash = catalog_performance_sync_job_search($db, 10000)
                ? 'Background-job search projection synchronised.'
                : 'Background-job search projection could not be synchronised. Apply pending migrations first.';
        } elseif ($action === 'prune_count_cache') {
            $deleted = $db->exec('DELETE FROM ue_exact_count_cache WHERE expires_at<CURRENT_TIMESTAMP');
            $flash = number_format(max(0, (int)$deleted)) . ' expired count-cache row(s) removed.';
        } elseif ($action === 'clear_count_cache') {
            $deleted = $db->exec('DELETE FROM ue_exact_count_cache');
            $flash = number_format(max(0, (int)$deleted)) . ' count-cache row(s) removed.';
        } elseif ($action === 'clear_request_metrics') {
            $deleted = $db->exec('DELETE FROM ue_request_performance');
            $flash = number_format(max(0, (int)$deleted)) . ' request-performance row(s) removed.';
        }
    }

    $requiredTables = [
        'ue_game_catalog_stats',
        'ue_search_documents',
        'ue_dependency_package_summaries',
        'ue_exact_count_telemetry',
        'ue_exact_count_query_plans',
        'ue_exact_count_cache',
        'ue_background_job_search',
        'ue_request_performance',
    ];
    $tableStatus = [];
    foreach ($requiredTables as $table) {
        $tableStatus[$table] = performance_readiness_table_exists($db, $table);
    }
    $readyCount = count(array_filter($tableStatus));

    $cache = performance_readiness_row(
        $db,
        'SELECT COUNT(*) rows_total,COALESCE(SUM(hit_count),0) hits,'
        . 'SUM(expires_at<CURRENT_TIMESTAMP) expired FROM ue_exact_count_cache'
    );
    $jobProjection = performance_readiness_row(
        $db,
        'SELECT (SELECT COUNT(*) FROM ue_background_jobs) source_rows,'
        . '(SELECT COUNT(*) FROM ue_background_job_search) projected_rows,'
        . '(SELECT COUNT(*) FROM ue_background_job_search s JOIN ue_background_jobs j ON j.id=s.job_id '
        . 'WHERE s.source_updated_at<j.updated_at) stale_rows'
    );
    $requestMetrics = performance_readiness_row(
        $db,
        'SELECT COUNT(*) routes,COALESCE(SUM(sample_count),0) samples,COALESCE(MAX(max_duration_us),0) max_us '
        . 'FROM ue_request_performance'
    );

    $confirmedCounts = performance_readiness_rows(
        $db,
        'SELECT t.metric_key,t.context_json,t.sample_count,'
        . 'ROUND(t.total_duration_us/GREATEST(t.sample_count,1)/1000,2) average_ms,'
        . 'ROUND(t.max_duration_us/1000,2) maximum_ms,p.assessment,p.selected_keys,p.extra_flags,p.recommendation '
        . 'FROM ue_exact_count_telemetry t JOIN ue_exact_count_query_plans p '
        . 'ON p.metric_key=t.metric_key AND p.context_hash=t.context_hash '
        . 'WHERE (t.total_duration_us/GREATEST(t.sample_count,1))>=100000 '
        . 'AND p.assessment IN ("watch","investigate") '
        . 'ORDER BY average_ms DESC,p.full_scan_rows DESC LIMIT 30'
    );
    $slowRoutes = performance_readiness_rows(
        $db,
        'SELECT route_key,method,sample_count,'
        . 'ROUND(total_duration_us/GREATEST(sample_count,1)/1000,2) average_ms,'
        . 'ROUND(total_sql_us/GREATEST(sample_count,1)/1000,2) average_sql_ms,'
        . 'ROUND(max_duration_us/1000,2) maximum_ms,slow_sample_count,last_query_count,last_status,last_seen_at '
        . 'FROM ue_request_performance ORDER BY average_ms DESC,max_duration_us DESC LIMIT 30'
    );

    catalog_head('Performance Readiness');
    echo CatalogUi::pageHeader(
        'Performance Readiness',
        'Final verification for query projections, exact-count remediation and real page-time diagnostics.',
        ['Exact Count Telemetry' => 'query-telemetry.php', 'Background Jobs' => 'background-jobs.php']
    );
    catalog_flash($flash);

    echo '<div class="grid">';
    catalog_stat_card('Required performance tables', $readyCount . ' / ' . count($requiredTables), $readyCount === count($requiredTables) ? 'All required migrations are present.' : 'Run pending migrations before relying on projections.');
    catalog_stat_card('Count-cache rows', number_format((int)($cache['rows_total'] ?? 0)), number_format((int)($cache['hits'] ?? 0)) . ' recorded hits; ' . number_format((int)($cache['expired'] ?? 0)) . ' expired.');
    catalog_stat_card('Job-search projection', number_format((int)($jobProjection['projected_rows'] ?? 0)), number_format((int)($jobProjection['source_rows'] ?? 0)) . ' authoritative jobs; ' . number_format((int)($jobProjection['stale_rows'] ?? 0)) . ' stale.');
    catalog_stat_card('Measured routes', number_format((int)($requestMetrics['routes'] ?? 0)), number_format((int)($requestMetrics['samples'] ?? 0)) . ' requests; maximum ' . number_format(((int)($requestMetrics['max_us'] ?? 0)) / 1000, 2) . ' ms.');
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Migration and projection checks</h2><p>Every row below must be ready before the performance phase is considered deployed.</p></div></div><div class="ui-section__body">';
    echo '<table><thead><tr><th>Component</th><th>Status</th></tr></thead><tbody>';
    foreach ($tableStatus as $table => $ready) {
        echo '<tr><td class="mono">' . catalog_h($table) . '</td><td>' . CatalogUi::badge($ready ? 'ready' : 'missing', $ready ? 'success' : 'danger') . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<div class="button-row">';
    foreach ([
        'sync_job_search' => 'Synchronise job search',
        'prune_count_cache' => 'Prune expired counts',
        'clear_count_cache' => 'Clear count cache',
        'clear_request_metrics' => 'Reset page timing',
    ] as $action => $label) {
        echo '<form method="post" style="display:inline-block;margin-right:8px"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('performance-readiness')) . '"><input type="hidden" name="action" value="' . catalog_h($action) . '"><button type="submit" class="button secondary">' . catalog_h($label) . '</button></form>';
    }
    echo '</div></div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Confirmed slow exact counts</h2><p>Only rows where repeated timing and the captured plan both indicate a problem are shown.</p></div></div><div class="ui-section__body">';
    if ($confirmedCounts === []) {
        echo '<p class="muted">No exact-count query currently meets both remediation thresholds.</p>';
    } else {
        echo '<div class="ui-table-region"><table><thead><tr><th>Metric</th><th>Samples</th><th>Average</th><th>Maximum</th><th>Plan</th><th>Selected keys / flags</th><th>Action</th></tr></thead><tbody>';
        foreach ($confirmedCounts as $row) {
            echo '<tr><td><strong>' . catalog_h((string)$row['metric_key']) . '</strong><br><span class="mono small">' . catalog_h((string)$row['context_json']) . '</span></td>';
            echo '<td>' . number_format((int)$row['sample_count']) . '</td><td>' . catalog_h((string)$row['average_ms']) . ' ms</td><td>' . catalog_h((string)$row['maximum_ms']) . ' ms</td>';
            echo '<td>' . CatalogUi::badge((string)$row['assessment'], (string)$row['assessment'] === 'investigate' ? 'danger' : 'warning') . '</td>';
            echo '<td class="mono small">' . catalog_h(trim((string)$row['selected_keys']) !== '' ? (string)$row['selected_keys'] : 'no selected key') . '<br>' . catalog_h((string)$row['extra_flags']) . '</td>';
            echo '<td class="small">' . catalog_h((string)$row['recommendation']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Page-time diagnostics</h2><p>Application and SQL time are aggregated by route; individual requests are not retained.</p></div></div><div class="ui-section__body">';
    if ($slowRoutes === []) {
        echo '<p class="muted">No page timing samples have been collected yet. Browse the site normally and return here.</p>';
    } else {
        echo '<div class="ui-table-region"><table><thead><tr><th>Route</th><th>Method</th><th>Samples</th><th>Average</th><th>Average SQL</th><th>Maximum</th><th>Slow</th><th>Last query count</th><th>Last status</th><th>Last seen</th></tr></thead><tbody>';
        foreach ($slowRoutes as $row) {
            echo '<tr><td class="mono">' . catalog_h((string)$row['route_key']) . '</td><td>' . catalog_h((string)$row['method']) . '</td><td>' . number_format((int)$row['sample_count']) . '</td>';
            echo '<td>' . catalog_h((string)$row['average_ms']) . ' ms</td><td>' . catalog_h((string)$row['average_sql_ms']) . ' ms</td><td>' . catalog_h((string)$row['maximum_ms']) . ' ms</td>';
            echo '<td>' . number_format((int)$row['slow_sample_count']) . '</td><td>' . number_format((int)$row['last_query_count']) . '</td><td>' . (int)$row['last_status'] . '</td><td>' . catalog_h((string)$row['last_seen_at']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] performance readiness failed: ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Performance Readiness Error');
    }
    echo CatalogUi::alert('danger', 'Performance readiness could not be loaded.', catalog_public_error_message());
    catalog_foot();
}
