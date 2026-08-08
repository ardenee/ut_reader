<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical asset-metadata rebuild function for compatibility callers.
 * Why: Reader selection, reference extraction, storage validation and persistence now live in CatalogAssetMetadataService.
 * Role: Thin compatibility facade; do not add asset metadata implementation logic here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogAssetMetadataService;

/**
 * @param object|null $reader Optional already-open package reader.
 * @return array{assets:int,string_asset_refs:int,preload_deps:int,soft_refs:int,redirectors:int}
 */
function catalog_asset_metadata_rebuild_file(
    PDO $db,
    array $config,
    int $fileId,
    ?object $reader = null
): array {
    return (new CatalogAssetMetadataService($db, $config))->rebuildFile($fileId, $reader);
}
