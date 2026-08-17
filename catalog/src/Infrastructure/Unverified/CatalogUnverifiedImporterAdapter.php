<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use UnrealDb\Catalog\Application\Unverified\CatalogUnverifiedImporter;

/** Infrastructure adapter exposing unverified package and retained-PAK import workflows through the Application port. */
final class CatalogUnverifiedImporterAdapter implements CatalogUnverifiedImporter
{
    private readonly CatalogUnverifiedImportService $service;
    private readonly CatalogUnverifiedPakAssignmentService $pakAssignments;

    /** @param array<string,mixed> $config */
    public function __construct(PDO $db, array $config)
    {
        $this->service = new CatalogUnverifiedImportService($db, $config);
        $this->pakAssignments = new CatalogUnverifiedPakAssignmentService($db, $config);
    }

    public function import(
        array $source,
        int $targetGameId,
        ?int $userId,
        bool $allowProfileOverride,
        ?callable $emit = null
    ): array {
        if ($this->isPak($source)) {
            return $this->pakAssignments->assign($source, $targetGameId, $userId, $emit);
        }
        return $this->service->import($source, $targetGameId, $userId, $allowProfileOverride, $emit);
    }

    public function importExactCompatibleGames(
        array $source,
        ?int $userId,
        ?callable $emit = null
    ): array {
        if ($this->isPak($source)) {
            throw new \RuntimeException(
                'PAK containers require one explicit UE4/UE5 target game because assigning the PAK assigns all supported files inside it.'
            );
        }
        return $this->service->importExactCompatibleGames($source, $userId, $emit);
    }

    /** @param array<string,mixed> $source */
    private function isPak(array $source): bool
    {
        return strtolower(trim((string)($source['extension'] ?? ''))) === 'pak'
            || strtolower((string)pathinfo((string)($source['original_name'] ?? ''), PATHINFO_EXTENSION)) === 'pak';
    }
}
