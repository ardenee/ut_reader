<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/CatalogResourceTracing.php';
require_once __DIR__ . '/CatalogPublicResponseCache.php';
require_once __DIR__ . '/CatalogPublicAccess.php';

\UnrealDb\Catalog\Presentation\Http\LegacySupportHooks::register();
\UnrealDb\Catalog\Presentation\Http\CatalogTableSortAssets::register();

/*
 * Many administrator pages still construct PdoJobQueue directly instead of
 * booting CatalogApplication. Give those paths the same saved resource limits
 * through the atomic projection written by job-resource-limits.php.
 */
try {
    $jobLimitConfig = catalog_config();
    $jobLimitStorage = rtrim((string)($jobLimitConfig['storage_path'] ?? ''), '/\\');
    if ($jobLimitStorage !== '') {
        \UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy::setLimitFile(
            $jobLimitStorage . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'resource-limits.json'
        );
    }
} catch (Throwable) {
    // Setup and incomplete installations may not have a readable config yet.
}

/*
 * Apply the anonymous crawler and rapid-link guard before public response-cache
 * lookup. This prevents a cached page from becoming a bypass for automated
 * bulk traversal. Logged-in administrators and non-GET requests are exempt.
 */
try {
    catalog_public_access_guard_request();
} catch (Throwable $error) {
    error_log('[UnrealDB public access] guard failed open: ' . $error->getMessage());
}

/*
 * Upload Bucket advertises no UnrealDB total-file-size cap. Redirect archives
 * must therefore not inherit the ordinary profiled-upload output limit while
 * reconstructing their real package bytes. This changes only the size ceiling;
 * the extension-specific Epic UZ/UZ2/UZ3 decoders remain responsible for the
 * actual format validation.
 */
if (in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['upload-bucket.php', 'upload-bucket-chunk.php'], true)) {
    $redirectLimit = (int)(getenv('UNREALDB_REDIRECT_MAX_OUTPUT_BYTES') ?: 0);
    if ($redirectLimit <= 0) {
        putenv('UNREALDB_REDIRECT_MAX_OUTPUT_BYTES=' . (PHP_INT_SIZE >= 8 ? '2147483647' : (string)PHP_INT_MAX));
    }
}

/*
 * Serve explicitly approved anonymous GET pages before they open a database
 * connection. Logged-in, remembered, POST and CSRF-bearing requests bypass it.
 */
try {
    catalog_public_cache_bootstrap(catalog_config());
} catch (Throwable $error) {
    error_log('[UnrealDB public cache] bootstrap skipped: ' . $error->getMessage());
}
