<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Unverified\Contract;

/** Filesystem operations required by exact duplicate cleanup. */
interface UnverifiedFileSystem
{
    public function exists(string $path): bool;

    public function size(string $path): int;

    public function md5(string $path): ?string;

    public function delete(string $path): bool;
}
