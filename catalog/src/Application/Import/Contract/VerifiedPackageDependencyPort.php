<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Import\Contract;

interface VerifiedPackageDependencyPort
{
    public function refreshCanonical(
        int $fileId,
        int $gameId,
        string $packageName,
        ?int $userId,
        bool $defer,
        ?callable $progress
    ): string;

    public function refreshAlias(
        int $fileId,
        int $gameId,
        string $packageName,
        bool $defer,
        ?callable $progress
    ): void;
}
