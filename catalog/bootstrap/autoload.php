<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Bootstraps catalog runtime behavior for autoload.
 * Why: It centralizes startup/autoload configuration so entry points do not repeat the same initialization logic.
 * Role: Shared bootstrap code loaded early by catalog pages, APIs, CLI tools, or workers.
 * Audit: Core shared startup code; changes have wide impact and should reduce rather than duplicate bootstrap logic.
 */
declare(strict_types=1);

/**
 * Lightweight PSR-4-style loader for the catalog modular monolith.
 *
 * The project intentionally has no Composer runtime requirement on shared hosts.
 * Keeping the loader here gives namespaced code one explicit bootstrap boundary
 * while legacy entry points continue to use their existing require_once files.
 */
(function (): void {
    $prefix = 'UnrealDb\\Catalog\\';
    $baseDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;

    spl_autoload_register(static function (string $class) use ($prefix, $baseDirectory): void {
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        if ($relativeClass === '') {
            return;
        }

        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
        $path = $baseDirectory . $relativePath;

        if (is_file($path)) {
            require_once $path;
        }
    });
})();

require_once dirname(__DIR__) . '/lib/CatalogSystemErrorSafe.php';
catalog_system_error_register();
