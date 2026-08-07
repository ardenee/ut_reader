<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the legacy global `CatalogUi` name as an alias of the namespaced
 *          `UnrealDb\\Catalog\\Presentation\\Ui\\CatalogUi` implementation.
 * Why: It keeps older include/call sites working while the real implementation lives under `catalog/src`.
 * Role: Compatibility wrapper between legacy global code and the namespaced application architecture.
 * Audit: Do not duplicate logic here; remove this wrapper only after all global-name callers have migrated to the
 *        namespaced class.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/../src/Presentation/Ui/CatalogUi.php';

class_alias('UnrealDb\\Catalog\\Presentation\\Ui\\CatalogUi', 'CatalogUi');
