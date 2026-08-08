<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders Performance Readiness and accepts administrator maintenance actions.
 * Why: Projection synchronisation, cache cleanup, schema probes and telemetry SQL now belong to a diagnostics service.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Diagnostics\CatalogPerformanceReadinessService;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Performance Readiness')) {
        exit;
    }

    $service = new CatalogPerformanceReadinessService($db);
    $flash = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        catalog_check_csrf('performance-readiness');
        $flash = $service->handleAction(trim((string)($_POST['action'] ?? '')));
    }

    $snapshot = $service->snapshot();
    $tableStatus = $snapshot['table_status'];
    $readyCount = $snapshot['ready_count'];
    $requiredCount = $snapshot['required_count'];
    $cache = $snapshot['cache'];
    $jobProjection = $snapshot['job_projection'];
    $requestMetrics = $snapshot['request_metrics'];
    $confirmedCounts = $snapshot['confirmed_counts'];
    $slowRoutes = $snapshot['slow_routes'];

    catalog_head('Performance Readiness');
    echo CatalogUi::pageHeader(
        'Performance Readiness',
        'Final verification for query projections, exact-count remediation and real page-time diagnostics.',
        ['Exact Count Telemetry' => 'query-telemetry.php', 'Background Jobs' => 'background-jobs.php']
    );
    catalog_flash($flash);

    echo '<div class="grid">';
    catalog_stat_card('Required performance tables', $readyCount . ' / ' . $requiredCount, $readyCount === $requiredCount ? 'All required migrations are present.' : 'Run pending migrations before relying on projections.');
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
