<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Import\Contract;

use UnrealDb\Catalog\Application\Import\CatalogVerifiedPackageInspection;

interface VerifiedPackageInspectorPort
{
    public function inspect(
        int $gameId,
        string $temporaryPath,
        string $submittedOriginalName,
        bool $strictProfile,
        string $sourceRelativePath,
        ?callable $progress = null
    ): CatalogVerifiedPackageInspection;
}
