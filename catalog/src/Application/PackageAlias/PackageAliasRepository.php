<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\PackageAlias;

/** Persistence port for logical package names sharing one physical file. */
interface PackageAliasRepository
{
    public function exists(int $fileId, int $gameId, string $packageName): bool;

    public function add(
        int $fileId,
        int $gameId,
        string $packageName,
        string $originalName,
        string $packageGuid,
        string $md5,
        int $fileSize
    ): bool;
}
