<?php
/**
 * Small, private, short-lived cache for Access Matrix grouped aggregates.
 *
 * Raw request-event rows and security/blocklist actions remain live. Files are
 * in PHP's system temporary directory, NOT beneath the webroot or catalogue
 * storage; reporting may include private visitor IPs.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Telemetry;

use Throwable;

final class CatalogAccessMatrixAggregateCache
{
    public const TTL_SECONDS = 300;

    private readonly string $directory;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $namespace = substr(hash('sha256',
            (string)($config['storage_path'] ?? '')
            . "\0" . (string)($config['db']['host'] ?? '')
            . "\0" . (string)($config['db']['name'] ?? $config['db']['database'] ?? '')
        ), 0, 24);
        $this->directory = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR . 'unrealdb-access-matrix-' . $namespace;
    }

    /**
     * Aggregated reports tolerate at most a five-minute delay; admin event
     * deletion invalidates caches immediately. Fail open to live SQL if the
     * temp directory cannot be created/read/written.
     *
     * @param list<mixed> $identity
     * @param callable():array<mixed> $loader
     * @return array<mixed>
     */
    public function remember(string $report, array $identity, callable $loader): array
    {
        if (!$this->ensureDirectory()) return $loader();
        $generation = @file_get_contents($this->directory . '/generation') ?: '';
        $key = hash('sha256', json_encode(
            ['v2',$generation,$report,$identity],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        ));
        $path = $this->directory . DIRECTORY_SEPARATOR . $key . '.json';
        $cached = $this->read($path);
        if ($cached !== null) return $cached;
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) return $loader();

        try {
            if (!@flock($lock, LOCK_EX)) return $loader();
            // Check again after another visitor computes this aggregate.
            $cached = $this->read($path);
            if ($cached !== null) return $cached;
            $value = $loader();
            $data = json_encode(
                ['created_at'=>time(),'value'=>$value],
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($temp, $data) !== false) {
                // rename may not overwrite existing Windows files; we have the
                // per-report exclusive lock throughout this operation.
                @unlink($path);
                if (!@rename($temp, $path)) @unlink($temp);
            }
            return $value;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** A mutation may change all card aggregates: rotate the generation. */
    public function invalidate(): void
    {
        if (!$this->ensureDirectory()) return;
        @file_put_contents(
            $this->directory . '/generation',
            bin2hex(random_bytes(16)), LOCK_EX
        );
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            @unlink($path);
        }
    }

    private function ensureDirectory(): bool
    {
        return is_dir($this->directory)
            || @mkdir($this->directory, 0700, true)
            || is_dir($this->directory);
    }

    /** @return ?array<mixed> */
    private function read(string $path): ?array
    {
        $data = @file_get_contents($path);
        if ($data === false) return null;
        $decoded = json_decode($data, true);
        if (!is_array($decoded)
            || !isset($decoded['created_at'])
            || !array_key_exists('value', $decoded)
            || !is_array($decoded['value'])
            || (int)$decoded['created_at'] < time() - self::TTL_SECONDS) {
            return null;
        }
        return $decoded['value'];
    }
}
