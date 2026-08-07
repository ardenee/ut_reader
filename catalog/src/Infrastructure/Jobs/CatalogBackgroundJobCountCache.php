<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Short-caches expensive Background Jobs aggregate counters between frequent live-row polls.
 * Why: The jobs UI refreshes every two seconds; repeatedly grouping and parsing result_json across the full queue can dominate the request.
 * Role: Infrastructure file-backed cache for administrator job-list aggregate metadata.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use Throwable;

final class CatalogBackgroundJobCountCache
{
    private const TTL_SECONDS = 15;
    private const STALE_SECONDS = 60;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * @param callable():array<string,int> $loader
     * @return array<string,int>
     */
    public function remember(string $key, callable $loader): array
    {
        $directory = $this->directory();
        if (!$this->ensureDirectory($directory)) {
            return $loader();
        }

        $path = $directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
        $lockPath = $path . '.lock';
        $cached = $this->read($path);
        $now = time();
        if ($cached !== null && $now - $cached['stored_at'] <= self::TTL_SECONDS) {
            return $cached['counts'];
        }

        $lock = @fopen($lockPath, 'c+b');
        if (!is_resource($lock)) {
            return $loader();
        }

        try {
            if (!@flock($lock, LOCK_EX | LOCK_NB)) {
                if ($cached !== null && $now - $cached['stored_at'] <= self::STALE_SECONDS) {
                    return $cached['counts'];
                }
                return $loader();
            }

            // Another request may have refreshed the cache between our first read
            // and acquiring the lock.
            $cached = $this->read($path);
            $now = time();
            if ($cached !== null && $now - $cached['stored_at'] <= self::TTL_SECONDS) {
                return $cached['counts'];
            }

            $counts = $loader();
            try {
                $this->write($path, $counts, $now);
            } catch (Throwable) {
                // Cache publication is best-effort. The freshly queried counts are
                // still authoritative for this response and must not be queried twice.
            }
            return $counts;
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private function directory(): string
    {
        $cache = is_array($this->config['cache'] ?? null) ? $this->config['cache'] : [];
        $base = trim((string)($cache['path'] ?? ''));
        if ($base === '') {
            $storageRoot = trim((string)($this->config['storage_path'] ?? ''));
            if ($storageRoot === '') {
                return '';
            }
            $base = rtrim($storageRoot, '/\\') . DIRECTORY_SEPARATOR . 'cache';
        }
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'background-jobs';
    }

    private function ensureDirectory(string $directory): bool
    {
        if ($directory === '' || $directory === DIRECTORY_SEPARATOR) {
            return false;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }
        return is_writable($directory);
    }

    /** @return array{stored_at:int,counts:array<string,int>}|null */
    private function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || (int)($decoded['stored_at'] ?? 0) < 1 || !is_array($decoded['counts'] ?? null)) {
            return null;
        }

        $counts = [];
        foreach ($decoded['counts'] as $status => $count) {
            $counts[(string)$status] = max(0, (int)$count);
        }
        return ['stored_at' => (int)$decoded['stored_at'], 'counts' => $counts];
    }

    /** @param array<string,int> $counts */
    private function write(string $path, array $counts, int $storedAt): void
    {
        $json = json_encode(
            ['stored_at' => $storedAt, 'counts' => $counts],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (!is_string($json)) {
            return;
        }

        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            @unlink($temporaryPath);
            return;
        }
        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
        }
    }
}
