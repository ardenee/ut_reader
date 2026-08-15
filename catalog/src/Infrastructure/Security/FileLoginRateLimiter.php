<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `FileLoginRateLimiter` for file login rate limiter.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
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

    /**
     * Login throttling deliberately uses three independent buckets:
     *
     * - account: stops a distributed attack rotating through source IPs;
     * - IP: stops one client rotating through account names;
     * - account+IP: trips quickly for repeated failures against one account.
     *
     * The historical login_max_attempts setting remains the account threshold.
     * The pair threshold is stricter and the IP threshold is broader so normal
     * NAT/proxy traffic is not needlessly locked out.
     */
    public function retryAfterSeconds(string $username, string $clientIp): int
    {
        $now = ($this->clock)();
        $retryAfter = 0;
        foreach ($this->buckets($username, $clientIp) as $bucket) {
            $retryAfter = max(
                $retryAfter,
                $this->mutateIdentity(
                    $bucket['identity'],
                    $bucket['limit'],
                    $now,
                    false
                )
            );
        }
        return $retryAfter;
    }

    public function recordFailure(string $username, string $clientIp): int
    {
        $now = ($this->clock)();
        $retryAfter = 0;
        foreach ($this->buckets($username, $clientIp) as $bucket) {
            $retryAfter = max(
                $retryAfter,
                $this->mutateIdentity(
                    $bucket['identity'],
                    $bucket['limit'],
                    $now,
                    true
                )
            );
        }
        return $retryAfter;
    }

    /**
     * A successful login clears the account and account+IP buckets. The broader
     * IP bucket is intentionally retained so one successful credential cannot
     * erase failures generated against other accounts from the same source.
     */
    public function clear(string $username, string $clientIp): void
    {
        foreach ($this->buckets($username, $clientIp) as $bucket) {
            if (!$bucket['clear_on_success']) {
                continue;
            }
            $path = $this->statePath($bucket['identity']);
            if (is_file($path) && !@unlink($path)) {
                error_log('[UnrealDB auth] Could not remove login rate-limit state: ' . basename($path));
            }
        }
    }

    /**
     * @return list<array{identity:string,limit:int,clear_on_success:bool}>
     */
    private function buckets(string $username, string $clientIp): array
    {
        $account = strtolower(substr(trim($username), 0, 80));
        $ip = trim($clientIp);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = '';
        }

        $accountLimit = max(1, $this->maxAttempts);
        $pairLimit = max(3, min($accountLimit, (int)ceil($accountLimit * 0.625)));
        $ipLimit = max(20, min(200, ($accountLimit * 2) + 4));

        $buckets = [];
        if ($account !== '') {
            $buckets[] = [
                'identity' => 'account|' . $account,
                'limit' => $accountLimit,
                'clear_on_success' => true,
            ];
        }
        if ($ip !== '') {
            // Keep the historical ip|<address> identity so existing throttling
            // state continues to apply after this security upgrade.
            $buckets[] = [
                'identity' => 'ip|' . $ip,
                'limit' => $ipLimit,
                'clear_on_success' => false,
            ];
        }
        if ($account !== '' && $ip !== '') {
            $buckets[] = [
                'identity' => 'pair|' . $account . '|' . $ip,
                'limit' => $pairLimit,
                'clear_on_success' => true,
            ];
        }

        if ($buckets === []) {
            $buckets[] = [
                'identity' => 'account|unknown',
                'limit' => $accountLimit,
                'clear_on_success' => true,
            ];
        }

        return $buckets;
    }

    private function mutateIdentity(
        string $identity,
        int $limit,
        int $now,
        bool $recordFailure
    ): int {
        $this->ensureDirectory();
        $path = $this->statePath($identity);
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
            $state = $this->normalize($state, $now);

            if ($recordFailure) {
                $state['attempts'][] = $now;
                if (count($state['attempts']) >= max(1, $limit)) {
                    $state['blocked_until'] = max(
                        (int)$state['blocked_until'],
                        $now + max(60, $this->blockSeconds)
                    );
                }
            }

            $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new \RuntimeException('Could not persist login rate-limit state.');
            }
            @chmod($path, 0600);

            return max(0, (int)$state['blocked_until'] - $now);
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

    private function statePath(string $identity): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . hash('sha256', $identity)
            . '.json';
    }
}
