<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical no-container source-scan function for compatibility callers.
 * Why: Active source-scan orchestration now lives in CatalogSourceScanRunner under catalog/src.
 * Role: Thin compatibility facade; do not add scan logic here.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Source\CatalogSourceScanRunner;

/**
 * @param array<string,mixed> $config
 * @param callable(array<string,mixed>):void|null $progress
 * @return array<string,mixed>
 */
function catalog_source_scan_run_without_containers(
    PDO $db,
    array $config,
    int $sourceId,
    bool $importUnknown,
    bool $strictProfile,
    ?int $userId = null,
    ?callable $progress = null
): array {
    return (new CatalogSourceScanRunner($db, $config))->run(
        $sourceId,
        $importUnknown,
        $strictProfile,
        $userId,
        $progress
    );
}
