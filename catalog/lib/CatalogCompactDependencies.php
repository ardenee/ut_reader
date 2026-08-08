<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves historical compact dependency read functions for compatibility callers.
 * Why: Schema/version checks, blocked metadata hydration and reverse/used-by query composition now live in CatalogCompactDependencyReadService.
 * Role: Thin compatibility facade; do not add dependency read implementation logic here.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactDependencyReadService;

function catalog_compact_dependencies_available(PDO $db): bool
{
    return (new CatalogCompactDependencyReadService($db))->available();
}

function catalog_compact_metadata_version(PDO $db, int $fileId): int
{
    return (new CatalogCompactDependencyReadService($db))->metadataVersion($fileId);
}

function catalog_compact_dependency_status_label(int $status): string
{
    return CatalogCompactDependencyReadService::statusLabel($status);
}

/** @return list<array<string,mixed>>|null */
function catalog_compact_dependency_rows(PDO $db, array $config, int $fileId): ?array
{
    return (new CatalogCompactDependencyReadService($db, $config))->compactRows($fileId);
}

/** @return list<array<string,mixed>> */
function catalog_dependency_rows(PDO $db, array $config, int $fileId): array
{
    return (new CatalogCompactDependencyReadService($db, $config))->rows($fileId);
}

/** @return list<array<string,mixed>> */
function catalog_dependency_used_by_rows(PDO $db, int $targetFileId, int $limit = 200): array
{
    return (new CatalogCompactDependencyReadService($db))->usedByRows($targetFileId, $limit);
}

/** @param list<string> $values @return list<string> */
function catalog_compact_unique_strings(array $values): array
{
    return CatalogCompactDependencyReadService::uniqueStrings($values);
}

/**
 * @param list<string> $identityNames
 * @return list<array<string,mixed>>
 */
function catalog_reverse_dependency_rows(
    PDO $db,
    array $config,
    int $gameId,
    int $targetFileId,
    array $identityNames
): array {
    return (new CatalogCompactDependencyReadService($db, $config))
        ->reverseRows($gameId, $targetFileId, $identityNames);
}
