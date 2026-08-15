<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Registers the `UnrealDb\Catalog` namespaced autoloader and boots the catalog HTTP application.
 * Why: It provides one startup path so pages do not repeat namespace-to-file loading and application bootstrapping.
 * Role: Core catalog bootstrap shared by entry points that use the namespaced application architecture.
 * Audit: Foundational shared code; keep initialization here rather than duplicating autoload/bootstrap logic in pages.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

function catalog_bootstrap(bool $startSession = true)
{
    $class = 'UnrealDb\\Catalog\\Presentation\\Http\\CatalogApplication';
    return $class::boot($startSession);
}
