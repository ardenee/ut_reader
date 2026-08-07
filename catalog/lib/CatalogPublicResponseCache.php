<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog public response cache.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

/**
 * Small shared-response cache for explicitly approved anonymous GET pages.
 * It is deliberately file-backed because this deployment uses one Windows host.
 */

function catalog_public_cache_route_ttl(array $config): int
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

    if ($script === 'index.php') {
        if ($page === '' || $page === 'home') {
            // The development notice and public limits are administrator-editable.
            // Do not let a browser or shared page cache hide those updates.
            return 0;
        }
        if ($page === 'search') {
            return 60;
        }
        return 0;
    }

    if (!isset($defaults[$script])) {
        return 0;
    }

    $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
    $overrides = is_array($cache['public_route_ttl_seconds'] ?? null)
        ? $cache['public_route_ttl_seconds']
        : [];
    $configured = isset($overrides[$script]) ? (int)$overrides[$script] : $defaults[$script];
    return max(0, min($configured, 3600));
}

function catalog_public_cache_enabled(array $config): bool
{
    $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
    if (array_key_exists('public_response_enabled', $cache)) {
        return (bool)$cache['public_response_enabled'];
    }
    return true;
}

function catalog_public_cache_anonymous_request(): bool
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

function catalog_public_cache_query_string(): string
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

function catalog_public_cache_directory(array $config): string
{
    $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
    $base = trim((string)($cache['path'] ?? ''));
    if ($base === '') {
        $base = rtrim((string)($config['storage_path'] ?? dirname(__DIR__) . '/storage'), '/\\')
            . DIRECTORY_SEPARATOR . 'cache';
    }
    return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'public-pages';
}

function catalog_public_cache_invalidate(array $config): int
{
    $directory = catalog_public_cache_directory($config);
    if (!is_dir($directory)) {
        return 0;
    }

    $removed = 0;
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
            continue;
        }
        $name = $entry->getFilename();
        if (!str_ends_with($name, '.htmlcache')) {
            continue;
        }
        if (@unlink($entry->getPathname())) {
            $removed++;
        }
    }
    return $removed;
}

function catalog_public_cache_prune_directory(string $directory): void
{
    if (random_int(1, 100) !== 1) {
        return;
    }
    $cutoff = time() - 7200;
    $checked = 0;
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if (++$checked > 2000 || !$entry instanceof SplFileInfo || !$entry->isFile()) {
            continue;
        }
        $name = $entry->getFilename();
        if (($entry->getMTime() < $cutoff && str_ends_with($name, '.htmlcache'))
            || ($entry->getMTime() < time() - 3600 && str_ends_with($name, '.lock'))) {
            @unlink($entry->getPathname());
        }
    }
}

function catalog_public_cache_read(string $path): ?array
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
    $meta = json_decode(trim($header), true);
    if (!is_array($meta) || (int)($meta['stored_at'] ?? 0) < 1) {
        return null;
    }
    return ['meta' => $meta, 'body' => $body];
}

function catalog_public_cache_serve(array $entry, string $status, int $ttl, int $staleSeconds): never
{
    $storedAt = (int)$entry['meta']['stored_at'];
    $age = max(0, time() - $storedAt);
    $remaining = max(0, $ttl - $age);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: public, max-age=' . $remaining . ', stale-while-revalidate=' . $staleSeconds);
    header('Age: ' . $age);
    header('X-UnrealDB-Page-Cache: ' . $status);
    if ($status === 'STALE') {
        header('Warning: 110 - "Response is stale"');
    }
    echo (string)$entry['body'];
    exit;
}

function catalog_public_cache_bootstrap(array $config): void
{
    if (!catalog_public_cache_enabled($config) || !catalog_public_cache_anonymous_request()) {
        return;
    }

    $ttl = catalog_public_cache_route_ttl($config);
    if ($ttl < 1) {
        return;
    }

    $directory = catalog_public_cache_directory($config);
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
    if (!is_dir($directory) || !is_writable($directory)) {
        return;
    }

    catalog_public_cache_prune_directory($directory);

    $script = strtolower(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $query = catalog_public_cache_query_string();
    if ($query === '' && $_GET !== []) {
        return;
    }
    $key = hash('sha256', $script . "\n" . $query);
    $path = $directory . DIRECTORY_SEPARATOR . $key . '.htmlcache';
    $lockPath = $directory . DIRECTORY_SEPARATOR . $key . '.lock';
    $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
    $staleSeconds = max(0, min((int)($cache['public_response_stale_seconds'] ?? 300), 3600));
    $maxBytes = max(65536, min((int)($cache['public_response_max_bytes'] ?? 8 * 1024 * 1024), 64 * 1024 * 1024));

    $entry = catalog_public_cache_read($path);
    if ($entry !== null) {
        $age = max(0, time() - (int)$entry['meta']['stored_at']);
        if ($age <= $ttl) {
            catalog_public_cache_serve($entry, 'HIT', $ttl, $staleSeconds);
        }
    }

    $lock = @fopen($lockPath, 'c+b');
    $writer = is_resource($lock) && @flock($lock, LOCK_EX | LOCK_NB);
    if (!$writer && $entry !== null) {
        $age = max(0, time() - (int)$entry['meta']['stored_at']);
        if ($age <= $ttl + $staleSeconds) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            catalog_public_cache_serve($entry, 'STALE', $ttl, $staleSeconds);
        }
    }

    $GLOBALS['catalog_public_cache_state'] = [
        'path' => $path,
        'lock' => $lock,
        'writer' => $writer,
        'ttl' => $ttl,
        'stale' => $staleSeconds,
        'max_bytes' => $maxBytes,
        'started_level' => ob_get_level(),
    ];
    ob_start();
    register_shutdown_function('catalog_public_cache_finish');
}

function catalog_public_cache_finish(): void
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
        $body = ob_get_contents();
        if (!is_string($body) || $body === '' || strlen($body) > (int)$state['max_bytes']) {
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
                || (str_starts_with($lower, 'cache-control:') && (str_contains($lower, 'no-store') || str_contains($lower, 'private')))) {
                return;
            }
        }

        $payload = json_encode([
            'stored_at' => time(),
            'ttl' => (int)$state['ttl'],
            'bytes' => strlen($body),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . $body;
        $temporary = (string)$state['path'] . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload) || !@rename($temporary, (string)$state['path'])) {
            @unlink($temporary);
            return;
        }
        if (!headers_sent()) {
            header('Cache-Control: public, max-age=' . (int)$state['ttl'] . ', stale-while-revalidate=' . (int)$state['stale']);
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
