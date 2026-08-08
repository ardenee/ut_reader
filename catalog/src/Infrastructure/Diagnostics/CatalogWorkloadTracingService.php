<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns workload-tracing actions, MySQL/runtime probes, application route telemetry and cache inspection.
 * Why: Operational SQL, Performance Schema compatibility probing and filesystem cache maintenance do not belong in Presentation.
 * Role: Infrastructure diagnostics/application service.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Diagnostics;

use FilesystemIterator;
use PDO;
use SplFileInfo;
use Throwable;

final class CatalogWorkloadTracingService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
    }

    public function handleAction(string $action): string
    {
        if ($action === 'reset_application_trace') {
            $this->db->exec('DELETE FROM ue_request_resource_performance');
            $this->db->exec('DELETE FROM ue_request_performance');
            return 'Application request tracing counters were reset.';
        }
        if ($action === 'clear_public_cache') {
            return 'Removed ' . $this->prunePublicCache() . ' public response cache file(s).';
        }
        throw new \RuntimeException('Unknown workload tracing action.');
    }

    /**
     * @return array{
     *   variables:array<string,string>,status:array<string,string>,routes:list<array<string,mixed>>,
     *   digests:list<array<string,mixed>>,digest_error:string,cache:array{files:int,bytes:int,oldest:int,newest:int},
     *   buffer_hit:float,tmp_tables:int,disk_tmp_tables:int,targets:array<string,int>,
     *   opcache:array<string,mixed>|false,opcache_directives:array<string,mixed>
     * }
     */
    public function snapshot(): array
    {
        $variables = $this->mysqlMap('VARIABLES', [
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
        ]);
        $status = $this->mysqlMap('STATUS', [
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
        ]);

        $routes = [];
        try {
            $routes = \catalog_all(
                $this->db,
                'SELECT route_key,method,audience,sample_count,total_duration_us,total_sql_us,total_cpu_us,'
                . 'max_duration_us,max_sql_us,max_cpu_us,total_peak_memory_bytes,max_peak_memory_bytes,'
                . 'last_duration_us,last_sql_us,last_cpu_us,last_peak_memory_bytes,last_memory_delta_bytes,'
                . 'last_query_count,last_status,slow_sample_count,last_slowest_query_hash,last_seen_at '
                . 'FROM ue_request_resource_performance ORDER BY total_cpu_us DESC,total_duration_us DESC LIMIT 100'
            );
        } catch (Throwable) {
            // Telemetry migration may not be applied yet.
        }

        [$digests, $digestError] = $this->statementDigests();
        $cache = $this->publicCacheStats();
        $bufferRequests = (int)($status['Innodb_buffer_pool_read_requests'] ?? 0);
        $bufferReads = (int)($status['Innodb_buffer_pool_reads'] ?? 0);
        $bufferHit = $bufferRequests > 0
            ? max(0, 100 - (($bufferReads * 100) / $bufferRequests))
            : 0.0;
        $tmpTables = (int)($status['Created_tmp_tables'] ?? 0);
        $diskTmpTables = (int)($status['Created_tmp_disk_tables'] ?? 0);

        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $opcacheConfig = function_exists('opcache_get_configuration') ? @opcache_get_configuration() : false;
        $opcacheDirectives = is_array($opcacheConfig) && is_array($opcacheConfig['directives'] ?? null)
            ? $opcacheConfig['directives']
            : [];

        return [
            'variables' => $variables,
            'status' => $status,
            'routes' => $routes,
            'digests' => $digests,
            'digest_error' => $digestError,
            'cache' => $cache,
            'buffer_hit' => $bufferHit,
            'tmp_tables' => $tmpTables,
            'disk_tmp_tables' => $diskTmpTables,
            'targets' => [
                'mysql_buffer_pool_bytes' => $this->target('mysql_buffer_pool_bytes', 36 * 1024 * 1024 * 1024),
                'mysql_max_connections' => $this->target('mysql_max_connections', 120),
                'mysql_thread_cache_size' => $this->target('mysql_thread_cache_size', 50),
                'apache_threads_per_child' => $this->target('apache_threads_per_child', 100),
                'opcache_memory_mb' => $this->target('opcache_memory_mb', 256),
                'opcache_max_accelerated_files' => $this->target('opcache_max_accelerated_files', 32531),
            ],
            'opcache' => is_array($opcache) ? $opcache : false,
            'opcache_directives' => $opcacheDirectives,
        ];
    }

    /** @return array<string,string> */
    private function mysqlMap(string $kind, array $names): array
    {
        if ($names === []) {
            return [];
        }
        $quoted = implode(',', array_map(
            fn(string $name): string => $this->db->quote($name),
            array_values($names)
        ));
        $rows = \catalog_all($this->db, 'SHOW GLOBAL ' . $kind . ' WHERE Variable_name IN (' . $quoted . ')');
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['Variable_name']] = (string)$row['Value'];
        }
        return $result;
    }

    /** @return array{0:list<array<string,mixed>>,1:string} */
    private function statementDigests(): array
    {
        try {
            $digestColumnRows = \catalog_all(
                $this->db,
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
            $rows = \catalog_all(
                $this->db,
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
            return [$rows, ''];
        } catch (Throwable $error) {
            return [[], $error->getMessage()];
        }
    }

    /** @return array{files:int,bytes:int,oldest:int,newest:int} */
    private function publicCacheStats(): array
    {
        $directory = function_exists('catalog_public_cache_directory')
            ? \catalog_public_cache_directory($this->config)
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

    private function prunePublicCache(): int
    {
        $directory = function_exists('catalog_public_cache_directory')
            ? \catalog_public_cache_directory($this->config)
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

    private function target(string $key, int $default): int
    {
        $performance = is_array($this->config['performance'] ?? null) ? $this->config['performance'] : [];
        return max(0, (int)($performance[$key] ?? $default));
    }
}
