<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns performance-readiness maintenance actions and diagnostic snapshot queries.
 * Why: Projection synchronisation, cache cleanup, schema probes and telemetry reads should not live in Presentation.
 * Role: Infrastructure diagnostics/application service; all actions retain existing operational semantics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Diagnostics;

use PDO;

final class CatalogPerformanceReadinessService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
    }

    public function handleAction(string $action): string
    {
        if ($action === 'sync_job_search') {
            return \catalog_performance_sync_job_search($this->db, 10000)
                ? 'Background-job search projection synchronised.'
                : 'Background-job search projection could not be synchronised. Apply pending migrations first.';
        }
        if ($action === 'prune_count_cache') {
            $deleted = $this->db->exec('DELETE FROM ue_exact_count_cache WHERE expires_at<CURRENT_TIMESTAMP');
            return number_format(max(0, (int)$deleted)) . ' expired count-cache row(s) removed.';
        }
        if ($action === 'clear_count_cache') {
            $deleted = $this->db->exec('DELETE FROM ue_exact_count_cache');
            return number_format(max(0, (int)$deleted)) . ' count-cache row(s) removed.';
        }
        if ($action === 'clear_request_metrics') {
            $deleted = $this->db->exec('DELETE FROM ue_request_performance');
            return number_format(max(0, (int)$deleted)) . ' request-performance row(s) removed.';
        }
        return '';
    }

    /**
     * @return array{
     *   table_status:array<string,bool>,ready_count:int,required_count:int,
     *   cache:array<string,mixed>,job_projection:array<string,mixed>,request_metrics:array<string,mixed>,
     *   confirmed_counts:list<array<string,mixed>>,slow_routes:list<array<string,mixed>>
     * }
     */
    public function snapshot(): array
    {
        $requiredTables = [
            'ue_game_catalog_stats',
            'ue_dependency_package_summaries',
            'ue_exact_count_telemetry',
            'ue_exact_count_query_plans',
            'ue_exact_count_cache',
            'ue_background_job_search',
            'ue_request_performance',
        ];
        $tableStatus = [];
        foreach ($requiredTables as $table) {
            $tableStatus[$table] = $this->tableExists($table);
        }

        return [
            'table_status' => $tableStatus,
            'ready_count' => count(array_filter($tableStatus)),
            'required_count' => count($requiredTables),
            'cache' => $this->row(
                'SELECT COUNT(*) rows_total,COALESCE(SUM(hit_count),0) hits,'
                . 'SUM(expires_at<CURRENT_TIMESTAMP) expired FROM ue_exact_count_cache'
            ),
            'job_projection' => $this->row(
                'SELECT (SELECT COUNT(*) FROM ue_background_jobs) source_rows,'
                . '(SELECT COUNT(*) FROM ue_background_job_search) projected_rows,'
                . '(SELECT COUNT(*) FROM ue_background_job_search s JOIN ue_background_jobs j ON j.id=s.job_id '
                . 'WHERE s.source_updated_at<j.updated_at) stale_rows'
            ),
            'request_metrics' => $this->row(
                'SELECT COUNT(*) routes,COALESCE(SUM(sample_count),0) samples,COALESCE(MAX(max_duration_us),0) max_us '
                . 'FROM ue_request_performance'
            ),
            'confirmed_counts' => $this->rows(
                'SELECT t.metric_key,t.context_json,t.sample_count,'
                . 'ROUND(t.total_duration_us/GREATEST(t.sample_count,1)/1000,2) average_ms,'
                . 'ROUND(t.max_duration_us/1000,2) maximum_ms,p.assessment,p.selected_keys,p.extra_flags,p.recommendation '
                . 'FROM ue_exact_count_telemetry t JOIN ue_exact_count_query_plans p '
                . 'ON p.metric_key=t.metric_key AND p.context_hash=t.context_hash '
                . 'WHERE (t.total_duration_us/GREATEST(t.sample_count,1))>=100000 '
                . 'AND p.assessment IN ("watch","investigate") '
                . 'ORDER BY average_ms DESC,p.full_scan_rows DESC LIMIT 30'
            ),
            'slow_routes' => $this->rows(
                'SELECT route_key,method,sample_count,'
                . 'ROUND(total_duration_us/GREATEST(sample_count,1)/1000,2) average_ms,'
                . 'ROUND(total_sql_us/GREATEST(sample_count,1)/1000,2) average_sql_ms,'
                . 'ROUND(max_duration_us/1000,2) maximum_ms,slow_sample_count,last_query_count,last_status,last_seen_at '
                . 'FROM ue_request_performance ORDER BY average_ms DESC,max_duration_us DESC LIMIT 30'
            ),
        ];
    }

    private function tableExists(string $table): bool
    {
        $row = $this->row(
            'SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
            [$table]
        );
        return (int)($row['c'] ?? 0) > 0;
    }

    /** @return array<string,mixed> */
    private function row(string $sql, array $args = []): array
    {
        try {
            return \catalog_one($this->db, $sql, $args) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $args = []): array
    {
        try {
            return \catalog_all($this->db, $sql, $args);
        } catch (\Throwable) {
            return [];
        }
    }
}
