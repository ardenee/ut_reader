<?php
/**
 * Owns physical/database publication of a newly inspected verified package.
 *
 * Storage compensation and compact metadata publication are kept behind one
 * Infrastructure boundary so the import orchestrator does not coordinate file
 * rollback details itself.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Import\CatalogVerifiedPackageInspection;
use UnrealDb\Catalog\Infrastructure\Metadata\VerifiedFileCompactMetadataFinalizer;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogVerifiedPackagePersistence;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogVerifiedPackageStorage;

final class CatalogVerifiedPackagePublisher
{
    private readonly PdoCatalogVerifiedPackagePersistence $persistence;
    private readonly CatalogVerifiedPackageStorage $storage;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $this->persistence = new PdoCatalogVerifiedPackagePersistence($db, $config);
        $this->storage = new CatalogVerifiedPackageStorage($config);
    }

    public function persist(
        int $gameId,
        string $temporaryPath,
        CatalogVerifiedPackageInspection $inspection,
        ?int $userId,
        ?callable $progress,
        int $maintenanceReplaceFileId = 0
    ): int {
        \scanner_emit_percent($progress, 'database', 23, 'Storing file');
        $stored = $this->storage->store(
            $temporaryPath,
            (string)$inspection->game['slug'],
            $inspection->md5,
            $inspection->extension,
            $maintenanceReplaceFileId === 0
        );

        try {
            return $this->persistence->persist(
                $gameId,
                $inspection->packageName,
                $inspection->originalName,
                $inspection->sourceRelativePath,
                (string)$stored['stored_name'],
                (string)$stored['relative_path'],
                $inspection->extension,
                $inspection->classification,
                $inspection->fileSize,
                $inspection->md5,
                $inspection->sha1,
                $inspection->packageGuid,
                $inspection->header,
                $inspection->names,
                $inspection->imports,
                $inspection->exports,
                $inspection->scanNotes,
                $userId,
                $progress,
                $maintenanceReplaceFileId
            );
        } catch (Throwable $error) {
            $this->storage->rollbackCreated($stored);
            throw $error;
        }
    }

    /**
     * @param array<int|string,mixed> $result
     * @return array<int|string,mixed>
     */
    public function publishMetadata(
        array $result,
        CatalogVerifiedPackageInspection $inspection
    ): array {
        return VerifiedFileCompactMetadataFinalizer::finalizeParsed(
            $this->db,
            $this->config,
            $result,
            $inspection->names,
            $inspection->imports,
            $inspection->exports,
            null
        );
    }
}
