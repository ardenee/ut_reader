<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves historical compact metadata mutation helper functions for existing callers.
 * Why: Compact identity mutation and blocked-container publication now live in CatalogCompactMetadataMutationService.
 * Role: Thin compatibility facade; do not add metadata mutation implementation logic here.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactMetadataMutationService;

function catalog_compact_metadata_rewrite_package_identity(
    PDO $db,
    array $config,
    int $fileId,
    string $packageName
): int {
    return (new CatalogCompactMetadataMutationService($db, $config))
        ->rewritePackageIdentity($fileId, $packageName);
}

function catalog_compact_metadata_join_package_path(string $packageName, string $localPath): string
{
    return CatalogCompactMetadataMutationService::joinPackagePath($packageName, $localPath);
}
