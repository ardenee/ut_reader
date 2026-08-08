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
    private readonly CatalogPublicAccessSettingsStore $settings;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
        $this->settings = new CatalogPublicAccessSettingsStore($config);
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

        // Never trust the presence of a remember cookie by itself: any visitor can
        // create a cookie with that name. Restore and verify the administrator token
        // through the normal authentication path before granting an exemption.
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
        $directory = rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'public-burst';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create public burst-control storage.');
        }
        $path = $directory . DIRECTORY_SEPARATOR . hash('sha256', $identity) . '.json';
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
                $retry = $blockedUntil - $now;
            } else {
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
            }
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

    public function guardRequest(): void
    {
        if ($this->exempt()) {
            return;
        }
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }
        $settings = $this->settings->settings();
        $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($settings['public_block_crawlers'] && $this->knownCrawler($userAgent)) {
            $this->abort(
                403,
                'Automated access blocked',
                'Automated crawlers and bulk link scanners are not permitted on this development service.'
            );
        }
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

    public function rateLimit(PDO $db, string $scope, int $maximum, int $windowSeconds): int
    {
        if (function_exists('catalog_support_is_admin') && \catalog_support_is_admin()) {
            return 0;
        }
        $directory = rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR)
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

    public function downloadLimit(PDO $db): void
    {
        $settings = $this->settings->settings($db);
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
        $settings = $this->settings->settings($db);
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
        $settings = $this->settings->settings($db);
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
        $settings = $this->settings->settings($db);
        return max(0, (int)$settings['public_download_speed_kbps']) * 1024;
    }
}
