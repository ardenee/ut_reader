<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `FileRequestRateLimiter` for file request rate limiter.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

final class FileRequestRateLimiter
{
    private \Closure $clock;

    public function __construct(
        private readonly string $directory,
        private readonly int $maxRequests,
        private readonly int $windowSeconds,
        ?callable $clock = null
    ) {
        $this->clock = $clock === null
            ? static fn(): int => time()
            : \Closure::fromCallable($clock);
    }

    /** Returns zero when allowed, otherwise the number of seconds until retry. */
    public function consume(string $scope, string $identity): int
    {
        $this->ensureDirectory();
        $path = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . hash('sha256', strtolower(trim($scope)) . '|' . trim($identity)) . '.json';
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Request rate-limit storage is unavailable.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock request rate-limit state.');
            }
            rewind($handle);
            $raw = stream_get_contents($handle);
            $state = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
            if (!is_array($state)) {
                $state = [];
            }

            $now = ($this->clock)();
            $window = max(60, $this->windowSeconds);
            $cutoff = $now - $window;
            $requests = [];
            foreach (($state['requests'] ?? []) as $request) {
                $request = (int)$request;
                if ($request >= $cutoff && $request <= $now + 60) {
                    $requests[] = $request;
                }
            }

            if (count($requests) >= max(1, $this->maxRequests)) {
                $retryAfter = max(1, ($requests[0] + $window) - $now);
            } else {
                $requests[] = $now;
                $retryAfter = 0;
            }

            $encoded = json_encode(['requests' => $requests], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new \RuntimeException('Could not persist request rate-limit state.');
            }
            @chmod($path, 0600);
            return $retryAfter;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create request rate-limit storage.');
        }
        @chmod($this->directory, 0700);
    }
}
