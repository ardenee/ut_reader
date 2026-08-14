<?php
/**
 * Compatibility composition adapter for the unverified-file action endpoint.
 *
 * Orchestration and result semantics live in Application; Infrastructure only
 * supplies the filesystem/database adapters required by that use case.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use UnrealDb\Catalog\Application\Unverified\CatalogUnverifiedActionService as ApplicationActionService;

final class CatalogUnverifiedActionService
{
    private readonly ApplicationActionService $service;

    /** @param array<string,mixed> $config */
    public function __construct(PDO $db, array $config)
    {
        $this->service = new ApplicationActionService(
            new CatalogUnverifiedQueueMutationService($db, $config),
            new CatalogUnverifiedImporterAdapter($db, $config)
        );
    }

    /**
     * @param array<string,mixed> $source
     * @param null|callable(string,int,string):void $emit
     * @return array<string,mixed>
     */
    public function execute(
        string $action,
        array $source,
        int $targetGameId,
        ?int $userId,
        bool $allowOverride,
        ?callable $emit = null
    ): array {
        return $this->service->execute(
            $action,
            $source,
            $targetGameId,
            $userId,
            $allowOverride,
            $emit
        );
    }
}
