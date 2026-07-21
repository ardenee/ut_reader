<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Infrastructure\Persistence\WorkerJobQueue;

final class CatalogJobWorkerFactory
{
    /** @param array<string,mixed> $config */
    public static function create(
        PDO $db,
        array $config,
        string $queueName,
        string $workerId,
        int $leaseSeconds
    ): JobWorker {
        return new JobWorker(
            new WorkerJobQueue($db),
            [
                new CatalogNonBlockingImportJobHandler(
                    new CatalogStagedImportJobHandler($db, $config),
                    $config
                ),
                new CatalogStorageMaintenanceJobHandler($db, $config),
                new UnverifiedDuplicateCleanupJobHandler($db, $config),
                new GeneratedPackageJobHandler($db, $config),
                new GameBackupExportJobHandler($db, $config),
                new GameBackupJobHandler($db, $config),
                // CatalogMaintenanceJobHandler currently recognises every registered
                // JobType before dispatching its own subset, so it must remain last.
                new CatalogMaintenanceJobHandler($db, $config),
            ],
            $queueName,
            $workerId,
            $leaseSeconds
        );
    }
}
