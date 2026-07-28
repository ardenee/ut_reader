<?php
declare(strict_types=1);

/**
 * Low-overhead request resource tracing layered on top of CatalogPerformance.
 * Normal requests are sampled; slow, CPU-heavy and high-memory requests are retained.
 */

function catalog_resource_trace_cpu_us(): int
{
    if (!function_exists('getrusage')) {
        return 0;
    }
    $usage = getrusage();
    if (!is_array($usage)) {
        return 0;
    }
    $user = ((int)($usage['ru_utime.tv_sec'] ?? 0) * 1000000) + (int)($usage['ru_utime.tv_usec'] ?? 0);
    $system = ((int)($usage['ru_stime.tv_sec'] ?? 0) * 1000000) + (int)($usage['ru_stime.tv_usec'] ?? 0);
    return max(0, $user + $system);
}

function catalog_resource_trace_route_key(): string
{
    $route = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? 'cli'));
    $parts = [];
    foreach (['page', 'action', 'tab'] as $key) {
        $value = trim((string)($_POST[$key] ?? $_GET[$key] ?? ''));
        if ($value !== '' && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $value) === 1) {
            $parts[] = $key . '=' . strtolower($value);
        }
    }
    if ($parts !== []) {
        $route .= '?' . implode('&', $parts);
    }
    return substr($route, 0, 190);
}

function catalog_resource_trace_register(): void
{
    if (isset($GLOBALS['catalog_resource_trace_state'])) {
        return;
    }
    $GLOBALS['catalog_resource_trace_state'] = [
        'started_ns' => hrtime(true),
        'started_cpu_us' => catalog_resource_trace_cpu_us(),
        'started_memory' => memory_get_usage(true),
        'finished' => false,
    ];
    register_shutdown_function('catalog_resource_trace_finish');
}

function catalog_resource_trace_finish(): void
{
    $trace =& $GLOBALS['catalog_resource_trace_state'];
    if (!is_array($trace) || !empty($trace['finished'])) {
        return;
    }
    $trace['finished'] = true;

    $performance = $GLOBALS['catalog_performance_state'] ?? [];
    $elapsedUs = (int)round(max(0, hrtime(true) - (int)$trace['started_ns']) / 1000);
    $sqlUs = (int)round(max(0, (int)($performance['query_ns'] ?? 0)) / 1000);
    $cpuUs = max(0, catalog_resource_trace_cpu_us() - (int)$trace['started_cpu_us']);
    $peakMemory = max(0, memory_get_peak_usage(true));
    $memoryDelta = max(0, memory_get_usage(true) - (int)$trace['started_memory']);
    $queryCount = max(0, (int)($performance['query_count'] ?? 0));
    $route = catalog_resource_trace_route_key();
    $method = substr(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'CLI')), 0, 10);
    $status = function_exists('http_response_code') ? (int)http_response_code() : 0;
    $audience = session_status() === PHP_SESSION_ACTIVE && (($_SESSION['user']['role'] ?? '') === 'admin')
        ? 'admin'
        : (PHP_SAPI === 'cli' ? 'cli' : 'public');
    $slowThresholdUs = max(250000, (int)(getenv('UNREALDB_SLOW_REQUEST_MS') ?: 1000) * 1000);
    $cpuThresholdUs = max(100000, (int)(getenv('UNREALDB_HIGH_CPU_REQUEST_MS') ?: 500) * 1000);
    $memoryThreshold = max(32 * 1024 * 1024, (int)(getenv('UNREALDB_HIGH_MEMORY_REQUEST_MB') ?: 128) * 1024 * 1024);
    $slow = $elapsedUs >= $slowThresholdUs || $cpuUs >= $cpuThresholdUs || $peakMemory >= $memoryThreshold;
    $sample = $slow || random_int(1, 20) === 1;

    $db = $performance['db'] ?? null;
    if ($sample && $db instanceof PDO && !$db->inTransaction()) {
        try {
            $statement = $db->prepare(
                'INSERT INTO ue_request_resource_performance('
                . 'route_key,method,audience,sample_count,total_duration_us,total_sql_us,total_cpu_us,max_duration_us,max_sql_us,max_cpu_us,'
                . 'total_peak_memory_bytes,max_peak_memory_bytes,last_duration_us,last_sql_us,last_cpu_us,last_peak_memory_bytes,'
                . 'last_memory_delta_bytes,last_query_count,last_status,slow_sample_count,last_slowest_query_hash,last_seen_at) '
                . 'VALUES(?,?,?,1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) '
                . 'ON DUPLICATE KEY UPDATE sample_count=sample_count+1,total_duration_us=total_duration_us+VALUES(total_duration_us),'
                . 'total_sql_us=total_sql_us+VALUES(total_sql_us),total_cpu_us=total_cpu_us+VALUES(total_cpu_us),'
                . 'max_duration_us=GREATEST(max_duration_us,VALUES(max_duration_us)),max_sql_us=GREATEST(max_sql_us,VALUES(max_sql_us)),'
                . 'max_cpu_us=GREATEST(max_cpu_us,VALUES(max_cpu_us)),total_peak_memory_bytes=total_peak_memory_bytes+VALUES(total_peak_memory_bytes),'
                . 'max_peak_memory_bytes=GREATEST(max_peak_memory_bytes,VALUES(max_peak_memory_bytes)),last_duration_us=VALUES(last_duration_us),'
                . 'last_sql_us=VALUES(last_sql_us),last_cpu_us=VALUES(last_cpu_us),last_peak_memory_bytes=VALUES(last_peak_memory_bytes),'
                . 'last_memory_delta_bytes=VALUES(last_memory_delta_bytes),last_query_count=VALUES(last_query_count),last_status=VALUES(last_status),'
                . 'slow_sample_count=slow_sample_count+VALUES(slow_sample_count),last_slowest_query_hash=VALUES(last_slowest_query_hash),'
                . 'last_seen_at=CURRENT_TIMESTAMP'
            );
            $statement->execute([
                $route,
                $method,
                $audience,
                $elapsedUs,
                $sqlUs,
                $cpuUs,
                $elapsedUs,
                $sqlUs,
                $cpuUs,
                $peakMemory,
                $peakMemory,
                $elapsedUs,
                $sqlUs,
                $cpuUs,
                $peakMemory,
                $memoryDelta,
                $queryCount,
                $status,
                $slow ? 1 : 0,
                substr((string)($performance['slowest_query_hash'] ?? ''), 0, 64),
            ]);
        } catch (Throwable) {
            // The migration may not be applied yet; tracing must remain optional.
        }
    }

    if (!headers_sent()) {
        header('X-UnrealDB-CPU-ms: ' . number_format($cpuUs / 1000, 2, '.', ''));
        header('X-UnrealDB-Peak-Memory: ' . $peakMemory);
    }

    if ($slow) {
        $requestId = function_exists('catalog_request_id') ? catalog_request_id() : '-';
        error_log('[UnrealDB resource] request=' . $requestId
            . ' route=' . $route
            . ' audience=' . $audience
            . ' elapsed_ms=' . number_format($elapsedUs / 1000, 2, '.', '')
            . ' sql_ms=' . number_format($sqlUs / 1000, 2, '.', '')
            . ' cpu_ms=' . number_format($cpuUs / 1000, 2, '.', '')
            . ' peak_memory_mb=' . number_format($peakMemory / 1048576, 2, '.', '')
            . ' queries=' . $queryCount
            . ' slowest_query_hash=' . (string)($performance['slowest_query_hash'] ?? ''));
    }
}

catalog_resource_trace_register();
