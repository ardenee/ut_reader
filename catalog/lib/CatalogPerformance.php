<?php
declare(strict_types=1);

/**
 * Shared, bounded performance helpers. This file intentionally avoids the
 * application autoloader so CatalogSupportCore can load it during bootstrap.
 */

function catalog_performance_register(): void
{
    if (!isset($GLOBALS['catalog_performance_state'])) {
        $GLOBALS['catalog_performance_state'] = [
            'started_ns' => hrtime(true),
            'query_count' => 0,
            'query_ns' => 0,
            'slowest_query_ns' => 0,
            'slowest_query_hash' => '',
            'count_cache_hits' => 0,
            'count_cache_misses' => 0,
            'finished' => false,
            'db' => null,
        ];
        register_shutdown_function('catalog_performance_finish');
    }
}

function catalog_performance_remember_db(PDO $db): void
{
    catalog_performance_register();
    $GLOBALS['catalog_performance_state']['db'] = $db;
}

/** @param list<mixed> $args */
function catalog_performance_statement(PDO $db, string $sql, array $args = []): PDOStatement
{
    catalog_performance_remember_db($db);
    $started = hrtime(true);
    try {
        $statement = $db->prepare($sql);
        $statement->execute($args);
        return $statement;
    } finally {
        catalog_performance_record_query($sql, hrtime(true) - $started);
    }
}

function catalog_performance_record_query(string $sql, int $elapsedNs): void
{
    catalog_performance_register();
    $state =& $GLOBALS['catalog_performance_state'];
    $state['query_count']++;
    $state['query_ns'] += max(0, $elapsedNs);
    if ($elapsedNs > $state['slowest_query_ns']) {
        $state['slowest_query_ns'] = $elapsedNs;
        $state['slowest_query_hash'] = hash('sha256', catalog_performance_normalize_sql($sql));
    }
}

function catalog_performance_normalize_sql(string $sql): string
{
    return trim((string)(preg_replace('/\s+/', ' ', $sql) ?? $sql));
}

/** @param list<mixed> $args */
function catalog_performance_count(PDO $db, string $sql, array $args = []): int
{
    catalog_performance_remember_db($db);
    if ($db->inTransaction() || getenv('UNREALDB_COUNT_CACHE_DISABLED') === '1') {
        return catalog_performance_execute_count($db, $sql, $args);
    }

    $normalized = catalog_performance_normalize_sql($sql);
    if (!catalog_performance_count_cacheable($db, $normalized)) {
        return catalog_performance_execute_count($db, $sql, $args);
    }

    $argsJson = json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $queryHash = hash('sha256', $normalized);
    $cacheKey = hash('sha256', $queryHash . "\n" . ($argsJson !== false ? $argsJson : serialize($args)));

    try {
        $lookup = catalog_performance_statement(
            $db,
            'SELECT result_count FROM ue_exact_count_cache WHERE cache_key=? AND expires_at>CURRENT_TIMESTAMP LIMIT 1',
            [$cacheKey]
        );
        $cached = $lookup->fetchColumn();
        if ($cached !== false) {
            $GLOBALS['catalog_performance_state']['count_cache_hits']++;
            if (random_int(1, 25) === 1) {
                $touch = $db->prepare('UPDATE ue_exact_count_cache SET hit_count=hit_count+1,last_hit_at=CURRENT_TIMESTAMP WHERE cache_key=?');
                $touch->execute([$cacheKey]);
            }
            return (int)$cached;
        }
    } catch (Throwable) {
        return catalog_performance_execute_count($db, $sql, $args);
    }

    $GLOBALS['catalog_performance_state']['count_cache_misses']++;
    $count = catalog_performance_execute_count($db, $sql, $args);
    $ttl = catalog_performance_count_ttl($normalized);
    try {
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl);
        $write = $db->prepare(
            'INSERT INTO ue_exact_count_cache(cache_key,query_hash,result_count,expires_at,generated_at,hit_count,last_hit_at) '
            . 'VALUES(?,?,?,?,CURRENT_TIMESTAMP,0,NULL) '
            . 'ON DUPLICATE KEY UPDATE query_hash=VALUES(query_hash),result_count=VALUES(result_count),'
            . 'expires_at=VALUES(expires_at),generated_at=CURRENT_TIMESTAMP'
        );
        $write->execute([$cacheKey, $queryHash, $count, $expiresAt]);
        if (random_int(1, 50) === 1) {
            $db->exec('DELETE FROM ue_exact_count_cache WHERE expires_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 1 DAY) LIMIT 1000');
        }
    } catch (Throwable) {
        // The count result remains authoritative even if the optional cache write fails.
    }
    return $count;
}

