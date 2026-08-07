<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogExactCountPlanCapture` for catalog exact count plan capture.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Telemetry;

use PDO;
use Throwable;

/** Captures one bounded EXPLAIN snapshot per exact-count metric/context. */
final class CatalogExactCountPlanCapture
{
    /**
     * @param list<array{metric_key:string,label:string,context:array<string,mixed>,sql:string,args:list<mixed>}> $definitions
     * @return list<array<string,mixed>>
     */
    public static function capture(PDO $db, array $definitions): array
    {
        $results = [];
        foreach ($definitions as $definition) {
            $results[] = self::captureOne($db, $definition);
        }
        return $results;
    }

    /**
     * @param array{metric_key:string,label:string,context:array<string,mixed>,sql:string,args:list<mixed>} $definition
     * @return array<string,mixed>
     */
    private static function captureOne(PDO $db, array $definition): array
    {
        $metricKey = self::normalizeMetricKey($definition['metric_key']);
        $context = self::normalizeContext($definition['context']);
        $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $contextHash = hash('sha256', $contextJson);
        $sql = trim($definition['sql']);
        $queryHash = hash('sha256', $sql);

        try {
            if (!preg_match('/^SELECT\b/i', $sql)) {
                throw new \RuntimeException('Only SELECT queries may be explained.');
            }
            $statement = $db->prepare('EXPLAIN ' . $sql);
            $statement->execute($definition['args']);
            $plan = $statement->fetchAll(PDO::FETCH_ASSOC);
            $analysis = self::analyse($plan);
            self::persist(
                $db,
                $metricKey,
                $contextHash,
                $contextJson,
                $queryHash,
                $sql,
                $plan,
                $analysis,
                ''
            );
            return [
                'metric_key' => $metricKey,
                'label' => $definition['label'],
                'context' => $context,
                'assessment' => $analysis['assessment'],
                'estimated_rows' => $analysis['estimated_rows'],
                'full_scan_rows' => $analysis['full_scan_rows'],
                'selected_keys' => $analysis['selected_keys'],
                'extra_flags' => $analysis['extra_flags'],
                'recommendation' => $analysis['recommendation'],
                'error' => '',
            ];
        } catch (Throwable $error) {
            $analysis = self::emptyAnalysis('error', 'EXPLAIN failed; inspect the recorded error before changing indexes.');
            self::persist(
                $db,
                $metricKey,
                $contextHash,
                $contextJson,
                $queryHash,
                $sql,
                [],
                $analysis,
                substr($error->getMessage(), 0, 4000)
            );
            return [
                'metric_key' => $metricKey,
                'label' => $definition['label'],
                'context' => $context,
                'assessment' => 'error',
                'estimated_rows' => 0,
                'full_scan_rows' => 0,
                'selected_keys' => '',
                'extra_flags' => '',
                'recommendation' => $analysis['recommendation'],
                'error' => $error->getMessage(),
            ];
        }
    }

    /**
     * @param list<array<string,mixed>> $plan
     * @return array{plan_step_count:int,estimated_rows:int,full_scan_rows:int,access_types:string,possible_keys:string,selected_keys:string,extra_flags:string,assessment:string,recommendation:string}
     */
    private static function analyse(array $plan): array
    {
        $estimatedRows = 0;
        $fullScanRows = 0;
        $accessTypes = [];
        $possibleKeys = [];
        $selectedKeys = [];
        $extraFlags = [];

        foreach ($plan as $row) {
            $normalized = [];
            foreach ($row as $key => $value) {
                $normalized[strtolower((string)$key)] = $value;
            }
            $rows = max(0, (int)($normalized['rows'] ?? 0));
            $estimatedRows += $rows;
            $accessType = strtolower(trim((string)($normalized['type'] ?? '')));
            if ($accessType !== '') {
                $accessTypes[$accessType] = true;
            }
            if ($accessType === 'all') {
                $fullScanRows += $rows;
            }
            self::collectCsv($possibleKeys, (string)($normalized['possible_keys'] ?? ''));
            self::collectCsv($selectedKeys, (string)($normalized['key'] ?? ''));
            $extra = trim((string)($normalized['extra'] ?? ''));
            if ($extra !== '') {
                foreach (preg_split('/\s*;\s*/', $extra) ?: [] as $flag) {
                    $flag = trim($flag);
                    if ($flag !== '') {
                        $extraFlags[$flag] = true;
                    }
                }
            }
        }

        $extraText = implode('; ', array_keys($extraFlags));
        $usesTemporary = stripos($extraText, 'Using temporary') !== false;
        $usesFilesort = stripos($extraText, 'Using filesort') !== false;
        $assessment = 'normal';
        if ($fullScanRows >= 50000 || (($usesTemporary || $usesFilesort) && $estimatedRows >= 10000)) {
            $assessment = 'investigate';
        } elseif ($fullScanRows >= 5000 || (($usesTemporary || $usesFilesort) && $estimatedRows >= 1000)
            || ($selectedKeys === [] && $estimatedRows >= 10000)) {
            $assessment = 'watch';
        }

        $recommendation = self::recommendation(
            $assessment,
            $fullScanRows,
            $estimatedRows,
            $usesTemporary,
            $usesFilesort,
            $selectedKeys !== []
        );

        return [
            'plan_step_count' => count($plan),
            'estimated_rows' => $estimatedRows,
            'full_scan_rows' => $fullScanRows,
            'access_types' => implode(', ', array_keys($accessTypes)),
            'possible_keys' => implode(', ', array_keys($possibleKeys)),
            'selected_keys' => implode(', ', array_keys($selectedKeys)),
            'extra_flags' => $extraText,
            'assessment' => $assessment,
            'recommendation' => $recommendation,
        ];
    }

    /** @param array<string,bool> $target */
    private static function collectCsv(array &$target, string $value): void
    {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'null') {
            return;
        }
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $target[$part] = true;
            }
        }
    }

    private static function recommendation(
        string $assessment,
        int $fullScanRows,
        int $estimatedRows,
        bool $usesTemporary,
        bool $usesFilesort,
        bool $hasSelectedKey
    ): string {
        if ($assessment === 'normal') {
            return 'No query-plan change is indicated. Confirm with repeated timing samples before changing indexes.';
        }
        $parts = [];
        if ($fullScanRows > 0) {
            $parts[] = 'A full scan is estimated across ' . number_format($fullScanRows) . ' row(s); compare WHERE/JOIN columns with existing composite indexes.';
        }
        if (!$hasSelectedKey && $estimatedRows > 0) {
            $parts[] = 'No key was selected; verify column order and expression use before proposing an index.';
        }
        if ($usesTemporary) {
            $parts[] = 'The plan uses a temporary table; inspect DISTINCT/GROUP BY aggregation and summary-table coverage.';
        }
        if ($usesFilesort) {
            $parts[] = 'The plan uses filesort; verify whether sorting is required for the count query or introduced by a derived/grouped path.';
        }
        $parts[] = 'Apply a schema change only when this context also records repeated timings of at least 100 ms.';
        return implode(' ', $parts);
    }

    /**
     * @return array{plan_step_count:int,estimated_rows:int,full_scan_rows:int,access_types:string,possible_keys:string,selected_keys:string,extra_flags:string,assessment:string,recommendation:string}
     */
    private static function emptyAnalysis(string $assessment, string $recommendation): array
    {
        return [
            'plan_step_count' => 0,
            'estimated_rows' => 0,
            'full_scan_rows' => 0,
            'access_types' => '',
            'possible_keys' => '',
            'selected_keys' => '',
            'extra_flags' => '',
            'assessment' => $assessment,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * @param list<array<string,mixed>> $plan
     * @param array{plan_step_count:int,estimated_rows:int,full_scan_rows:int,access_types:string,possible_keys:string,selected_keys:string,extra_flags:string,assessment:string,recommendation:string} $analysis
     */
    private static function persist(
        PDO $db,
        string $metricKey,
        string $contextHash,
        string $contextJson,
        string $queryHash,
        string $sql,
        array $plan,
        array $analysis,
        string $errorMessage
    ): void {
        $planJson = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $statement = $db->prepare(
            'INSERT INTO ue_exact_count_query_plans('
            . 'metric_key,context_hash,context_json,query_hash,query_sql,plan_json,plan_step_count,'
            . 'estimated_rows,full_scan_rows,access_types,possible_keys,selected_keys,extra_flags,'
            . 'assessment,recommendation,error_message,captured_at'
            . ') VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE context_json=VALUES(context_json),query_hash=VALUES(query_hash),'
            . 'query_sql=VALUES(query_sql),plan_json=VALUES(plan_json),plan_step_count=VALUES(plan_step_count),'
            . 'estimated_rows=VALUES(estimated_rows),full_scan_rows=VALUES(full_scan_rows),'
            . 'access_types=VALUES(access_types),possible_keys=VALUES(possible_keys),'
            . 'selected_keys=VALUES(selected_keys),extra_flags=VALUES(extra_flags),'
            . 'assessment=VALUES(assessment),recommendation=VALUES(recommendation),'
            . 'error_message=VALUES(error_message),captured_at=CURRENT_TIMESTAMP'
        );
        $statement->execute([
            $metricKey,
            $contextHash,
            $contextJson,
            $queryHash,
            $sql,
            $planJson,
            $analysis['plan_step_count'],
            $analysis['estimated_rows'],
            $analysis['full_scan_rows'],
            $analysis['access_types'],
            $analysis['possible_keys'],
            $analysis['selected_keys'],
            $analysis['extra_flags'],
            $analysis['assessment'],
            $analysis['recommendation'],
            $errorMessage,
        ]);
    }

    private static function normalizeMetricKey(string $metricKey): string
    {
        $metricKey = strtolower(trim($metricKey));
        $metricKey = preg_replace('/[^a-z0-9_.:-]+/', '_', $metricKey) ?? '';
        $metricKey = substr(trim($metricKey, '_'), 0, 120);
        if ($metricKey === '') {
            throw new \InvalidArgumentException('A query-plan metric key is required.');
        }
        return $metricKey;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private static function normalizeContext(array $context): array
    {
        ksort($context, SORT_STRING);
        $normalized = [];
        foreach ($context as $key => $value) {
            $key = substr((string)$key, 0, 80);
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $normalized[$key] = $value;
            } elseif (is_string($value)) {
                $normalized[$key] = substr($value, 0, 255);
            } elseif (is_array($value)) {
                $normalized[$key] = self::normalizeContext($value);
            } else {
                $normalized[$key] = get_debug_type($value);
            }
        }
        return $normalized;
    }
}
