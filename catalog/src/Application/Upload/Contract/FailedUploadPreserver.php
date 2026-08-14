<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Upload\Contract;

interface FailedUploadPreserver
{
    public function preserve(
        string $temporaryPath,
        string $originalName,
        string $gameSlug,
        string $reason,
        ?int $uploadedBy = null
    ): void;
}
