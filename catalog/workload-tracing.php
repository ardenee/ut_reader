<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

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

/** @return array<string,string> */
function workload_mysql_map(PDO $db, string $kind, array $names): array
{
    if ($names === []) {
        return [];
    }
    $quoted = implode(',', array_map(static fn(string $name): string => $db->quote($name), array_values($names)));
    $rows = catalog_all($db, 'SHOW GLOBAL ' . $kind . ' WHERE Variable_name IN (' . $quoted . ')');
    $result = [];
    foreach ($rows as $row) {
        $result[(string)$row['Variable_name']] = (string)$row['Value'];
    }
    return $result;
}

/** @return array{files:int,bytes:int,oldest:int,newest:int} */
function workload_public_cache_stats(array $config): array
{
    $directory = function_exists('catalog_public_cache_directory')
        ? catalog_public_cache_directory($config)
        : '';
    $stats = ['files' => 0, 'bytes' => 0, 'oldest' => 0, 'newest' => 0];
    if ($directory === '' || !is_dir($directory)) {
        return $stats;
    }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if (!$entry instanceof SplFileInfo || !$entry->isFile() || !str_ends_with($entry->getFilename(), '.htmlcache')) {
            continue;
        }
        $stats['files']++;
        $stats['bytes'] += max(0, $entry->getSize());
        $mtime = $entry->getMTime();
        $stats['oldest'] = $stats['oldest'] === 0 ? $mtime : min($stats['oldest'], $mtime);
        $stats['newest'] = max($stats['newest'], $mtime);
    }
    return $stats;
}

function workload_prune_public_cache(array $config): int
{
    $directory = function_exists('catalog_public_cache_directory')
        ? catalog_public_cache_directory($config)
        : '';
    if ($directory === '' || !is_dir($directory)) {
        return 0;
    }
    $removed = 0;
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
            continue;
        }
        if (str_ends_with($entry->getFilename(), '.htmlcache') || str_ends_with($entry->getFilename(), '.lock')) {
            if (@unlink($entry->getPathname())) {
                $removed++;
            }
        }
    }
    return $removed;
}

