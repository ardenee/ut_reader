<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogExactCountTelemetry` for catalog exact count telemetry.
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

/**
 * Aggregates exact-count query timings into one bounded row per metric/context.
 *
 * Telemetry failures never change the measured query result. Before migration
 * 202607270012 is applied, samples are returned normally without persistence.
 */
final class CatalogExactCountTelemetry
{
    public const SLOW_THRESHOLD_US = 100000;

    /** @var array<int,bool> */
    private static array $availability = [];
    private static bool $recordFailureLogged = false;

    /**
     * @param array<string,mixed> $context
     * @param callable():int $query
     */
    public static function measure(PDO $db, string $metricKey, array $context, callable $query): int
    {
        return self::sample($db, $metricKey, $context, $query)['result'];
    }

    /**
     * @param array<string,mixed> $context
     * @param callable():int $query
     * @return array{metric_key:string,context:array<string,mixed>,result:int,duration_us:int,duration_ms:float,slow:bool,recorded:bool}
     */
    public static function sample(PDO $db, string $metricKey, array $context, callable $query): array
    {
        $started = hrtime(true);
        $result = $query();
        $durationUs = max(0, (int)round((hrtime(true) - $started) / 1000));
        $recorded = false;

        if (self::available($db)) {
            try {
                self::record($db, $metricKey, $context, $durationUs, $result);
                $recorded = true;
            } catch (Throwable $error) {
                if (!self::$recordFailureLogged) {
                    self::$recordFailureLogged = true;
                    error_log('[UnrealDB exact-count telemetry] ' . $error->getMessage());
                }
            }
        }

        return [
            'metric_key' => self::normalizeMetricKey($metricKey),
            'context' => self::normalizeContext($context),
            'result' => $result,
            'duration_us' => $durationUs,
            'duration_ms' => round($durationUs / 1000, 3),
            'slow' => $durationUs >= self::SLOW_THRESHOLD_US,
            'recorded' => $recorded,
        ];
    }

    /** @param array<string,mixed> $context */
    private static function record(PDO $db, string $metricKey, array $context, int $durationUs, int $result): void
    {
        $metricKey = self::normalizeMetricKey($metricKey);
        $normalized = self::normalizeContext($context);
        $contextJson = json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        if (strlen($contextJson) > 4000) {
            $contextJson = json_encode(
                ['context_truncated' => true, 'context_hash' => hash('sha256', $contextJson)],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }
        $contextHash = hash('sha256', $contextJson);
        $slow = $durationUs >= self::SLOW_THRESHOLD_US ? 1 : 0;

        $statement = $db->prepare(
            'INSERT INTO ue_exact_count_telemetry('
            . 'metric_key,context_hash,context_json,sample_count,total_duration_us,max_duration_us,'
            . 'last_duration_us,slow_sample_count,last_result_count,first_seen_at,last_seen_at'
            . ') VALUES(?,?,?,1,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'context_json=VALUES(context_json),sample_count=sample_count+1,'
            . 'total_duration_us=total_duration_us+VALUES(total_duration_us),'
            . 'max_duration_us=GREATEST(max_duration_us,VALUES(max_duration_us)),'
            . 'last_duration_us=VALUES(last_duration_us),'
            . 'slow_sample_count=slow_sample_count+VALUES(slow_sample_count),'
            . 'last_result_count=VALUES(last_result_count),last_seen_at=CURRENT_TIMESTAMP'
        );
        $statement->execute([
            $metricKey,
            $contextHash,
            $contextJson,
            $durationUs,
            $durationUs,
            $durationUs,
            $slow,
            max(0, $result),
        ]);
    }

    private static function normalizeMetricKey(string $metricKey): string
    {
        $metricKey = strtolower(trim($metricKey));
        $metricKey = preg_replace('/[^a-z0-9_.:-]+/', '_', $metricKey) ?? '';
        $metricKey = substr(trim($metricKey, '_'), 0, 120);
        if ($metricKey === '') {
            throw new \InvalidArgumentException('An exact-count telemetry metric key is required.');
        }
        return $metricKey;
    }

    private static function available(PDO $db): bool
    {
        $key = spl_object_id($db);
        if (array_key_exists($key, self::$availability)) {
            return self::$availability[$key];
        }

        try {
            $statement = $db->query(
                'SELECT 1 FROM information_schema.tables '
                . 'WHERE table_schema=DATABASE() AND table_name="ue_exact_count_telemetry" LIMIT 1'
            );
            self::$availability[$key] = (bool)$statement->fetchColumn();
        } catch (Throwable) {
            self::$availability[$key] = false;
        }
        return self::$availability[$key];
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
                continue;
            }
            if (is_string($value)) {
                $normalized[$key] = substr($value, 0, 255);
                continue;
            }
            if (is_array($value)) {
                $normalized[$key] = self::normalizeContext($value);
                continue;
            }
            $normalized[$key] = get_debug_type($value);
        }
        return $normalized;
    }
}
