<?php
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
