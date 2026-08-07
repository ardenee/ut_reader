<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `CatalogExactCountBenchmark` for catalog exact count benchmark.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Telemetry;

use PDO;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogExactCountTelemetry;

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
