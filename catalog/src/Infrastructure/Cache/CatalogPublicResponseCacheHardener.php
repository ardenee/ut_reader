<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Bounds the public HTML response cache against scraper-driven key explosions.
 * Why: Arbitrary GET parameters previously created distinct cache identities and each identity left a lock file.
 * Role: Safety policy around CatalogPublicResponseCacheService without changing rendered page semantics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Cache;

use FilesystemIterator;
use SplFileInfo;

final class CatalogPublicResponseCacheHardener
{
    private const DEFAULT_MAX_ENTRIES = 500000;

    public static function requestCacheable(): bool
    {
        $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $keys = array_map(static fn($v): string => strtolower((string)$v), array_keys($_GET));

        if ($script === 'index.php') {
            $page = strtolower(trim((string)($_GET['page'] ?? '')));
            if ($page === '' || $page === 'home') {
                return self::keysAllowed($keys, ['page']);
            }
            // Search is already deliberately bounded to a fixed slot count by
            // CatalogPublicResponseCacheService.
            return $page === 'search';
        }

        $allowed = [
            'games.php' => [],
            'library.php' => ['q', 'game', 'game_id', 'type', 'sort', 'order', 'page'],
            'game-page.php' => ['id'],
            'game-files.php' => ['id', 'q', 'type', 'sort', 'order', 'page'],
            'file-info.php' => ['id', 'dep_status'],
            'file-examine.php' => ['id'],
            'game-paks.php' => ['id', 'page'],
            'game-upks.php' => ['id', 'page'],
            'pak-info.php' => ['id'],
            'upk-info.php' => ['id'],
        ];
        if (!array_key_exists($script, $allowed)) {
            return true;
        }
        return self::keysAllowed($keys, $allowed[$script]);
    }

    public static function markExistingState(): void
    {
        $state = $GLOBALS['catalog_public_cache_state'] ?? null;
        if (!is_array($state)) {
            return;
        }
        $path = (string)($state['path'] ?? '');
        $state['cache_existed_before'] = $path !== '' && is_file($path);
        $GLOBALS['catalog_public_cache_state'] = $state;
    }

    public static function finish(array $config): void
    {
        $state = $GLOBALS['catalog_public_cache_state'] ?? null;
        if (!is_array($state)) {
            return;
        }
        $path = (string)($state['path'] ?? '');
        if ($path === '') {
            return;
        }

        $lockPath = preg_replace('/\.htmlcache$/', '.lock', $path);
        if (is_string($lockPath) && $lockPath !== $path) {
            // On Windows this fails safely if another process still has the file open.
            @unlink($lockPath);
        }
        if (!is_file($path)) {
            return;
        }

        $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
        $maximum = max(1000, min(
            (int)($cache['public_response_max_entries'] ?? self::DEFAULT_MAX_ENTRIES),
            1000000
        ));
        $directory = CatalogPublicResponseCacheService::directory($config);
        $counterPath = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.entry-count';
        $handle = @fopen($counterPath, 'c+b');
        if (!is_resource($handle)) {
            return;
        }
        try {
            if (!@flock($handle, LOCK_EX)) {
                return;
            }
            rewind($handle);
            $raw = trim((string)stream_get_contents($handle));
            $reconciled = $raw === '';
            $count = $reconciled ? self::countEntries($directory) : max(0, (int)$raw);
            $wasNew = !isset($state['cache_existed_before']) || !$state['cache_existed_before'];
            if (!$wasNew) {
                self::writeCount($handle, $count);
                return;
            }

            if ($count >= $maximum) {
                // Normal expiry can make the cheap counter conservative. Pay for
                // a full reconciliation only when the ceiling is reached.
                $count = self::countEntries($directory);
                $reconciled = true;
            }
            if ($count >= $maximum) {
                @unlink($path);
                self::writeCount($handle, $count - 1); // scan included the just-published file
                return;
            }

            // A scan already includes the just-published file; the cheap counter does not.
            if (!$reconciled) {
                ++$count;
            }
            self::writeCount($handle, $count);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /** @param list<string> $keys @param list<string> $allowed */
    private static function keysAllowed(array $keys, array $allowed): bool
    {
        $allowed = array_fill_keys($allowed, true);
        foreach ($keys as $key) {
            if (str_starts_with($key, 'utm_')) {
                continue;
            }
            if (!isset($allowed[$key])) {
                return false;
            }
        }
        return true;
    }

    private static function countEntries(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }
        $count = 0;
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry instanceof SplFileInfo
                && $entry->isFile()
                && str_ends_with($entry->getFilename(), '.htmlcache')) {
                ++$count;
            }
        }
        return $count;
    }

    /** @param resource $handle */
    private static function writeCount($handle, int $count): void
    {
        rewind($handle);
        @ftruncate($handle, 0);
        @fwrite($handle, (string)max(0, $count));
        @fflush($handle);
    }
}
