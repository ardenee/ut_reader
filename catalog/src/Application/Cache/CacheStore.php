<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application interface `CacheStore` for cache store.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Cache;

/**
 * Small cache boundary. Application services depend on this contract rather
 * than a particular filesystem, Redis, or managed cache implementation.
 */
interface CacheStore
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds): void;

    public function delete(string $key): void;

    /**
     * @param callable():mixed $loader
     */
    public function remember(string $key, int $ttlSeconds, callable $loader): mixed;
}
