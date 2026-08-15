<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Import\Contract;

use UnrealDb\Catalog\Application\Import\CatalogVerifiedPackageInspection;

interface VerifiedPackageIdentityPort
{
    public function ensureSourcePathSchema(): void;

    public function ensureAliasSchema(): void;

    public function validateMaintenanceTarget(int $gameId, int $fileId): void;

    /** @return array<string,mixed>|null */
    public function findVerifiedDuplicate(
        int $gameId,
        CatalogVerifiedPackageInspection $inspection,
        int $maintenanceReplaceFileId = 0
    ): ?array;

    public function recordSourcePathIfMissing(int $fileId, string $sourceRelativePath): void;

    public function addAlias(
        int $fileId,
        int $gameId,
        CatalogVerifiedPackageInspection $inspection
    ): bool;
}
