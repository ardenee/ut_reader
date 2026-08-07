<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `FileCacheStore` for file cache store.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Cache;

use UnrealDb\Catalog\Application\Cache\CacheStore;

/**
 * Atomic shared-filesystem cache for the current single-host deployment.
 *
 * Values are JSON-only by design. In multi-node deployments, bind the same
 * CacheStore contract to Redis instead; callers do not need to change.
 */
final class FileCacheStore implements CacheStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create cache directory.');
        }
    }

    public function get(string $key): mixed
    {
        $path = $this->pathFor($key);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            @unlink($path);
            return null;
        }

        if (!is_array($record) || !isset($record['expires_at']) || (int)$record['expires_at'] < time()) {
            @unlink($path);
            return null;
        }

        return $record['value'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            $this->delete($key);
            return;
        }

        $path = $this->pathFor($key);
        $payload = json_encode([
            'expires_at' => time() + $ttlSeconds,
            'value' => $value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temporary, $payload, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not persist cache value.');
        }
    }

    public function delete(string $key): void
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function remember(string $key, int $ttlSeconds, callable $loader): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $loader();
        $this->set($key, $value, $ttlSeconds);

        return $value;
    }

    private function pathFor(string $key): string
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Cache key is required.');
        }

        return rtrim($this->directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}
