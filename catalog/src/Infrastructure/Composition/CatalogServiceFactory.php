<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Composition;

use PDO;
use UnrealDb\Catalog\Application\Upload\ProfiledUploadService;
use UnrealDb\Catalog\Infrastructure\Legacy\LegacyCatalogPackageImporter;
use UnrealDb\Catalog\Infrastructure\Logging\LegacyUploadFailureLogger;

/**
 * Composition root for application use cases.
 *
 * Controllers receive fully wired application services from here instead of
 * constructing PDO, parser, filesystem, and logging dependencies themselves.
 */
final class CatalogServiceFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function profiledUpload(): ProfiledUploadService
    {
        return new ProfiledUploadService(
            $this->db,
            $this->config,
            new LegacyCatalogPackageImporter(),
            new LegacyUploadFailureLogger($this->db)
        );
    }
}
