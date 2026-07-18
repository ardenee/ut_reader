<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

final class FileLoginRateLimiter
{
    private \Closure $clock;

    public function __construct(
        private readonly string $directory,
        private readonly int $maxAttempts = 8,
        private readonly int $windowSeconds = 900,
        private readonly int $blockSeconds = 900,
        ?callable $clock = null
    ) {
        $this->clock = $clock === null
            ? static fn(): int => time()
            : \Closure::fromCallable($clock);
    }

    public function retryAfterSeconds(string $username, string $clientIp): int
    {
        return $this->mutate($username, $clientIp, function (array $state, int $now): array {
            $state = $this->normalize($state, $now);
            return [$state, max(0, (int)$state['blocked_until'] - $now)];
        });
    }

    public function recordFailure(string $username, string $clientIp): int
    {
        return $this->mutate($username, $clientIp, function (array $state, int $now): array {
            $state = $this->normalize($state, $now);
            $state['attempts'][] = $now;
            if (count($state['attempts']) >= max(1, $this->maxAttempts)) {
                $state['blocked_until'] = max((int)$state['blocked_until'], $now + max(60, $this->blockSeconds));
            }
            return [$state, max(0, (int)$state['blocked_until'] - $now)];
        });
    }

    public function clear(string $username, string $clientIp): void
    {
        $path = $this->statePath($username, $clientIp);
        if (is_file($path) && !@unlink($path)) {
            error_log('[UnrealDB auth] Could not remove login rate-limit state: ' . basename($path));
        }
    }

    /** @param callable(array<string,mixed>,int):array{0:array<string,mixed>,1:int} $callback */
    private function mutate(string $username, string $clientIp, callable $callback): int
    {
        $this->ensureDirectory();
        $path = $this->statePath($username, $clientIp);
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Login rate-limit storage is unavailable.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock login rate-limit state.');
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $state = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
            if (!is_array($state)) {
                $state = [];
            }

            [$nextState, $result] = $callback($state, ($this->clock)());
            $encoded = json_encode($nextState, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new \RuntimeException('Could not persist login rate-limit state.');
            }
            @chmod($path, 0600);

            return $result;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $state @return array{attempts:list<int>,blocked_until:int} */
    private function normalize(array $state, int $now): array
    {
        $cutoff = $now - max(60, $this->windowSeconds);
        $attempts = [];
        foreach (($state['attempts'] ?? []) as $attempt) {
            $attempt = (int)$attempt;
            if ($attempt >= $cutoff && $attempt <= $now + 60) {
                $attempts[] = $attempt;
            }
        }

        $blockedUntil = max(0, (int)($state['blocked_until'] ?? 0));
        if ($blockedUntil <= $now) {
            $blockedUntil = 0;
        }

        return ['attempts' => $attempts, 'blocked_until' => $blockedUntil];
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create login rate-limit storage.');
        }
        @chmod($this->directory, 0700);
    }

    private function statePath(string $username, string $clientIp): string
    {
        $clientIp = trim($clientIp);
        $identity = $clientIp !== ''
            ? 'ip|' . $clientIp
            : 'account|' . strtolower(substr(trim($username), 0, 80));
        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . hash('sha256', $identity) . '.json';
    }
}
