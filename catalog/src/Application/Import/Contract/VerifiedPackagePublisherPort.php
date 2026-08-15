<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Import\Contract;

use UnrealDb\Catalog\Application\Import\CatalogVerifiedPackageInspection;

interface VerifiedPackagePublisherPort
{
    public function persist(
        int $gameId,
        string $temporaryPath,
        CatalogVerifiedPackageInspection $inspection,
        ?int $userId,
        ?callable $progress,
        int $maintenanceReplaceFileId = 0
    ): int;

    /** @param array<int|string,mixed> $result @return array<int|string,mixed> */
    public function publishMetadata(
        array $result,
        CatalogVerifiedPackageInspection $inspection
    ): array;
}
