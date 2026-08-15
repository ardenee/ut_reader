<?php
/**
 * PDO convenience adapter for durable jobs that construct verified import from
 * a database connection and config array.
 *
 * All import behaviour lives in CatalogPackageImporterAdapter. This class keeps
 * the stable constructor used by resumable workers while delegating the complete
 * operation to the central Infrastructure composition root.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use UnrealDb\Catalog\Application\Upload\Contract\CatalogPackageImporter;
use UnrealDb\Catalog\Infrastructure\Composition\CatalogPackageImporterFactory;

final class PdoCatalogPackageImporter implements CatalogPackageImporter
{
    private readonly CatalogPackageImporterAdapter $delegate;

    /** @param array<string,mixed> $config */
    public function __construct(PDO $db, array $config)
    {
        $this->delegate = CatalogPackageImporterFactory::create($db, $config);
    }

    public function import(
        int $gameId,
        string $temporaryPath,
        string $originalName,
        ?int $userId,
        bool $strictProfile,
        ?callable $progress
    ): array {
        return $this->delegate->import(
            $gameId,
            $temporaryPath,
            $originalName,
            $userId,
            $strictProfile,
            $progress
        );
    }

    /**
     * Scanner-compatible verified import operation.
     *
     * @param array<string,mixed> $scannerOptions
     * @return array<int|string,mixed>
     */
    public function importUploadedFile(
        int $gameId,
        string $temporaryPath,
        string $originalName,
        ?int $userId,
        bool $strictProfile = true,
        ?callable $progress = null,
        bool $allowProfileOverride = false,
        array $scannerOptions = []
    ): array {
        return $this->delegate->importUploadedFile(
            $gameId,
            $temporaryPath,
            $originalName,
            $userId,
            $strictProfile,
            $progress,
            $allowProfileOverride,
            $scannerOptions
        );
    }

    public function preserveFailedUpload(
        string $temporaryPath,
        string $originalName,
        string $gameSlug,
        string $reason,
        ?int $uploadedBy = null
    ): void {
        $this->delegate->preserveFailedUpload(
            $temporaryPath,
            $originalName,
            $gameSlug,
            $reason,
            $uploadedBy
        );
    }
}
