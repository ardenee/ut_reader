<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders Workload Tracing and accepts administrator maintenance actions.
 * Why: MySQL/Performance Schema probes, route telemetry, cache filesystem inspection and reset actions now live in a diagnostics service.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Diagnostics\CatalogWorkloadTracingService;

function workload_ms(int|float $microseconds): string
{
    return number_format(((float)$microseconds) / 1000, 2) . ' ms';
}

function workload_ratio(int|float $numerator, int|float $denominator): string
{
    if ((float)$denominator <= 0) {
        return 'n/a';
    }
    return number_format(((float)$numerator * 100) / (float)$denominator, 2) . '%';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Workload Tracing')) {
        exit;
    }

    $service = new CatalogWorkloadTracingService($db, $config);
    $message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('workload-tracing');
        $message = $service->handleAction(strtolower(trim((string)($_POST['action'] ?? ''))));
    }

    $snapshot = $service->snapshot();
    $variables = $snapshot['variables'];
    $status = $snapshot['status'];
    $routes = $snapshot['routes'];
    $digests = $snapshot['digests'];
    $digestError = $snapshot['digest_error'];
    $cacheStats = $snapshot['cache'];
    $bufferHit = $snapshot['buffer_hit'];
    $tmpTables = $snapshot['tmp_tables'];
    $diskTmpTables = $snapshot['disk_tmp_tables'];
    $targets = $snapshot['targets'];
    $opcache = $snapshot['opcache'];
    $opcacheDirectives = $snapshot['opcache_directives'];

    $targetBuffer = $targets['mysql_buffer_pool_bytes'];
    $targetConnections = $targets['mysql_max_connections'];
    $targetThreadCache = $targets['mysql_thread_cache_size'];
    $targetApacheThreads = $targets['apache_threads_per_child'];
    $targetOpcacheMemory = $targets['opcache_memory_mb'];
    $targetOpcacheFiles = $targets['opcache_max_accelerated_files'];

    catalog_head('Workload Tracing');
    echo CatalogUi::pageHeader(
        'Workload Tracing',
        'Identify public pages, actions and MySQL statement families consuming the most elapsed time, SQL time, CPU and peak PHP memory.',
        ['Performance Readiness' => 'performance-readiness.php', 'Exact Count Telemetry' => 'query-telemetry.php']
    );
    catalog_flash($message);

    echo '<div class="grid">';
    catalog_stat_card('MySQL buffer pool', catalog_bytes((int)($variables['innodb_buffer_pool_size'] ?? 0)), 'Target ' . catalog_bytes($targetBuffer));
    catalog_stat_card('Buffer-pool hit rate', number_format($bufferHit, 3) . '%', 'Higher is better after warm-up', $bufferHit >= 99.5 ? 'good' : 'attention');
    catalog_stat_card('Threads running', (int)($status['Threads_running'] ?? 0), 'Connected ' . (int)($status['Threads_connected'] ?? 0));
    catalog_stat_card('Peak MySQL connections', (int)($status['Max_used_connections'] ?? 0), 'Configured ' . (int)($variables['max_connections'] ?? 0));
    catalog_stat_card('Disk temporary tables', $diskTmpTables, $tmpTables > 0 ? workload_ratio($diskTmpTables, $tmpTables) . ' of temporary tables' : 'No temporary tables recorded');
    catalog_stat_card('Public response cache', $cacheStats['files'], catalog_bytes($cacheStats['bytes']));
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Current configuration against the 64 GB Windows target</h2>'
        . '<p>These targets leave RAM for Apache/PHP, Windows and filesystem caching while giving MySQL enough room for the expected catalogue size.</p></div></div><div class="ui-section__body">';
    echo '<table><thead><tr><th>Setting</th><th>Current</th><th>Target</th><th>Assessment</th></tr></thead><tbody>';
    $checks = [
        ['innodb_buffer_pool_size', catalog_bytes((int)($variables['innodb_buffer_pool_size'] ?? 0)), catalog_bytes($targetBuffer), abs((int)($variables['innodb_buffer_pool_size'] ?? 0) - $targetBuffer) <= 2 * 1024 * 1024 * 1024],
        ['max_connections', (string)($variables['max_connections'] ?? 'unknown'), (string)$targetConnections, (int)($variables['max_connections'] ?? 0) === $targetConnections],
        ['thread_cache_size', (string)($variables['thread_cache_size'] ?? 'unknown'), (string)$targetThreadCache, (int)($variables['thread_cache_size'] ?? 0) >= $targetThreadCache],
        ['innodb_buffer_pool_dump_at_shutdown', (string)($variables['innodb_buffer_pool_dump_at_shutdown'] ?? 'unknown'), 'ON', strtoupper((string)($variables['innodb_buffer_pool_dump_at_shutdown'] ?? '')) === 'ON'],
        ['innodb_buffer_pool_load_at_startup', (string)($variables['innodb_buffer_pool_load_at_startup'] ?? 'unknown'), 'ON', strtoupper((string)($variables['innodb_buffer_pool_load_at_startup'] ?? '')) === 'ON'],
        ['slow_query_log', (string)($variables['slow_query_log'] ?? 'unknown'), 'ON', strtoupper((string)($variables['slow_query_log'] ?? '')) === 'ON'],
        ['long_query_time', (string)($variables['long_query_time'] ?? 'unknown'), '0.5', (float)($variables['long_query_time'] ?? 99) <= 0.5],
        ['performance_schema', (string)($variables['performance_schema'] ?? 'unknown'), 'ON', strtoupper((string)($variables['performance_schema'] ?? '')) === 'ON'],
    ];
    foreach ($checks as [$name, $current, $target, $good]) {
        echo '<tr><td class="mono">' . catalog_h($name) . '</td><td>' . catalog_h($current) . '</td><td>' . catalog_h($target) . '</td><td><span class="pill ' . ($good ? 'green' : 'amber') . '">' . ($good ? 'ready' : 'change') . '</span></td></tr>';
    }
    echo '<tr><td class="mono">Apache ThreadsPerChild</td><td>Read from httpd.conf</td><td>' . $targetApacheThreads . '</td><td><span class="pill amber">manual check</span></td></tr>';
    $opcacheMemory = isset($opcacheDirectives['opcache.memory_consumption'])
        ? catalog_bytes((int)$opcacheDirectives['opcache.memory_consumption'])
        : 'unavailable';
    echo '<tr><td class="mono">opcache.memory_consumption</td><td>' . catalog_h($opcacheMemory) . '</td><td>' . $targetOpcacheMemory . ' MiB</td><td><span class="pill amber">verify php.ini</span></td></tr>';
    echo '<tr><td class="mono">opcache.max_accelerated_files</td><td>' . catalog_h((string)($opcacheDirectives['opcache.max_accelerated_files'] ?? 'unavailable')) . '</td><td>' . $targetOpcacheFiles . '</td><td><span class="pill amber">verify php.ini</span></td></tr>';
    echo '</tbody></table></div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Application routes by sampled CPU</h2>'
        . '<p>Normal requests are sampled 1 in 20. Slow, CPU-heavy or high-memory requests are always retained. Query parameters such as file IDs and search terms are not stored.</p></div></div><div class="ui-section__body">';
    if ($routes === []) {
        echo CatalogUi::emptyState('No route traces yet', 'Apply migration 202607280001, then use the site normally to collect samples.');
    } else {
        echo '<table><thead><tr><th>Route/action</th><th>Audience</th><th>Samples</th><th>Average wall</th><th>Average SQL</th><th>Average CPU</th><th>Maximum CPU</th><th>Maximum peak RAM</th><th>Last queries</th><th>Slow samples</th><th>Last seen</th></tr></thead><tbody>';
        foreach ($routes as $row) {
            $samples = max(1, (int)$row['sample_count']);
            echo '<tr><td class="mono small">' . catalog_h((string)$row['route_key']) . '<br><span class="muted">' . catalog_h((string)$row['method']) . '</span></td>';
            echo '<td>' . catalog_h((string)$row['audience']) . '</td><td>' . number_format($samples) . '</td>';
            echo '<td>' . workload_ms((int)$row['total_duration_us'] / $samples) . '</td>';
            echo '<td>' . workload_ms((int)$row['total_sql_us'] / $samples) . '</td>';
            echo '<td>' . workload_ms((int)$row['total_cpu_us'] / $samples) . '</td>';
            echo '<td>' . workload_ms((int)$row['max_cpu_us']) . '</td>';
            echo '<td>' . catalog_h(catalog_bytes((int)$row['max_peak_memory_bytes'])) . '</td>';
            echo '<td>' . (int)$row['last_query_count'] . '</td><td>' . (int)$row['slow_sample_count'] . '</td><td>' . catalog_h((string)$row['last_seen_at']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>MySQL statement families by total execution time</h2>'
        . '<p>These are normalized digests maintained by MySQL Performance Schema since the last MySQL restart or digest reset. Literal parameter values are not displayed.</p></div></div><div class="ui-section__body">';
    if ($digests === []) {
        echo CatalogUi::emptyState('MySQL statement digests unavailable', $digestError !== '' ? $digestError : 'Enable performance_schema and grant access to its digest summary.');
    } else {
        echo '<table><thead><tr><th>Normalized SQL digest</th><th>Executions</th><th>Total</th><th>CPU</th><th>Average</th><th>Maximum</th><th>Max DB memory</th><th>Rows examined</th><th>Rows sent</th><th>Disk temp</th><th>No index</th></tr></thead><tbody>';
        foreach ($digests as $row) {
            echo '<tr><td class="mono small" style="white-space:normal;overflow-wrap:anywhere">' . catalog_h((string)$row['digest_text']) . '</td>';
            echo '<td>' . number_format((int)$row['execution_count']) . '</td><td>' . catalog_h((string)$row['total_seconds']) . ' s</td>';
            echo '<td>' . ($row['cpu_seconds'] !== null ? catalog_h((string)$row['cpu_seconds']) . ' s' : '<span class="muted">unsupported</span>') . '</td>';
            echo '<td>' . catalog_h((string)$row['average_ms']) . ' ms</td><td>' . catalog_h((string)$row['maximum_ms']) . ' ms</td>';
            echo '<td>' . ($row['maximum_memory_bytes'] !== null ? catalog_h(catalog_bytes((int)$row['maximum_memory_bytes'])) : '<span class="muted">unsupported</span>') . '</td>';
            echo '<td>' . number_format((int)$row['rows_examined']) . '</td><td>' . number_format((int)$row['rows_sent']) . '</td>';
            echo '<td>' . number_format((int)$row['disk_tmp_tables']) . '</td><td>' . number_format((int)$row['no_index_used']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>PHP and web runtime</h2></div></div><div class="ui-section__body"><table><tbody>';
    foreach ([
        'PHP version' => PHP_VERSION,
        'PHP SAPI' => PHP_SAPI,
        'PHP memory_limit' => (string)ini_get('memory_limit'),
        'OPcache enabled' => is_array($opcache) && !empty($opcache['opcache_enabled']) ? 'yes' : 'no',
        'OPcache used memory' => is_array($opcache) ? catalog_bytes((int)($opcache['memory_usage']['used_memory'] ?? 0)) : 'unavailable',
        'OPcache free memory' => is_array($opcache) ? catalog_bytes((int)($opcache['memory_usage']['free_memory'] ?? 0)) : 'unavailable',
        'Server software' => (string)($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
        'Current process peak memory' => catalog_bytes(memory_get_peak_usage(true)),
    ] as $label => $value) {
        echo '<tr><th>' . catalog_h($label) . '</th><td class="mono">' . catalog_h((string)$value) . '</td></tr>';
    }
    echo '</tbody></table></div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Maintenance actions</h2></div></div><div class="ui-section__body"><div class="button-row">';
    echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('workload-tracing')) . '"><input type="hidden" name="action" value="clear_public_cache"><button type="submit">Clear public response cache</button></form>';
    echo '<form method="post" onsubmit="return confirm(\'Reset all accumulated application tracing counters?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('workload-tracing')) . '"><input type="hidden" name="action" value="reset_application_trace"><button type="submit" class="secondary">Reset application traces</button></form>';
    echo '</div></div></section>';

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB workload tracing][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Workload Tracing Error');
    }
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'Workload tracing could not be loaded.');
    catalog_foot();
}