/** @param list<mixed> $args */
function catalog_performance_execute_count(PDO $db, string $sql, array $args): int
{
    $statement = catalog_performance_statement($db, $sql, $args);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return (int)($row['c'] ?? 0);
}

function catalog_performance_count_cacheable(PDO $db, string $normalizedSql): bool
{
    if (stripos($normalizedSql, 'SELECT') !== 0
        || (stripos($normalizedSql, 'COUNT(') === false && stripos($normalizedSql, 'SUM(') === false)) {
        return false;
    }

    static $availability = null;
    if ($availability === null) {
        try {
            $db->query('SELECT 1 FROM ue_exact_count_cache LIMIT 0');
            $availability = true;
        } catch (Throwable) {
            $availability = false;
        }
    }
    if (!$availability) {
        return false;
    }

    $lower = strtolower($normalizedSql);
    foreach ([
        'ue_files',
        'ue_dependencies',
        'ue_dependency_package_summaries',
        'ue_background_jobs',
        'ue_federation_peer_files',
        'ue_federation_requests',
        'ue_federation_request_items',
        'ue_federation_transfer_jobs',
        'ue_federation_transfer_logs',
    ] as $largeTable) {
        if (str_contains($lower, $largeTable)) {
            return true;
        }
    }

    static $evidence = [];
    $queryHash = hash('sha256', $normalizedSql);
    if (array_key_exists($queryHash, $evidence)) {
        return $evidence[$queryHash];
    }
    try {
        $statement = $db->prepare(
            'SELECT p.assessment,t.sample_count,t.total_duration_us '
            . 'FROM ue_exact_count_query_plans p '
            . 'LEFT JOIN ue_exact_count_telemetry t ON t.metric_key=p.metric_key AND t.context_hash=p.context_hash '
            . 'WHERE p.query_hash=? ORDER BY p.captured_at DESC LIMIT 1'
        );
        $statement->execute([$queryHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $evidence[$queryHash] = false;
        }
        $samples = max(0, (int)($row['sample_count'] ?? 0));
        $averageUs = $samples > 0 ? (int)floor((int)($row['total_duration_us'] ?? 0) / $samples) : 0;
        return $evidence[$queryHash] = $averageUs >= 100000
            || in_array((string)($row['assessment'] ?? ''), ['watch', 'investigate'], true);
    } catch (Throwable) {
        return $evidence[$queryHash] = false;
    }
}

function catalog_performance_count_ttl(string $normalizedSql): int
{
    $lower = strtolower($normalizedSql);
    if (str_contains($lower, 'ue_background_jobs')) {
        return 10;
    }
    if (str_contains($lower, 'ue_federation_')) {
        return 30;
    }
    if (str_contains($lower, 'ue_dependencies') || str_contains($lower, 'ue_dependency_package_summaries')) {
        return 60;
    }
    if (str_contains($lower, 'ue_files')) {
        return 120;
    }
    return 60;
}

function catalog_performance_boolean_query(string $search): string
{
    $tokens = preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower(trim($search), 'UTF-8'));
    if (!is_array($tokens)) {
        return '';
    }
    $terms = [];
    foreach (array_values(array_unique(array_filter($tokens, static fn(string $token): bool => mb_strlen($token, 'UTF-8') >= 4))) as $token) {
        $terms[] = '+' . $token . '*';
        if (count($terms) >= 8) {
            break;
        }
    }
    return implode(' ', $terms);
}

function catalog_performance_sync_job_search(PDO $db, int $limit = 2000): bool
{
    $limit = max(100, min($limit, 10000));
    try {
        $watermarkStatement = catalog_performance_statement(
            $db,
            'SELECT COALESCE(MAX(source_updated_at),"1970-01-01 00:00:00") watermark FROM ue_background_job_search'
        );
        $watermark = (string)($watermarkStatement->fetchColumn() ?: '1970-01-01 00:00:00');
        $watermarkTime = strtotime($watermark . ' UTC');
        $threshold = gmdate('Y-m-d H:i:s', ($watermarkTime !== false ? $watermarkTime : 0) - 2);
        $sql = 'INSERT INTO ue_background_job_search(job_id,queue_name,job_type,source_status,search_text,source_updated_at) '
            . 'SELECT j.id,j.queue_name,j.job_type,j.status,LEFT(CONCAT_WS(" ",CAST(j.id AS CHAR),j.queue_name,j.job_type,'
            . 'COALESCE(j.concurrency_key,""),COALESCE(j.payload_json,""),COALESCE(j.last_error,""),COALESCE(j.result_json,"")),65535),j.updated_at '
            . 'FROM ue_background_jobs j LEFT JOIN ue_background_job_search s ON s.job_id=j.id '
            . 'WHERE j.updated_at>=? AND (s.job_id IS NULL OR s.source_updated_at<j.updated_at) '
            . 'ORDER BY j.updated_at ASC,j.id ASC LIMIT ' . $limit . ' '
            . 'ON DUPLICATE KEY UPDATE queue_name=VALUES(queue_name),job_type=VALUES(job_type),source_status=VALUES(source_status),'
            . 'search_text=VALUES(search_text),source_updated_at=VALUES(source_updated_at)';
        catalog_performance_statement($db, $sql, [$threshold]);
        if (random_int(1, 50) === 1) {
            $db->exec(
                'DELETE FROM ue_background_job_search WHERE job_id NOT IN '
                . '(SELECT id FROM ue_background_jobs) LIMIT 1000'
            );
        }
        return true;
    } catch (Throwable $error) {
        error_log('[UnrealDB performance] background job search projection unavailable: ' . $error->getMessage());
        return false;
    }
}

