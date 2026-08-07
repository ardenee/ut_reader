<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Runs representative exact-count benchmarks for administrator performance diagnostics.
 * Why: Exact-count timing is database/telemetry infrastructure, not an application use case.
 * Role: Infrastructure telemetry component used by the exact-count diagnostics page.
 * Audit: Keep database execution and telemetry recording here rather than in Application services or page-local code.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Telemetry;

use PDO;

/** Runs the exact count SQL used by the largest paginated administrator views. */
final class CatalogExactCountBenchmark
{
    /** @return list<array<string,mixed>> */
    public static function run(PDO $db): array
    {
        $samples = [];
        foreach (CatalogExactCountQueryCatalog::definitions($db) as $definition) {
            $sql = $definition['sql'];
            $args = $definition['args'];
            $samples[] = CatalogExactCountTelemetry::sample(
                $db,
                $definition['metric_key'],
                $definition['context'],
                static fn(): int => \catalog_count($db, $sql, $args)
            ) + ['label' => $definition['label']];
        }
        return $samples;
    }
}
