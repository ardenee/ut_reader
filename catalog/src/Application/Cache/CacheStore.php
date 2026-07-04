<?php
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
