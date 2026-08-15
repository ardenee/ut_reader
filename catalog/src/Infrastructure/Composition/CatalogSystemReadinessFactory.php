<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Composition;

use PDO;
use UnrealDb\Catalog\Application\System\SystemReadinessService;
use UnrealDb\Catalog\Infrastructure\Health\FilesystemStorageReadinessProbe;
use UnrealDb\Catalog\Infrastructure\Health\PdoDatabaseReadinessProbe;
use UnrealDb\Catalog\Infrastructure\Health\PdoQueueReadinessProbe;

/** Composition root for the production-readiness dependency graph. */
final class CatalogSystemReadinessFactory
{
    /** @param array<string,mixed> $config */
    public static function create(PDO $db, array $config): SystemReadinessService
    {
        return new SystemReadinessService([
            new PdoDatabaseReadinessProbe($db),
            new PdoQueueReadinessProbe($db),
            new FilesystemStorageReadinessProbe((string)($config['storage_path'] ?? '')),
        ]);
    }
}
