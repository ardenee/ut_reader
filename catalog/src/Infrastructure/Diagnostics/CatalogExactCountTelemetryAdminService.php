<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns exact-count benchmark/plan actions, retention mutations, schema availability and telemetry reads.
 * Why: Diagnostic persistence and query construction should not live in the Exact Count Telemetry rendering page.
 * Role: Infrastructure diagnostics/application service over the existing exact-count telemetry components.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Diagnostics;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogExactCountBenchmark;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogExactCountPlanCapture;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogExactCountQueryCatalog;

final class CatalogExactCountTelemetryAdminService
{
    private readonly SchemaInspector $schema;

    public function __construct(private readonly PDO $db)
    {
        $this->schema = new SchemaInspector($db);
    }

    /** @return array{telemetry:bool,plans:bool} */
    public function availability(): array
    {
        return [
            'telemetry' => $this->schema->tableExists('ue_exact_count_telemetry'),
            'plans' => $this->schema->tableExists('ue_exact_count_query_plans'),
        ];
    }

    /**
     * @return array{flash:string,last_run:list<array<string,mixed>>,last_plans:list<array<string,mixed>>,clear_last:bool}
     */
    public function handleAction(string $action, int $days = 90): array
    {
        $available = $this->availability();
        $lastRun = [];
        $lastPlans = [];
        $clearLast = false;

        if ($action === 'run') {
            if (!$available['telemetry']) {
                throw new RuntimeException('Apply migration 202607270012 before collecting exact-count telemetry.');
            }
            @set_time_limit(0);
            $lastRun = CatalogExactCountBenchmark::run($this->db);
            return [
                'flash' => 'Recorded ' . count($lastRun) . ' representative exact-count sample(s).',
                'last_run' => $lastRun,
                'last_plans' => [],
                'clear_last' => false,
            ];
        }

        if ($action === 'capture_plans') {
            if (!$available['plans']) {
                throw new RuntimeException('Apply migration 202607270013 before capturing exact-count query plans.');
            }
            @set_time_limit(0);
            $lastPlans = CatalogExactCountPlanCapture::capture(
                $this->db,
                CatalogExactCountQueryCatalog::definitions($this->db)
            );
            $errors = count(array_filter(
                $lastPlans,
                static fn(array $row): bool => (string)($row['assessment'] ?? '') === 'error'
            ));
            return [
                'flash' => 'Captured ' . count($lastPlans) . ' EXPLAIN plan(s)'
                    . ($errors > 0 ? '; ' . $errors . ' could not be explained.' : '.'),
                'last_run' => [],
                'last_plans' => $lastPlans,
                'clear_last' => false,
            ];
        }

        if ($action === 'prune') {
            $days = max(1, min(3650, $days));
            $removedTelemetry = 0;
            $removedPlans = 0;
            if ($available['telemetry']) {
                $statement = $this->db->prepare(
                    'DELETE FROM ue_exact_count_telemetry WHERE last_seen_at<DATE_SUB(NOW(),INTERVAL ? DAY)'
                );
                $statement->execute([$days]);
                $removedTelemetry = $statement->rowCount();
            }
            if ($available['plans']) {
                $statement = $this->db->prepare(
                    'DELETE FROM ue_exact_count_query_plans WHERE captured_at<DATE_SUB(NOW(),INTERVAL ? DAY)'
                );
                $statement->execute([$days]);
                $removedPlans = $statement->rowCount();
            }
            return [
                'flash' => 'Removed ' . $removedTelemetry . ' timing context(s) and '
                    . $removedPlans . ' plan context(s) older than ' . $days . ' day(s).',
                'last_run' => [],
                'last_plans' => [],
                'clear_last' => false,
            ];
        }

        if ($action === 'clear') {
            $removedTelemetry = $available['telemetry']
                ? (int)$this->db->exec('DELETE FROM ue_exact_count_telemetry')
                : 0;
            $removedPlans = $available['plans']
                ? (int)$this->db->exec('DELETE FROM ue_exact_count_query_plans')
                : 0;
            $clearLast = true;
            return [
                'flash' => 'Cleared ' . $removedTelemetry . ' timing context(s) and '
                    . $removedPlans . ' plan context(s).',
                'last_run' => [],
                'last_plans' => [],
                'clear_last' => $clearLast,
            ];
        }

        throw new RuntimeException('Unknown exact-count telemetry action.');
    }

