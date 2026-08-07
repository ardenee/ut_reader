<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Aggregates the shared catalog implementation pieces needed for catalog file maintenance.
 * Why: It provides one stable include point while the implementation is split into smaller focused files.
 * Role: Include/facade layer only; the executable implementation lives in the required library files.
 * Audit: Keep as a thin aggregator; do not add duplicate business logic here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogFileMaintenanceCompactCore.php';
require_once __DIR__ . '/CatalogFileMaintenanceCompactReimport.php';
require_once __DIR__ . '/CatalogFileMaintenanceCompactDelete.php';
