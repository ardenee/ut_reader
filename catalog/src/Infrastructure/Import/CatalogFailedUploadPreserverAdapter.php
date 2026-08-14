<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use UnrealDb\Catalog\Application\Upload\Contract\FailedUploadPreserver;

final class CatalogFailedUploadPreserverAdapter implements FailedUploadPreserver
{
    private readonly CatalogFailedUploadRetention $retention;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $this->retention = new CatalogFailedUploadRetention($config);
    }

    public function preserve(
        string $temporaryPath,
        string $originalName,
        string $gameSlug,
        string $reason,
        ?int $uploadedBy = null
    ): void {
        $this->retention->preserve(
            $temporaryPath,
            $originalName,
            $gameSlug,
            $reason,
            $uploadedBy
        );
    }
}
