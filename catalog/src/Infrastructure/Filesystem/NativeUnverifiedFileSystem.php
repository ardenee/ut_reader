<?php
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

    public function md5(string $path): ?string
    {
        $hash = @md5_file($path);
        return is_string($hash) ? strtolower($hash) : null;
    }

    public function delete(string $path): bool
    {
        return @unlink($path);
    }
}
