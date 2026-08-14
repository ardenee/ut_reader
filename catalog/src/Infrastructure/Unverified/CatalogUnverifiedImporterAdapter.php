<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use UnrealDb\Catalog\Application\Unverified\CatalogUnverifiedImporter;

/** Infrastructure adapter exposing the existing import implementation through the Application port. */
final class CatalogUnverifiedImporterAdapter implements CatalogUnverifiedImporter
{
    private readonly CatalogUnverifiedImportService $service;

    /** @param array<string,mixed> $config */
    public function __construct(PDO $db, array $config)
    {
        $this->service = new CatalogUnverifiedImportService($db, $config);
    }

    public function import(
        array $source,
        int $targetGameId,
        ?int $userId,
        bool $allowProfileOverride,
        ?callable $emit = null
    ): array {
        return $this->service->import($source, $targetGameId, $userId, $allowProfileOverride, $emit);
    }

    public function importExactCompatibleGames(
        array $source,
        ?int $userId,
        ?callable $emit = null
    ): array {
        return $this->service->importExactCompatibleGames($source, $userId, $emit);
    }
}