function workload_target(array $config, string $key, int $default): int
{
    $performance = is_array($config['performance'] ?? null) ? $config['performance'] : [];
    return max(0, (int)($performance[$key] ?? $default));
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Workload Tracing')) {
        exit;
    }

    $message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('workload-tracing');
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        if ($action === 'reset_application_trace') {
            $db->exec('TRUNCATE TABLE ue_request_resource_performance');
            $db->exec('TRUNCATE TABLE ue_request_performance');
            $message = 'Application request tracing counters were reset.';
        } elseif ($action === 'clear_public_cache') {
            $removed = workload_prune_public_cache($config);
            $message = 'Removed ' . $removed . ' public response cache file(s).';
        } else {
            throw new RuntimeException('Unknown workload tracing action.');
        }
    }

    $variableNames = [
        'version',
        'innodb_buffer_pool_size',
        'innodb_buffer_pool_dump_at_shutdown',
        'innodb_buffer_pool_load_at_startup',
        'innodb_buffer_pool_dump_pct',
        'innodb_redo_log_capacity',
        'max_connections',
        'thread_cache_size',
        'table_open_cache',
        'tmp_table_size',
        'max_heap_table_size',
        'slow_query_log',
        'long_query_time',
        'min_examined_row_limit',
        'performance_schema',
        'innodb_flush_log_at_trx_commit',
        'sync_binlog',
    ];
    $statusNames = [
        'Threads_connected',
        'Threads_running',
        'Threads_created',
        'Max_used_connections',
        'Questions',
        'Slow_queries',
        'Created_tmp_tables',
        'Created_tmp_disk_tables',
        'Innodb_buffer_pool_read_requests',
        'Innodb_buffer_pool_reads',
        'Innodb_buffer_pool_wait_free',
        'Bytes_received',
        'Bytes_sent',
        'Uptime',
    ];
    $variables = workload_mysql_map($db, 'VARIABLES', $variableNames);
    $status = workload_mysql_map($db, 'STATUS', $statusNames);

    $routes = [];
    try {
        $routes = catalog_all(
            $db,
            'SELECT route_key,method,audience,sample_count,total_duration_us,total_sql_us,total_cpu_us,'
            . 'max_duration_us,max_sql_us,max_cpu_us,total_peak_memory_bytes,max_peak_memory_bytes,'
            . 'last_duration_us,last_sql_us,last_cpu_us,last_peak_memory_bytes,last_memory_delta_bytes,'
            . 'last_query_count,last_status,slow_sample_count,last_slowest_query_hash,last_seen_at '
            . 'FROM ue_request_resource_performance ORDER BY total_cpu_us DESC,total_duration_us DESC LIMIT 100'
        );
    } catch (Throwable) {
        // Migration not applied yet.
    }

    $digests = [];
    $digestError = '';
    try {
        $digestColumnRows = catalog_all(
            $db,
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA="performance_schema" AND TABLE_NAME="events_statements_summary_by_digest" '
            . 'AND COLUMN_NAME IN ("SUM_CPU_TIME","MAX_TOTAL_MEMORY")'
        );
        $digestColumns = array_fill_keys(array_map(
            static fn(array $row): string => strtoupper((string)$row['COLUMN_NAME']),
            $digestColumnRows
        ), true);
        $cpuSelect = isset($digestColumns['SUM_CPU_TIME'])
            ? 'ROUND(SUM_CPU_TIME/1000000000000,3) cpu_seconds,'
            : 'NULL cpu_seconds,';
        $memorySelect = isset($digestColumns['MAX_TOTAL_MEMORY'])
            ? 'MAX_TOTAL_MEMORY maximum_memory_bytes,'
            : 'NULL maximum_memory_bytes,';
        $digests = catalog_all(
            $db,
            'SELECT LEFT(DIGEST_TEXT,1000) digest_text,COUNT_STAR execution_count,'
            . 'ROUND(SUM_TIMER_WAIT/1000000000000,3) total_seconds,'
            . $cpuSelect
            . 'ROUND(AVG_TIMER_WAIT/1000000000,3) average_ms,'
            . 'ROUND(MAX_TIMER_WAIT/1000000000,3) maximum_ms,'
            . $memorySelect
            . 'SUM_ROWS_EXAMINED rows_examined,SUM_ROWS_SENT rows_sent,'
            . 'SUM_CREATED_TMP_DISK_TABLES disk_tmp_tables,SUM_NO_INDEX_USED no_index_used '
            . 'FROM performance_schema.events_statements_summary_by_digest '
            . 'WHERE SCHEMA_NAME=DATABASE() AND DIGEST_TEXT IS NOT NULL '
            . 'ORDER BY SUM_TIMER_WAIT DESC LIMIT 50'
        );
    } catch (Throwable $error) {
        $digestError = $error->getMessage();
    }

    $cacheStats = workload_public_cache_stats($config);
    $bufferRequests = (int)($status['Innodb_buffer_pool_read_requests'] ?? 0);
    $bufferReads = (int)($status['Innodb_buffer_pool_reads'] ?? 0);
    $bufferHit = $bufferRequests > 0
        ? max(0, 100 - (($bufferReads * 100) / $bufferRequests))
        : 0;
    $tmpTables = (int)($status['Created_tmp_tables'] ?? 0);
    $diskTmpTables = (int)($status['Created_tmp_disk_tables'] ?? 0);

    $targetBuffer = workload_target($config, 'mysql_buffer_pool_bytes', 36 * 1024 * 1024 * 1024);
    $targetConnections = workload_target($config, 'mysql_max_connections', 120);
    $targetThreadCache = workload_target($config, 'mysql_thread_cache_size', 50);
    $targetApacheThreads = workload_target($config, 'apache_threads_per_child', 100);
    $targetOpcacheMemory = workload_target($config, 'opcache_memory_mb', 256);
    $targetOpcacheFiles = workload_target($config, 'opcache_max_accelerated_files', 32531);

    $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
    $opcacheConfig = function_exists('opcache_get_configuration') ? @opcache_get_configuration() : false;
    $opcacheDirectives = is_array($opcacheConfig) && is_array($opcacheConfig['directives'] ?? null)
        ? $opcacheConfig['directives']
        : [];

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
    echo '<tr><td class="mono">opcache.memory_consumption</td><td>' . catalog_h((string)($opcacheDirectives['opcache.memory_consumption'] ?? 'unavailable')) . '</td><td>' . $targetOpcacheMemory . ' MiB</td><td><span class="pill amber">verify php.ini</span></td></tr>';
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
