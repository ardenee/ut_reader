<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `NativeUnverifiedFileSystem` for native unverified file system.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Filesystem;

use UnrealDb\Catalog\Application\Unverified\Contract\UnverifiedFileSystem;

/** Native filesystem adapter used by unverified duplicate cleanup. */
final class NativeUnverifiedFileSystem implements UnverifiedFileSystem
{
    public function exists(string $path): bool
    {
        return is_file($path);
    }

    public function size(string $path): int
    {
        return (int)(filesize($path) ?: 0);
    }

    public function md5(string $path, ?callable $progress = null): ?string
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return null;
        }

        $context = hash_init('md5');
        $total = max(0, (int)(filesize($path) ?: 0));
        $read = 0;
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if ($chunk === false) {
                    return null;
                }
                if ($chunk === '') {
                    break;
                }
                hash_update($context, $chunk);
                $read += strlen($chunk);
                if ($progress !== null) {
                    $progress($read, $total);
                }
            }
        } finally {
            fclose($handle);
        }

        return strtolower(hash_final($context));
    }

    public function delete(string $path): bool
    {
        return @unlink($path);
    }
}
