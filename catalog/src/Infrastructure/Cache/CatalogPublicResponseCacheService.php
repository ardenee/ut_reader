<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns the anonymous file-backed HTML response cache lifecycle.
 * Why: Cache eligibility, key/storage policy, stale serving, writer locking and shutdown publication form one cache
 *      subsystem and should not live as a large procedural catalog/lib module.
 * Role: Infrastructure cache service preserving the existing single-host public-page cache contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Cache;

use FilesystemIterator;
use SplFileInfo;
use Throwable;

final class CatalogPublicResponseCacheService
{
    private const PRUNE_INTERVAL_SECONDS = 300;
    private const PRUNE_SCAN_LIMIT = 2000;
    private const DEFAULT_SEARCH_CACHE_SLOTS = 4096;

    /** @param array<string,mixed> $config */
    public static function routeTtl(array $config): int
    {
        $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $page = strtolower(trim((string)($_GET['page'] ?? '')));
        $defaults = [
            'games.php' => 120,
            'library.php' => 120,
            'game-page.php' => 120,
            'game-files.php' => 60,
            'file-info.php' => 300,
            'file-examine.php' => 300,
            'game-paks.php' => 120,
            'game-upks.php' => 120,
            'pak-info.php' => 300,
            'upk-info.php' => 300,
        ];

        $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
        $overrides = is_array($cache['public_route_ttl_seconds'] ?? null)
            ? $cache['public_route_ttl_seconds']
            : [];

        if ($script === 'index.php') {
            if ($page === '' || $page === 'home') {
                $configured = isset($overrides['index.php:home'])
                    ? (int)$overrides['index.php:home']
                    : 15;
                return max(0, min($configured, 3600));
            }
            if ($page === 'search') {
                $configured = isset($overrides['index.php:search'])
                    ? (int)$overrides['index.php:search']
                    : 60;
                return max(0, min($configured, 3600));
            }
            return 0;
        }

        if (!isset($defaults[$script])) {
            return 0;
        }

        $configured = isset($overrides[$script]) ? (int)$overrides[$script] : $defaults[$script];
        return max(0, min($configured, 3600));
    }

    /** @param array<string,mixed> $config */
    public static function enabled(array $config): bool
    {
        $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
        if (array_key_exists('public_response_enabled', $cache)) {
            return (bool)$cache['public_response_enabled'];
        }
        return true;
    }

    public static function anonymousRequest(): bool
    {
        if (PHP_SAPI === 'cli' || strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return false;
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['PHP_AUTH_USER'])) {
            return false;
        }
        if (isset($_GET['nocache'])) {
            return false;
        }

        $sessionName = session_name();
        if (($sessionName !== '' && isset($_COOKIE[$sessionName])) || isset($_COOKIE['UNREALDB_REMEMBER'])) {
            return false;
        }

