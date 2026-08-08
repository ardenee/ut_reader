<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Compatibility facade for mounted source identity helpers.
 * Why: Existing procedural callers retain stable signatures while naming and repair live under src/.
 * Role: Transitional legacy facade; new code should use CatalogSourceIdentityNaming and CatalogSourceIdentityRebuilder.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Identity\CatalogSourceIdentityNaming;
use UnrealDb\Catalog\Infrastructure\Identity\CatalogSourceIdentityRebuilder;

function catalog_source_identity_path(string $sourceRelativePath): string
{
    return (new CatalogSourceIdentityNaming())->path($sourceRelativePath);
}

function catalog_source_identity_package_name(
    string $engineKey,
    string $sourceRelativePath,
    string $originalName = ''
): string {
    return (new CatalogSourceIdentityNaming())->packageName(
        $engineKey,
        $sourceRelativePath,
        $originalName
    );
}

/** @param list<string> $names @return list<string> */
function catalog_source_identity_normalized_names(array $names): array
{
    return (new CatalogSourceIdentityNaming())->normalizedNames($names);
}

/**
 * @param list<string> $previousPackageNames
 * @return array{changed:bool,file_id:int,old_package_name:string,new_package_name:string,alias_count:int,dependency_files_refreshed:int,reconciliation_job_id:int}
 */
function catalog_source_identity_rebuild_file(
    PDO $db,
    array $config,
    int $fileId,
    ?callable $progress = null,
    bool $refreshDependencies = true,
    array $previousPackageNames = []
): array {
    return (new CatalogSourceIdentityRebuilder($db, $config))->rebuild(
        $fileId,
        $progress,
        $refreshDependencies,
        $previousPackageNames
    );
}
