<?php
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

use UnrealDb\Catalog\Presentation\Http\CatalogApplication;

/**
 * Compatibility bootstrap for legacy page controllers.
 *
 * Existing pages retain their current request/response code. New controllers
 * receive configuration and a PDO connection through a single application
 * context instead of repeating session, error, config, and connection setup.
 */
function catalog_bootstrap(): CatalogApplication
{
    return CatalogApplication::boot();
}
