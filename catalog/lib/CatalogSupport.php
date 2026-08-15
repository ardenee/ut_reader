<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog support for catalog support.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/CatalogResourceTracing.php';
require_once __DIR__ . '/CatalogPublicResponseCache.php';
require_once __DIR__ . '/CatalogPublicAccess.php';

\UnrealDb\Catalog\Presentation\Http\CatalogPageResponseTransform::register();
\UnrealDb\Catalog\Presentation\Http\CatalogFileInfoRouteGuard::register();
\UnrealDb\Catalog\Presentation\Http\CatalogFederationInventoryFailureHandler::register();

/*
 * Runtime job-resource limits are resolved when a worker attempts queue
 * admission. Shared support code therefore no longer mutates Domain policy or
 * opens a settings connection merely because a page includes CatalogSupport.
 */

/*
 * Keep the cheap crawler signature gate ahead of response-cache lookup so a
 * cached page is not a crawler-policy bypass. This gate reads no per-IP burst
 * state and takes no per-client exclusive lock.
 */
try {
    catalog_public_access_guard_crawler_request();
} catch (Throwable $error) {
    error_log('[UnrealDB public access] crawler guard failed open: ' . $error->getMessage());
}

/*
 * Upload Bucket advertises no UnrealDB total-file-size cap. Redirect archives
 * must therefore not inherit the ordinary profiled-upload output limit while
 * reconstructing their real package bytes. This changes only the size ceiling;
 * the extension-specific Epic UZ/UZ2/UZ3 decoders remain responsible for the
 * actual format validation.
 */
if (in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['upload-bucket-chunk.php'], true)) {
    $redirectLimit = (int)(getenv('UNREALDB_REDIRECT_MAX_OUTPUT_BYTES') ?: 0);
    if ($redirectLimit <= 0) {
        putenv('UNREALDB_REDIRECT_MAX_OUTPUT_BYTES=' . (PHP_INT_SIZE >= 8 ? '2147483647' : (string)PHP_INT_MAX));
    }
}

/*
 * Serve explicitly approved anonymous GET pages before they open a database
 * connection or mutate per-IP burst-control state. A cache HIT exits here.
 */
try {
    catalog_public_cache_bootstrap(catalog_config());
} catch (Throwable $error) {
    error_log('[UnrealDB public cache] bootstrap skipped: ' . $error->getMessage());
}

/*
 * Only cache misses/dynamic public GETs pay for synchronized per-IP burst state.
 * This keeps abuse protection around expensive work while making cached traffic
 * a read-only filesystem fast path.
 */
try {
    catalog_public_access_guard_burst_request();
} catch (Throwable $error) {
    error_log('[UnrealDB public access] burst guard failed open: ' . $error->getMessage());
}
