<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Telemetry;

use PDO;
use Throwable;

/**
 * Aggregates exact-count query timings into one bounded row per metric/context.
 *
 * Telemetry failures never change the page result. Before migration 202607270012
 * is applied, measure() simply returns the exact query result without recording.
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
        $started = hrtime(true);
        $result = $query();
        $durationUs = max(0, (int)round((hrtime(true) - $started) / 1000));

        if (!self::available($db)) {
            return $result;
        }

        try {
            self::record($db, $metricKey, $context, $durationUs, $result);
        } catch (Throwable $error) {
            if (!self::$recordFailureLogged) {
                self::$recordFailureLogged = true;
                error_log('[UnrealDB exact-count telemetry] ' . $error->getMessage());
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $context */
    private static function record(PDO $db, string $metricKey, array $context, int $durationUs, int $result): void
    {
        $metricKey = strtolower(trim($metricKey));
        $metricKey = preg_replace('/[^a-z0-9_.:-]+/', '_', $metricKey) ?? '';
        $metricKey = substr(trim($metricKey, '_'), 0, 120);
        if ($metricKey === '') {
            throw new \InvalidArgumentException('An exact-count telemetry metric key is required.');
        }

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
            . ') VALUES(?,?,?,1,?,?,?, ?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) '
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