    /**
     * @return array{
     *   availability:array{telemetry:bool,plans:bool},summary:?array<string,mixed>,rows:list<array<string,mixed>>,
     *   plan_summary:?array<string,mixed>,plan_rows:list<array<string,mixed>>
     * }
     */
    public function snapshot(string $metricFilter, float $minimumMs): array
    {
        $available = $this->availability();
        $metricFilter = substr(strtolower(trim($metricFilter)), 0, 120);
        $minimumMs = max(0, min(60000, $minimumMs));

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

        $planWhere = [];
        $planArgs = [];
        if ($metricFilter !== '') {
            $planWhere[] = 'p.metric_key LIKE ?';
            $planArgs[] = '%' . $metricFilter . '%';
        }
        if ($minimumMs > 0) {
            $planWhere[] = 'COALESCE((t.total_duration_us/GREATEST(t.sample_count,1))/1000,0)>=?';
            $planArgs[] = $minimumMs;
        }
        $planWhereSql = $planWhere !== [] ? ' WHERE ' . implode(' AND ', $planWhere) : '';

        return [
            'availability' => $available,
            'summary' => $available['telemetry'] ? $this->one(
                'SELECT COUNT(*) contexts,COALESCE(SUM(sample_count),0) samples,'
                . 'COALESCE(SUM(slow_sample_count),0) slow_samples,COALESCE(MAX(max_duration_us),0) maximum_us '
                . 'FROM ue_exact_count_telemetry'
            ) : null,
            'rows' => $available['telemetry'] ? $this->all(
                'SELECT metric_key,context_hash,context_json,sample_count,total_duration_us,max_duration_us,last_duration_us,'
                . 'slow_sample_count,last_result_count,first_seen_at,last_seen_at,'
                . '(total_duration_us/GREATEST(sample_count,1))/1000 average_ms '
                . 'FROM ue_exact_count_telemetry' . $whereSql
                . ' ORDER BY average_ms DESC,max_duration_us DESC,last_seen_at DESC LIMIT 500',
                $args
            ) : [],
            'plan_summary' => $available['plans'] ? $this->one(
                'SELECT COUNT(*) contexts,'
                . 'SUM(assessment="investigate") investigate_total,SUM(assessment="watch") watch_total,'
                . 'SUM(assessment="error") error_total,COALESCE(MAX(estimated_rows),0) maximum_rows '
                . 'FROM ue_exact_count_query_plans'
            ) : null,
            'plan_rows' => $available['plans'] ? $this->all(
                'SELECT p.*,t.sample_count timing_samples,'
                . '(t.total_duration_us/GREATEST(t.sample_count,1))/1000 average_ms,t.max_duration_us timing_max_us '
                . 'FROM ue_exact_count_query_plans p '
                . 'LEFT JOIN ue_exact_count_telemetry t ON t.metric_key=p.metric_key AND t.context_hash=p.context_hash'
                . $planWhereSql
                . ' ORDER BY CASE p.assessment WHEN "error" THEN 0 WHEN "investigate" THEN 1 '
                . 'WHEN "watch" THEN 2 ELSE 3 END,p.full_scan_rows DESC,p.estimated_rows DESC,p.captured_at DESC LIMIT 500',
                $planArgs
            ) : [],
        ];
    }

    /** @return array<string,mixed>|null */
    private function one(string $sql, array $args = []): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function all(string $sql, array $args = []): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