function catalog_performance_finish(): void
{
    if (!isset($GLOBALS['catalog_performance_state'])) {
        return;
    }
    $state =& $GLOBALS['catalog_performance_state'];
    if (!empty($state['finished'])) {
        return;
    }
    $state['finished'] = true;

    $elapsedNs = max(0, hrtime(true) - (int)$state['started_ns']);
    $elapsedUs = (int)round($elapsedNs / 1000);
    $sqlUs = (int)round((int)$state['query_ns'] / 1000);
    $route = substr((string)($_SERVER['SCRIPT_NAME'] ?? 'cli'), 0, 190);
    $method = substr(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'CLI')), 0, 10);
    $status = function_exists('http_response_code') ? (int)http_response_code() : 0;
    $slowThresholdUs = max(250000, (int)(getenv('UNREALDB_SLOW_REQUEST_MS') ?: 1000) * 1000);

    $db = $state['db'] ?? null;
    $persistSample = $elapsedUs >= $slowThresholdUs || random_int(1, 20) === 1;
    if ($db instanceof PDO && !$db->inTransaction() && $persistSample) {
        try {
            $statement = $db->prepare(
                'INSERT INTO ue_request_performance(route_key,method,sample_count,total_duration_us,total_sql_us,max_duration_us,max_sql_us,'
                . 'last_duration_us,last_sql_us,slow_sample_count,last_query_count,last_status,last_seen_at) '
                . 'VALUES(?,?,1,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) '
                . 'ON DUPLICATE KEY UPDATE sample_count=sample_count+1,total_duration_us=total_duration_us+VALUES(total_duration_us),'
                . 'total_sql_us=total_sql_us+VALUES(total_sql_us),max_duration_us=GREATEST(max_duration_us,VALUES(max_duration_us)),'
                . 'max_sql_us=GREATEST(max_sql_us,VALUES(max_sql_us)),last_duration_us=VALUES(last_duration_us),last_sql_us=VALUES(last_sql_us),'
                . 'slow_sample_count=slow_sample_count+VALUES(slow_sample_count),last_query_count=VALUES(last_query_count),'
                . 'last_status=VALUES(last_status),last_seen_at=CURRENT_TIMESTAMP'
            );
            $statement->execute([
                $route,
                $method,
                $elapsedUs,
                $sqlUs,
                $elapsedUs,
                $sqlUs,
                $elapsedUs,
                $sqlUs,
                $elapsedUs >= $slowThresholdUs ? 1 : 0,
                (int)$state['query_count'],
                $status,
            ]);
        } catch (Throwable) {
            // Diagnostics must never break the response.
        }
    }

    if (!headers_sent()) {
        header('Server-Timing: app;dur=' . number_format($elapsedUs / 1000, 2, '.', '')
            . ', db;dur=' . number_format($sqlUs / 1000, 2, '.', ''));
        header('X-UnrealDB-Query-Count: ' . (int)$state['query_count']);
        header('X-UnrealDB-Count-Cache: hit=' . (int)$state['count_cache_hits'] . '; miss=' . (int)$state['count_cache_misses']);
    }

    if ($elapsedUs >= $slowThresholdUs || $sqlUs >= 500000) {
        $requestId = function_exists('catalog_request_id') ? (string)catalog_request_id() : '';
        error_log('[UnrealDB performance] request=' . ($requestId !== '' ? $requestId : '-')
            . ' route=' . $route
            . ' method=' . $method
            . ' status=' . $status
            . ' elapsed_ms=' . number_format($elapsedUs / 1000, 2, '.', '')
            . ' sql_ms=' . number_format($sqlUs / 1000, 2, '.', '')
            . ' queries=' . (int)$state['query_count']
            . ' count_cache_hits=' . (int)$state['count_cache_hits']
            . ' count_cache_misses=' . (int)$state['count_cache_misses']
            . ' slowest_query_hash=' . (string)$state['slowest_query_hash']);
    }
}

catalog_performance_register();
