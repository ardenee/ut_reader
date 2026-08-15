<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Places one verified package in its canonical game storage location.
 * Why: Import orchestration should depend on a stable storage collaborator rather than duplicate filesystem policy.
 * Role: Compatibility collaborator for verified-package publication; local filesystem behavior lives in
 *       LocalFilesystemPackageStorage.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Storage;

use UnrealDb\Catalog\Application\Storage\Contract\PackageStoragePort;

final class CatalogVerifiedPackageStorage
{
    private readonly PackageStoragePort $storage;

    /** @param array<string,mixed> $config */
    public function __construct(array $config, ?PackageStoragePort $storage = null)
    {
        $this->storage = $storage ?? new LocalFilesystemPackageStorage(
            (string)$config['storage_path'],
            dirname(__DIR__, 3)
        );
    }

    /**
     * @return array{stored_name:string,destination:string,relative_path:string,created:bool,source_path:string}
     */
    public function store(
        string $temporaryPath,
        string $gameSlug,
        string $md5,
        string $extension,
        bool $discardDuplicateSource = true
    ): array {
        return $this->storage->storeVerified(
            $temporaryPath,
            $gameSlug,
            $md5,
            $extension,
            $discardDuplicateSource
        );
    }

    /** @param array{destination?:string,created?:bool,source_path?:string} $stored */
    public function rollbackCreated(array $stored): void
    {
        $this->storage->rollbackVerified($stored);
    }
}
