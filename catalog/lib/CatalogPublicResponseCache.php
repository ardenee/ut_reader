<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical public response-cache helper API and named shutdown callback.
 * Why: File-backed cache policy, locking, stale serving and publication now live in CatalogPublicResponseCacheService.
 * Role: Thin compatibility facade; do not add cache implementation logic here.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Cache\CatalogPublicResponseCacheService;

function catalog_public_cache_route_ttl(array $config): int
{
    return CatalogPublicResponseCacheService::routeTtl($config);
}

function catalog_public_cache_enabled(array $config): bool
{
    return CatalogPublicResponseCacheService::enabled($config);
}

function catalog_public_cache_anonymous_request(): bool
{
    return CatalogPublicResponseCacheService::anonymousRequest();
}

function catalog_public_cache_query_string(): string
{
    return CatalogPublicResponseCacheService::queryString();
}

function catalog_public_cache_directory(array $config): string
{
    return CatalogPublicResponseCacheService::directory($config);
}

function catalog_public_cache_invalidate(array $config): int
{
    return CatalogPublicResponseCacheService::invalidate($config);
}

function catalog_public_cache_prune_directory(string $directory): void
{
    CatalogPublicResponseCacheService::pruneDirectory($directory);
}

function catalog_public_cache_read(string $path): ?array
{
    return CatalogPublicResponseCacheService::read($path);
}

function catalog_public_cache_serve(array $entry, string $status, int $ttl, int $staleSeconds): never
{
    CatalogPublicResponseCacheService::serve($entry, $status, $ttl, $staleSeconds);
}

function catalog_public_cache_bootstrap(array $config): void
{
    CatalogPublicResponseCacheService::bootstrap($config);
}

function catalog_public_cache_finish(): void
{
    CatalogPublicResponseCacheService::finish();
}
