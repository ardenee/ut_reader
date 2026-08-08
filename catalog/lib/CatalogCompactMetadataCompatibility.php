<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical compact-metadata SQL compatibility hook used by CatalogSupportCore.
 * Why: Active compatibility implementation now lives in CatalogCompactMetadataCompatibilityService under catalog/src.
 * Role: Thin compatibility facade; do not add metadata query implementation logic here.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactMetadataCompatibilityService;

/**
 * @param list<mixed> $args
 * @return array{handled:bool,value:mixed}
 */
function catalog_metadata_compat_query(PDO $db, string $mode, string $sql, array $args): array
{
    return (new CatalogCompactMetadataCompatibilityService())->query($db, $mode, $sql, $args);
}
