<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog public rate limit.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Security\FileRequestRateLimiter;

function catalog_public_rate_limit_value(string $environmentName, int $default, int $minimum, int $maximum): int
{
    $raw = getenv($environmentName);
    $value = $raw !== false && $raw !== '' ? filter_var($raw, FILTER_VALIDATE_INT) : false;
    return max($minimum, min($value === false ? $default : (int)$value, $maximum));
}

function catalog_public_rate_limit(string $scope, int $maxRequests, int $windowSeconds, ?string $identity = null): int
{
    $config = catalog_config();
    $identity = trim((string)($identity ?? catalog_client_ip()));
    if ($identity === '') {
        $identity = 'unknown';
    }
    $limiter = new FileRequestRateLimiter(
        rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'public-requests',
        max(1, $maxRequests),
        max(60, $windowSeconds)
    );
    return $limiter->consume($scope, $identity);
}

function catalog_public_rate_limit_or_throw(string $scope, int $maxRequests, int $windowSeconds, ?string $identity = null): void
{
    $retryAfter = catalog_public_rate_limit($scope, $maxRequests, $windowSeconds, $identity);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        throw new RuntimeException('Too many requests. Try again in ' . $retryAfter . ' seconds.');
    }
}

function catalog_public_search_rate_limit(): void
{
    if (catalog_support_is_admin()) {
        return;
    }
    catalog_public_rate_limit_or_throw(
        'public-search',
        catalog_public_rate_limit_value('UNREALDB_PUBLIC_SEARCH_MAX_REQUESTS', 60, 1, 5000),
        catalog_public_rate_limit_value('UNREALDB_PUBLIC_SEARCH_WINDOW_SECONDS', 600, 60, 86400)
    );
}

function catalog_public_download_rate_limit(): void
{
    catalog_public_rate_limit_or_throw(
        'public-download',
        catalog_public_rate_limit_value('UNREALDB_PUBLIC_DOWNLOAD_MAX_REQUESTS', 30, 1, 1000),
        catalog_public_rate_limit_value('UNREALDB_PUBLIC_DOWNLOAD_WINDOW_SECONDS', 600, 60, 86400)
    );
}

function catalog_public_join_rate_limit(string $siteId = ''): void
{
    $identity = catalog_client_ip() . '|' . strtolower(trim($siteId));
    catalog_public_rate_limit_or_throw(
        'federation-join',
        // Pairing can legitimately require several retries while certificates,
        // firewall rules, and database migrations are being corrected. Keep the
        // limiter useful without locking an administrator out for an hour.
        catalog_public_rate_limit_value('UNREALDB_FEDERATION_JOIN_MAX_REQUESTS', 20, 1, 100),
        catalog_public_rate_limit_value('UNREALDB_FEDERATION_JOIN_WINDOW_SECONDS', 900, 60, 86400),
        $identity
    );
}
