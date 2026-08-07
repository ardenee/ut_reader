<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog dependency schema.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencySchemaManager;

/** Compatibility facade for dependency schema management. */
function catalog_dependency_schema_manager(PDO $db): PdoDependencySchemaManager
{
    /** @var array<int, PdoDependencySchemaManager> $managers */
    static $managers = [];

    $id = spl_object_id($db);
    return $managers[$id] ??= new PdoDependencySchemaManager($db);
}

function catalog_dependency_schema_column_exists(
    PDO $db,
    string $table,
    string $column
): bool {
    return catalog_dependency_schema_manager($db)->columnExists($table, $column);
}

function catalog_dependency_schema_table_exists(PDO $db, string $table): bool
{
    return catalog_dependency_schema_manager($db)->tableExists($table);
}

function catalog_dependency_schema_index_exists(
    PDO $db,
    string $table,
    string $index
): bool {
    return catalog_dependency_schema_manager($db)->indexExists($table, $index);
}

/**
 * Keeps dependency metadata explicit so resolver drift cannot silently become a
 * normal resolved dependency. Runtime upgrade behaviour is retained while the
 * deployment migrations are consolidated.
 */
function catalog_dependency_schema_ensure(PDO $db): void
{
    catalog_dependency_schema_manager($db)->ensure();
}

function catalog_dependency_schema_ensure_asset_registry_tables(PDO $db): void
{
    catalog_dependency_schema_manager($db)->ensureAssetRegistryTables();
}
