<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the legacy global `CatalogReaderResolver` name as an alias of the namespaced
 *          `\UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver` implementation.
 * Why: It keeps older include/call sites working while the real implementation lives under `catalog/src`.
 * Role: Compatibility wrapper between legacy global code and the namespaced application architecture.
 * Audit: Do not duplicate logic here; remove this wrapper only after all global-name callers have migrated to the
 *        namespaced class.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Infrastructure/Readers/CatalogReaderResolver.php';

if (!class_exists('CatalogReaderResolver', false)) {
    class_alias(
        \UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver::class,
        'CatalogReaderResolver'
    );
}
