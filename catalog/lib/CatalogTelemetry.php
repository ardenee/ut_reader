<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogExactCountTelemetry;

/**
 * @param list<mixed> $args
 * @param array<string,mixed> $context
 */
function catalog_timed_count(
    PDO $db,
    string $metricKey,
    string $sql,
    array $args = [],
    array $context = []
): int {
    return CatalogExactCountTelemetry::measure(
        $db,
        $metricKey,
        $context,
        static fn(): int => catalog_count($db, $sql, $args)
    );
}
