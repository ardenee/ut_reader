<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Loads the catalog scanner compatibility API from focused scanner modules.
 * Why: The former scanner monolith mixed naming policy, upload staging, reader helpers, dependency persistence and
 *      package import in one file. Existing callers still include CatalogScanner.php, so this remains the stable
 *      compatibility entry point while implementation responsibilities live in focused modules/classes.
 * Role: Thin compatibility include manifest for current scanner_* functions.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/GameProfiles.php';
require_once __DIR__ . '/CatalogDependencySchema.php';
require_once __DIR__ . '/CatalogPackageAliases.php';
require_once __DIR__ . '/CatalogUE4ParserProfile.php';

require_once __DIR__ . '/Scanner/CatalogScannerPath.php';
require_once __DIR__ . '/Scanner/CatalogScannerSupport.php';
require_once __DIR__ . '/Scanner/CatalogScannerDependencies.php';
require_once __DIR__ . '/Scanner/CatalogScannerImport.php';
