<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for bootstrap.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

if (!defined('UNREALDB_CATALOG_AUTOLOAD_REGISTERED')) {
    define('UNREALDB_CATALOG_AUTOLOAD_REGISTERED', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'UnrealDb\\Catalog\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}

function catalog_bootstrap()
{
    $class = 'UnrealDb\\Catalog\\Presentation\\Http\\CatalogApplication';
    return $class::boot();
}
