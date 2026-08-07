<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Composes catalog application services with their infrastructure adapters.
 * Why: Controllers should receive fully wired use cases instead of constructing PDO, parser, filesystem and logging dependencies themselves.
 * Role: Infrastructure composition root for application use cases.
 * Audit: Keep concrete adapters here; Application classes must depend only on their ports.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Composition;

use PDO;
use UnrealDb\Catalog\Application\Unverified\UnverifiedDuplicateCleanupService;
use UnrealDb\Catalog\Application\Upload\ProfiledUploadService;
use UnrealDb\Catalog\Infrastructure\Filesystem\NativeUnverifiedFileSystem;
use UnrealDb\Catalog\Infrastructure\Import\PdoCatalogPackageImporter;
use UnrealDb\Catalog\Infrastructure\Logging\LegacyUploadFailureLogger;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoProfiledUploadGameCatalog;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUnverifiedRecordStore;
use UnrealDb\Catalog\Infrastructure\Unverified\LegacyUnverifiedQueueInventory;

final class CatalogServiceFactory
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function profiledUpload(): ProfiledUploadService
    {
        return new ProfiledUploadService(
            new PdoProfiledUploadGameCatalog($this->db),
            new PdoCatalogPackageImporter($this->db, $this->config),
            new LegacyUploadFailureLogger($this->db)
        );
    }

    public function unverifiedDuplicateCleanup(): UnverifiedDuplicateCleanupService
    {
        return new UnverifiedDuplicateCleanupService(
            new LegacyUnverifiedQueueInventory($this->db, $this->config),
            new PdoUnverifiedRecordStore($this->db),
            new NativeUnverifiedFileSystem()
        );
    }
}
