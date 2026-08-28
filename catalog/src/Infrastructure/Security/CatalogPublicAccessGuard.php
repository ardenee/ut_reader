<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Enforces anonymous crawler, burst and public-action rate limits.
 * Why: Request-abuse policy should be separate from settings persistence and download streaming.
 * Role: Infrastructure security boundary preserving existing public access and administrator exemption behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogPublicAccessGuard
{
    private const BURST_SHARD_COUNT = 256;
    private const BURST_PRUNE_INTERVAL_SECONDS = 60;

    /** @param array<string,mixed>|null $config */
    public function __construct(private readonly ?array $config = null)
    {
    }

    public function clientIp(): string
    {
        $ip = function_exists('catalog_client_ip')
            ? trim((string)\catalog_client_ip())
            : trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return $ip !== '' ? $ip : 'unknown';
    }

    public function knownCrawler(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }
        return preg_match(
            '/(?:bot\b|crawler|spider|slurp|bingpreview|facebookexternalhit|bytespider|headlesschrome|phantomjs|wget|curl\/|python-requests|scrapy|go-http-client|java\/)/i',
            $userAgent
        ) === 1;
    }

    public function exempt(): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }
        if (session_status() === PHP_SESSION_ACTIVE && (($_SESSION['user']['role'] ?? '') === 'admin')) {
            return true;
        }

        $sessionCookie = session_name();
        $hasSessionCookie = $sessionCookie !== ''
            && isset($_COOKIE[$sessionCookie])
            && trim((string)$_COOKIE[$sessionCookie]) !== '';
        $hasRememberCookie = !empty($_COOKIE['UNREALDB_REMEMBER']);
        if (($hasSessionCookie || $hasRememberCookie) && function_exists('catalog_support_is_admin')) {
            try {
                return \catalog_support_is_admin();
            } catch (Throwable $error) {
                error_log('[UnrealDB public access] administrator exemption check failed: ' . $error->getMessage());
            }
        }
        return false;
    }

    public function burstState(
        string $identity,
        int $maximum,
        int $windowSeconds,
        int $blockSeconds
    ): int {
        $config = $this->resolvedConfig();
        $directory = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'public-burst';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create public burst-control storage.');
        }

        // One flat directory becomes pathological with millions of historical IPs.
        // Hash sharding keeps directory lookup bounded, while rotating cleanup
        // deletes state after it can no longer affect either the rolling window or
        // an active temporary block.
        $hash = hash('sha256', $identity);
        $shardDirectory = $directory . DIRECTORY_SEPARATOR . substr($hash, 0, 2);
        if (!is_dir($shardDirectory)
            && !mkdir($shardDirectory, 0700, true)
            && !is_dir($shardDirectory)) {
            throw new RuntimeException('Could not create public burst-control shard.');
        }
        $this->pruneBurstState(
            $directory,
            max(max(1, $windowSeconds), max(10, $blockSeconds)) + 60
        );

        $path = $shardDirectory . DIRECTORY_SEPARATOR . $hash . '.json';
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open public burst-control state.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Could not lock public burst-control state.');
            }
            rewind($handle);
            $raw = stream_get_contents($handle);
            $state = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
            if (!is_array($state)) {
                $state = [];
            }
            $now = time();
            $blockedUntil = max(0, (int)($state['blocked_until'] ?? 0));
            if ($blockedUntil > $now) {
                // The read-only pre-cache gate normally catches this case. Direct
                // callers of burstState() should still avoid rewriting identical
                // blocked state on every rejected request.
                return $blockedUntil - $now;
            }

            $cutoff = $now - max(1, $windowSeconds);
            $requests = [];
            foreach ((array)($state['requests'] ?? []) as $timestamp) {
                $timestamp = (int)$timestamp;
                if ($timestamp >= $cutoff && $timestamp <= $now + 60) {
                    $requests[] = $timestamp;
                }
            }
            $requests[] = $now;
            if (count($requests) > max(2, $maximum)) {
                $blockedUntil = $now + max(10, $blockSeconds);
                $retry = $blockedUntil - $now;
            } else {
                $blockedUntil = 0;
                $retry = 0;
            }
            $state['requests'] = $requests;
            $state['blocked_until'] = $blockedUntil;

            $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new RuntimeException('Could not persist public burst-control state.');
            }
            @chmod($path, 0600);
            return $retry;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function abort(int $status, string $title, string $message, int $retryAfter = 0): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, max-age=0');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
            if ($retryAfter > 0) {
                header('Retry-After: ' . $retryAfter);
            }
        }
        $escape = static fn(string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $escape($title) . '</title><style>body{font-family:system-ui,sans-serif;background:#111827;color:#e5e7eb;padding:32px}'
            . '.card{max-width:760px;margin:auto;background:#1f2937;border:1px solid #4b5563;border-radius:10px;padding:24px}'
            . 'h1{margin-top:0}a{color:#93c5fd}</style></head><body><div class="card"><h1>' . $escape($title) . '</h1><p>'
            . $escape($message) . '</p>'
            . ($retryAfter > 0 ? '<p>Try again in approximately ' . $retryAfter . ' seconds.</p>' : '')
            . '<p><a href="index.php">Return to UnrealDB</a></p></div></body></html>';
        exit;
    }

    /**
     * Cheap pre-cache gate: crawler detection plus read-only enforcement of an
     * already-active temporary block. No request timestamp is appended here and
     * no burst-state file is rewritten on ordinary cache hits.
     */
    public function guardCrawlerRequest(): void
    {
        if ($this->exempt() || !$this->guardableMethod()) {
            return;
        }
        $settings = $this->settingsStore()->settings();
        $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($settings['public_block_crawlers'] && $this->knownCrawler($userAgent)) {
            $this->abort(
                403,
                'Automated access blocked',
                'Automated crawlers and bulk link scanners are not permitted on this development service.'
            );
        }

        $retryAfter = $this->activeBurstBlockRetry($this->clientIp());
        if ($retryAfter > 0) {
            $this->abort(
                429,
                'Temporarily blocked',
                'Too many pages or links were opened in a short period. This IP has been temporarily blocked.',
                $retryAfter
            );
        }
    }

    /** Stateful burst gate for requests that were not served from response cache. */
    public function guardBurstRequest(): void
    {
        if ($this->exempt() || !$this->guardableMethod()) {
            return;
        }
        $settings = $this->settingsStore()->settings();
        $retryAfter = $this->burstState(
            $this->clientIp(),
            $settings['public_burst_max_requests'],
            $settings['public_burst_window_seconds'],
            $settings['public_burst_block_seconds']
        );
        if ($retryAfter > 0) {
            $this->abort(
                429,
                'Temporarily blocked',
                'Too many pages or links were opened in a short period. This IP has been temporarily blocked.',
                $retryAfter
            );
        }
    }

    /** Compatibility entry point for callers that need both gates immediately. */
    public function guardRequest(): void
    {
        $this->guardCrawlerRequest();
        $this->guardBurstRequest();
    }

    public function rateLimit(PDO $db, string $scope, int $maximum, int $windowSeconds): int
    {
        if (function_exists('catalog_support_is_admin') && \catalog_support_is_admin()) {
            return 0;
        }
        $config = $this->resolvedConfig();
        $directory = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'public-actions';
        $limiter = new FileRequestRateLimiter($directory, max(1, $maximum), max(60, $windowSeconds));
        return $limiter->consume($scope, $this->clientIp());
    }

    public function limitOrThrow(
        PDO $db,
        string $scope,
        int $maximum,
        int $windowSeconds,
        string $label
    ): void {
        $retryAfter = $this->rateLimit($db, $scope, $maximum, $windowSeconds);
        if ($retryAfter > 0) {
            if (!headers_sent()) {
                http_response_code(429);
                header('Retry-After: ' . $retryAfter);
            }
            throw new RuntimeException(
                $label . ' limit reached for this IP address. Try again in ' . $retryAfter . ' seconds.'
            );
        }
    }

    public function transferAllowedOrThrow(PDO $db, string $label): void
    {
        $ip = $this->clientIp();
        if ((new CatalogTransferBlocklist($db))->isBlocked($ip)) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            throw new RuntimeException(
                $label . ' is blocked for this IP address. Website browsing remains available.'
            );
        }
    }

    public function downloadLimit(PDO $db): void
    {
        $this->transferAllowedOrThrow($db, 'Download');
        $settings = $this->settingsStore()->settings($db);
        $this->limitOrThrow(
            $db,
            'public-file-download',
            $settings['public_download_max_files'],
            $settings['public_download_window_seconds'],
            'Public download'
        );
    }

    public function packageLimit(PDO $db): void
    {
        $this->transferAllowedOrThrow($db, 'Package download');
        $settings = $this->settingsStore()->settings($db);
        $this->limitOrThrow(
            $db,
            'public-package-generation',
            $settings['public_package_max_builds'],
            $settings['public_package_window_seconds'],
            'Generated package'
        );
    }

    public function feedbackLimit(PDO $db): void
    {
        $settings = $this->settingsStore()->settings($db);
        $this->limitOrThrow(
            $db,
            'public-feedback',
            $settings['feedback_max_requests'],
            $settings['feedback_window_seconds'],
            'Feedback submission'
        );
    }

    public function downloadSpeedBytes(PDO $db): int
    {
        $settings = $this->settingsStore()->settings($db);
        return max(0, (int)$settings['public_download_speed_kbps']) * 1024;
    }

    private function guardableMethod(): bool
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        return in_array($method, ['GET', 'HEAD'], true);
    }

    /**
     * Read only enough state to preserve an already-active block before cache.
     * The sharded path is current; the legacy flat path is also checked so a
     * deployment cannot accidentally clear a live block during the layout change.
     */
    private function activeBurstBlockRetry(string $identity): int
    {
        $config = $this->resolvedConfig();
        $directory = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'public-burst';
        if (!is_dir($directory)) {
            return 0;
        }

        $hash = hash('sha256', $identity);
        $paths = [
            $directory . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . DIRECTORY_SEPARATOR . $hash . '.json',
            $directory . DIRECTORY_SEPARATOR . $hash . '.json',
        ];
        $retry = 0;
        $now = time();
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $handle = @fopen($path, 'rb');
            if (!is_resource($handle)) {
                continue;
            }
            try {
                if (!@flock($handle, LOCK_SH)) {
                    continue;
                }
                $raw = stream_get_contents($handle);
                $state = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
                if (is_array($state)) {
                    $blockedUntil = max(0, (int)($state['blocked_until'] ?? 0));
                    if ($blockedUntil > $now) {
                        $retry = max($retry, $blockedUntil - $now);
                    }
                }
            } finally {
                @flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
        return $retry;
    }

    /**
     * Rotate through one of 256 shards per interval. Cleanup is best-effort and
     * never blocks ordinary requests on another request's scan.
     */
    private function pruneBurstState(string $directory, int $retentionSeconds): void
    {
        $lockPath = $directory . DIRECTORY_SEPARATOR . '.prune.lock';
        clearstatcache(true, $lockPath);
        $now = time();
        $lastRun = is_file($lockPath) ? (int)@filemtime($lockPath) : 0;
        if ($lastRun > 0 && $now - $lastRun < self::BURST_PRUNE_INTERVAL_SECONDS) {
            return;
        }

        $lock = @fopen($lockPath, 'c+b');
        if (!is_resource($lock)) {
            return;
        }
        try {
            if (!@flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }
            clearstatcache(true, $lockPath);
            $lastRun = (int)(@filemtime($lockPath) ?: 0);
            $now = time();
            if ($lastRun > 0 && $now - $lastRun < self::BURST_PRUNE_INTERVAL_SECONDS) {
                return;
            }

            $cursorPath = $directory . DIRECTORY_SEPARATOR . '.prune.cursor';
            $rawCursor = @file_get_contents($cursorPath);
            $cursor = is_string($rawCursor) && ctype_digit(trim($rawCursor))
                ? ((int)trim($rawCursor) % self::BURST_SHARD_COUNT)
                : 0;
            $shard = sprintf('%02x', $cursor);
            $next = ($cursor + 1) % self::BURST_SHARD_COUNT;
            @file_put_contents($cursorPath, (string)$next, LOCK_EX);

            $shardDirectory = $directory . DIRECTORY_SEPARATOR . $shard;
            $cutoff = $now - max(60, $retentionSeconds);
            if (is_dir($shardDirectory)) {
                foreach (new \FilesystemIterator($shardDirectory, \FilesystemIterator::SKIP_DOTS) as $entry) {
                    if ($entry->isFile()
                        && str_ends_with($entry->getFilename(), '.json')
                        && $entry->getMTime() < $cutoff) {
                        @unlink($entry->getPathname());
                    }
                }
            }

            @ftruncate($lock, 0);
            @rewind($lock);
            @fwrite($lock, (string)$now);
            @fflush($lock);
            @touch($lockPath, $now);
        } catch (Throwable $error) {
            error_log('[UnrealDB public access] burst-state pruning failed: ' . $error->getMessage());
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /** @return array<string,mixed> */
    private function resolvedConfig(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }
        return function_exists('catalog_config') ? \catalog_config() : [];
    }

    private function settingsStore(): CatalogPublicAccessSettingsStore
    {
        return new CatalogPublicAccessSettingsStore($this->resolvedConfig());
    }
}