        return session_status() !== PHP_SESSION_ACTIVE;
    }

    public static function queryString(): string
    {
        $query = $_GET;
        foreach (array_keys($query) as $key) {
            if (str_starts_with(strtolower((string)$key), 'utm_')) {
                unset($query[$key]);
            }
        }
        ksort($query, SORT_STRING);
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return strlen($encoded) <= 2048 ? $encoded : '';
    }

    /** @param array<string,mixed> $config */
    public static function directory(array $config): string
    {
        $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
        $base = trim((string)($cache['path'] ?? ''));
        if ($base === '') {
            $base = rtrim((string)($config['storage_path'] ?? dirname(__DIR__, 3) . '/storage'), '/\\')
                . DIRECTORY_SEPARATOR . 'cache';
        }
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'public-pages';
    }

    /** @param array<string,mixed> $config */
    public static function invalidate(array $config): int
    {
        $directory = self::directory($config);
        if (!is_dir($directory)
            && !@mkdir($directory, 0775, true)
            && !is_dir($directory)) {
            return 0;
        }

        // Invalidation must stay constant-time. Re-key the cache namespace with
        // one tiny generation file instead of walking/deleting every cached page.
        // Existing files immediately become unreachable and bounded pruning
        // removes them later without delaying an administrator settings save.
        $path = $directory . DIRECTORY_SEPARATOR . '.generation';
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            return 0;
        }
        try {
            if (!@flock($handle, LOCK_EX)) {
                return 0;
            }
            $token = bin2hex(random_bytes(16));
            rewind($handle);
            if (!@ftruncate($handle, 0)
                || @fwrite($handle, $token) !== strlen($token)
                || !@fflush($handle)) {
                return 0;
            }
            return 1;
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    public static function pruneDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        $lockPath = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.prune.lock';
        clearstatcache(true, $lockPath);
        $lastRun = is_file($lockPath) ? (int)@filemtime($lockPath) : 0;
        $now = time();
        if ($lastRun > 0 && $now - $lastRun < self::PRUNE_INTERVAL_SECONDS) {
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
            if ($lastRun > 0 && $now - $lastRun < self::PRUNE_INTERVAL_SECONDS) {
                return;
            }

            @ftruncate($lock, 0);
            @rewind($lock);
            @fwrite($lock, (string)$now);
            @fflush($lock);
            @touch($lockPath, $now);

            $cacheCutoff = $now - 7200;
            $lockCutoff = $now - 3600;
            $checked = 0;
            foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
                if (++$checked > self::PRUNE_SCAN_LIMIT) {
                    break;
                }
                if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
                    continue;
                }
                $name = $entry->getFilename();
                if ($name === '.prune.lock') {
                    continue;
                }
                $mtime = $entry->getMTime();
                if (($mtime < $cacheCutoff && str_ends_with($name, '.htmlcache'))
                    || ($mtime < $lockCutoff && str_ends_with($name, '.lock'))) {
                    @unlink($entry->getPathname());
                }
            }
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /** @return array{meta:array<string,mixed>,body:string}|null */
    public static function read(string $path): ?array
    {
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) {
            return null;
        }
        $header = fgets($stream, 8192);
        $body = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($header) || !is_string($body)) {
            return null;
        }
        $meta = self::decodeMeta($header);
        if ($meta === null) {
            return null;
        }
        return ['meta' => $meta, 'body' => $body];
    }

    /** @param array{meta:array<string,mixed>,body:string} $entry */
    public static function serve(array $entry, string $status, int $ttl, int $staleSeconds): never
    {
        self::sendCacheHeaders((array)$entry['meta'], $status, $ttl, $staleSeconds);
        echo (string)$entry['body'];
        exit;
    }

    /** @param array<string,mixed> $config */
    public static function bootstrap(array $config): void
    {
        if (!self::enabled($config) || !self::anonymousRequest()) {
            return;
        }

        $ttl = self::routeTtl($config);
        if ($ttl < 1) {
            return;
        }

        $directory = self::directory($config);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        if (!is_dir($directory) || !is_writable($directory)) {
            return;
        }

        self::pruneDirectory($directory);

        $script = strtolower(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $query = self::queryString();
        if ($query === '' && $_GET !== []) {
            return;
        }

        $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
        $generation = self::generationToken($directory);
        $identityHash = hash('sha256', $generation . "\n" . $script . "\n" . $query);
        $boundedSearch = self::isSearchRoute($script);
        if ($boundedSearch) {
            $slots = max(
                256,
                min((int)($cache['public_search_cache_slots'] ?? self::DEFAULT_SEARCH_CACHE_SLOTS), 65536)
            );
            $slot = hexdec(substr($identityHash, 0, 8)) % $slots;
            $key = 'search-' . str_pad(dechex($slot), 4, '0', STR_PAD_LEFT);
        } else {
            $key = $identityHash;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $key . '.htmlcache';
        $lockPath = $directory . DIRECTORY_SEPARATOR . $key . '.lock';
        $staleSeconds = max(0, min((int)($cache['public_response_stale_seconds'] ?? 300), 3600));
        $maxBytes = max(
            65536,
            min((int)($cache['public_response_max_bytes'] ?? 8 * 1024 * 1024), 64 * 1024 * 1024)
        );

        $meta = self::readMeta($path);
        if ($meta !== null && !self::matchesIdentity($meta, $identityHash, $boundedSearch)) {
            // Search slots intentionally collide. Identity metadata turns a slot
            // collision into a cache miss instead of serving another query's HTML.
            $meta = null;
        }
        if ($meta !== null) {
            $age = max(0, time() - (int)$meta['stored_at']);
            if ($age <= $ttl && self::servePath($path, $meta, 'HIT', $ttl, $staleSeconds)) {
                exit;
            }
        }

        $lock = @fopen($lockPath, 'c+b');
        $writer = is_resource($lock) && @flock($lock, LOCK_EX | LOCK_NB);
        if (!$writer && $meta !== null) {
            $age = max(0, time() - (int)$meta['stored_at']);
            if ($age <= $ttl + $staleSeconds) {
                if (is_resource($lock)) {
                    fclose($lock);
                    $lock = null;
                }
                if (self::servePath($path, $meta, 'STALE', $ttl, $staleSeconds)) {
                    exit;
                }
            }
        }

        $GLOBALS['catalog_public_cache_state'] = [
            'path' => $path,
            'lock' => $lock,
            'writer' => $writer,
            'ttl' => $ttl,
            'stale' => $staleSeconds,
            'max_bytes' => $maxBytes,
            'identity_hash' => $identityHash,
            'started_level' => ob_get_level(),
        ];
        ob_start();
        register_shutdown_function('catalog_public_cache_finish');
    }

    public static function finish(): void
    {
        $state = $GLOBALS['catalog_public_cache_state'] ?? null;
        if (!is_array($state)) {
            return;
        }

        $lock = $state['lock'] ?? null;
        try {
            if (empty($state['writer']) || ob_get_level() <= (int)$state['started_level']) {
                return;
            }

            $bodyLength = ob_get_length();
            if (!is_int($bodyLength) || $bodyLength < 1 || $bodyLength > (int)$state['max_bytes']) {
                return;
            }
            if ((int)http_response_code() !== 200
                || session_status() === PHP_SESSION_ACTIVE
                || !empty($GLOBALS['catalog_public_cache_abort'])) {
                return;
            }
            foreach (headers_list() as $header) {
                $lower = strtolower($header);
                if (str_starts_with($lower, 'set-cookie:')
                    || (str_starts_with($lower, 'cache-control:')
                        && (str_contains($lower, 'no-store') || str_contains($lower, 'private')))) {
                    return;
                }
            }

            $body = ob_get_contents();
            if (!is_string($body) || strlen($body) !== $bodyLength) {
                return;
            }
            $header = json_encode([
                'stored_at' => time(),
                'ttl' => (int)$state['ttl'],
                'bytes' => $bodyLength,
                'key_hash' => (string)($state['identity_hash'] ?? ''),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($header)) {
                return;
            }
            $temporary = (string)$state['path'] . '.' . bin2hex(random_bytes(6)) . '.tmp';
            $stream = @fopen($temporary, 'wb');
            if (!is_resource($stream)) {
                return;
            }
            $written = false;
            try {
                $headerBytes = $header . "\n";
                $written = @fwrite($stream, $headerBytes) === strlen($headerBytes)
                    && @fwrite($stream, $body) === $bodyLength
                    && @fflush($stream);
            } finally {
                @fclose($stream);
            }
            if (!$written || !@rename($temporary, (string)$state['path'])) {
                @unlink($temporary);
                return;
            }
            if (!headers_sent()) {
                header(
                    'Cache-Control: public, max-age=' . (int)$state['ttl']
                    . ', stale-while-revalidate=' . (int)$state['stale']
                );
                header('X-UnrealDB-Page-Cache: MISS');
            }
        } catch (Throwable) {
            // Caching is optional and must never affect the response.
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function generationToken(string $directory): string
    {
        $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.generation';
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return '0';
        }
        try {
            if (!@flock($handle, LOCK_SH)) {
                return '0';
            }
            $token = trim((string)stream_get_contents($handle));
            return preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? $token : '0';
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /** @return array<string,mixed>|null */
    private static function readMeta(string $path): ?array
    {
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) {
            return null;
        }
        try {
            $header = fgets($stream, 8192);
        } finally {
            fclose($stream);
        }
        return is_string($header) ? self::decodeMeta($header) : null;
    }

    /** @return array<string,mixed>|null */
    private static function decodeMeta(string $header): ?array
    {
        $meta = json_decode(trim($header), true);
        if (!is_array($meta) || (int)($meta['stored_at'] ?? 0) < 1) {
            return null;
        }
        return $meta;
    }

    /** @param array<string,mixed> $meta */
    private static function matchesIdentity(array $meta, string $identityHash, bool $required): bool
    {
        $stored = trim((string)($meta['key_hash'] ?? ''));
        if ($stored === '') {
            // Existing bounded-route cache files from before identity metadata are
            // safe because their filename is the complete identity hash. Search
            // slots require an explicit identity because their path is shared.
            return !$required;
        }
        return hash_equals($stored, $identityHash);
    }

    private static function isSearchRoute(string $script): bool
    {
        return basename($script) === 'index.php'
            && strtolower(trim((string)($_GET['page'] ?? ''))) === 'search';
    }

    /** @param array<string,mixed> $meta */
    private static function servePath(
        string $path,
        array $meta,
        string $status,
        int $ttl,
        int $staleSeconds
    ): bool {
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) {
            return false;
        }
        $header = fgets($stream, 8192);
        if (!is_string($header) || self::decodeMeta($header) === null) {
            fclose($stream);
            return false;
        }

        self::sendCacheHeaders($meta, $status, $ttl, $staleSeconds);
        fpassthru($stream);
        fclose($stream);
        return true;
    }

    /** @param array<string,mixed> $meta */
    private static function sendCacheHeaders(array $meta, string $status, int $ttl, int $staleSeconds): void
    {
        $storedAt = (int)$meta['stored_at'];
        $age = max(0, time() - $storedAt);
        $remaining = max(0, $ttl - $age);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: public, max-age=' . $remaining . ', stale-while-revalidate=' . $staleSeconds);
        header('Age: ' . $age);
        header('X-UnrealDB-Page-Cache: ' . $status);
        if ($status === 'STALE') {
            header('Warning: 110 - "Response is stale"');
        }
    }
}
